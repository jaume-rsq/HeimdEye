<?php

namespace Tests\Feature;

use App\Enums\CheckKind;
use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Models\Block;
use App\Models\Check;
use App\Models\Module;
use App\Models\Student;
use App\Runner\CheckRunner;
use App\Runner\CodeValidator;
use App\Runner\Params;
use Carbon\Carbon;
use Database\Seeders\Block12CorreuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Block12CorreuSeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Block12CorreuSeeder::class);
    }

    public function test_block_12_has_its_tasks_and_numbered_checks(): void
    {
        // Es pot tornar a executar sense duplicar res.
        $this->seed(Block12CorreuSeeder::class);
        $block = Block::where('numero', 12)->sole();

        $this->assertSame('Correu electrònic', $block->titol);
        $this->assertSame(14, $block->tasks()->count());
        $checks = $this->checks();
        $this->assertCount(24, $checks);
        $this->assertSame(23, $checks->where('tipus', CheckKind::Required)->sum('punts'));
        $this->assertSame(1, $checks->where('tipus', CheckKind::Optional)->sum('punts'));
        $this->assertTrue($checks->every(fn (Check $c) => filled($c->descripcio) && filled($c->info) && $c->versions()->count() === 1));

        // Cada check porta el número <bloc>.<pas>.<n> del seu pas.
        foreach ($block->tasks as $task) {
            preg_match('/^Pas (\d+) /', $task->titol, $m);
            foreach ($task->checks as $check) {
                $this->assertMatchesRegularExpression('/^12\.'.$m[1].'\.\d+ /', $check->nom, $check->nom);
            }
        }
    }

    public function test_the_mail_host_type_and_variable_exist(): void
    {
        $module = Module::where('codi', '9991')->sole();

        $this->assertSame('vm_mail', $module->hostTypes()->where('nom', 'mail')->sole()->grup_ansible);
        $this->assertSame('10.{{x}}.0.25', $module->variables()->where('clau', 'ip_mail')->sole()->valor);
    }

    public function test_checks_run_every_night_from_monday_to_the_friday_at_2am_and_no_later(): void
    {
        foreach ($this->checks() as $check) {
            $this->assertFalse($check->isActiveOn(Carbon::parse('2026-11-29')), $check->nom);
            $this->assertTrue($check->isActiveOn(Carbon::parse('2026-11-30')), $check->nom);
            // La nit de dijous a divendres (02:00 del 11/12) és l'última ronda.
            $this->assertTrue($check->isActiveOn(Carbon::parse('2026-12-11')), $check->nom);
            $this->assertFalse($check->isActiveOn(Carbon::parse('2026-12-12')), $check->nom);
        }
    }

    public function test_the_code_passes_the_save_rules(): void
    {
        $validator = app(CodeValidator::class);
        $module = Block::where('numero', 12)->sole()->module;

        foreach ($this->checks() as $check) {
            $version = $check->currentVersion;
            $validator->validate($module, $check->executor, $version->codi);
            Params::values($version->parametres);
            $this->assertSyntax($version->codi, $check->nom);
        }
    }

    public function test_ssh_checks_target_the_mail_host_group_and_never_change_anything(): void
    {
        $ssh = $this->checks()->filter(fn (Check $c) => $c->executor === Executor::Ssh);

        $this->assertGreaterThan(0, $ssh->count());
        foreach ($ssh as $check) {
            $this->assertStringContainsString('# hosts: vm_mail', $check->currentVersion->codi, $check->nom);
        }

        // Cap check no escriu res ni envia més que sondes: res de systemctl restart, postconf -e, rm, etc.
        foreach ($this->checks() as $check) {
            $this->assertDoesNotMatchRegularExpression('/postconf\s+-[a-z]*e\b|systemctl\s+(restart|start|stop|reload)|\brm\s|postsuper|useradd|tee\s/', $check->currentVersion->codi, $check->nom);
        }
    }

    public function test_no_secret_or_password_is_written_in_the_checks(): void
    {
        foreach ($this->checks() as $check) {
            $this->assertStringNotContainsStringIgnoringCase('--password', $check->currentVersion->codi, $check->nom);
            // El fitxer de la contrasenya del relay només es comprova (grep -q, stat), mai es mostra.
            $this->assertDoesNotMatchRegularExpression('/\b(cat|head|tail|less|sed|awk)\b[^\n|]*sasl_passwd/', $check->currentVersion->codi, $check->nom);
        }
    }

    public function test_local_check_fails_with_a_message_when_the_dns_does_not_answer(): void
    {
        // Als alumnes de prova, el DNS de l'aula i el router són d'una xarxa que no respon.
        $check = $this->checks()->firstWhere('nom', '12.3.2 El port 25 respon a l\'adreça d\'aula del router');

        $result = app(CheckRunner::class)->run($check, Student::where('usuari', 'alumne01')->sole());

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertStringContainsString('mx.', $result->message);
    }

    /** @return Collection<int, Check> */
    private function checks()
    {
        return Check::whereIn('tasca_id', Block::where('numero', 12)->sole()->tasks()->pluck('id'))->get();
    }

    private function assertSyntax(string $code, string $name): void
    {
        $process = new Process(['bash', '-n']);
        $process->setInput($code)->run();
        $this->assertTrue($process->isSuccessful(), "{$name}: {$process->getErrorOutput()}");

        // El Python d'un heredoc <<'PY' també ha de compilar.
        if (preg_match("/<<'PY'\n(.*)\nPY\n/s", $code, $m)) {
            $python = new Process(['python3', '-c', 'import sys; compile(sys.stdin.read(), "check", "exec")']);
            $python->setInput($m[1])->run();
            $this->assertTrue($python->isSuccessful(), "{$name}: {$python->getErrorOutput()}");
        }
    }
}
