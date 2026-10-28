<?php

namespace Tests\Feature;

use App\Enums\CheckKind;
use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Models\Block;
use App\Models\Check;
use App\Models\Student;
use App\Runner\Ansible\Playbook;
use App\Runner\CheckRunner;
use App\Runner\CodeValidator;
use App\Runner\Params;
use Database\Seeders\Block08DhcpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Block08DhcpSeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Block08DhcpSeeder::class);
    }

    public function test_block_8_has_its_tasks_and_checks(): void
    {
        $this->seed(Block08DhcpSeeder::class);
        $block = Block::where('numero', 8)->sole();

        $this->assertSame(4, $block->tasks()->count());
        $checks = $this->checks();
        $this->assertCount(19, $checks);
        $this->assertSame(29, $checks->where('tipus', CheckKind::Required)->sum('punts'));
        $this->assertSame(1, $checks->where('tipus', CheckKind::Optional)->sum('punts'));
        $this->assertTrue($checks->every(fn (Check $c) => filled($c->descripcio) && filled($c->info) && $c->versions()->count() === 1));
    }

    public function test_block_8_code_passes_the_save_rules(): void
    {
        $validator = app(CodeValidator::class);
        $module = Block::where('numero', 8)->sole()->module;

        foreach ($this->checks() as $check) {
            $version = $check->currentVersion;
            $validator->validate($module, $check->executor, $version->codi);
            Params::values($version->parametres);
            $this->assertSyntax($check->executor, $version->codi, $check->nom);
        }
    }

    public function test_router_checks_fail_when_heimdeye_cannot_reach_the_router(): void
    {
        // Als alumnes de prova, el router és a una adreça de documentació que no respon.
        $check = $this->checks()->firstWhere('nom', 'Leases estàtics al MikroTik');

        $result = app(CheckRunner::class)->run($check, Student::where('usuari', 'alumne01')->sole());

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertStringStartsWith('HeimdEye no pot entrar al router', $result->message);
    }

    /** @return Collection<int, Check> */
    private function checks()
    {
        return Check::whereIn('tasca_id', Block::where('numero', 8)->sole()->tasks()->pluck('id'))->get();
    }

    private function assertSyntax(Executor $executor, string $code, string $name): void
    {
        if ($executor === Executor::Ansible) {
            $this->assertNotEmpty(Playbook::parse($code)->hostGroups(), $name);

            return;
        }

        $process = new Process(['bash', '-n']);
        $process->setInput($code)->run();
        $this->assertTrue($process->isSuccessful(), "{$name}: {$process->getErrorOutput()}");

        // El Python que hi ha dins del heredoc també ha de compilar.
        if (preg_match("/<<'PY'\n(.*)\nPY\n/s", $code, $m)) {
            $python = new Process(['python3', '-c', 'import sys; compile(sys.stdin.read(), "check", "exec")']);
            $python->setInput($m[1])->run();
            $this->assertTrue($python->isSuccessful(), "{$name}: {$python->getErrorOutput()}");
        }
    }
}
