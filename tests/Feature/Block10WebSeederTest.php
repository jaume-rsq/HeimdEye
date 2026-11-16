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
use Database\Seeders\Block10WebSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Block10WebSeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Block10WebSeeder::class);
    }

    public function test_block_10_has_its_tasks_and_numbered_checks(): void
    {
        // Es pot tornar a executar: esborra i recrea el bloc sense duplicar res.
        $this->seed(Block10WebSeeder::class);
        $block = Block::where('numero', 10)->sole();

        $this->assertSame(17, $block->tasks()->count());
        $checks = $this->checks();
        $this->assertCount(93, $checks);
        $this->assertSame(2, $checks->where('tipus', CheckKind::Optional)->count());
        $this->assertTrue($checks->every(fn (Check $c) => filled($c->descripcio) && filled($c->info) && $c->versions()->count() === 1));

        // Cada check porta el número <bloc>.<pas>.<n> del seu pas.
        foreach ($block->tasks as $task) {
            preg_match('/^Pas (\d+) /', $task->titol, $m);
            foreach ($task->checks as $check) {
                $this->assertMatchesRegularExpression('/^10\.'.$m[1].'\.\d+ /', $check->nom, $check->nom);
            }
        }
    }

    public function test_the_web_host_type_and_variable_exist(): void
    {
        $module = Module::where('codi', '9991')->sole();

        $this->assertSame('vm_web', $module->hostTypes()->where('nom', 'web')->sole()->grup_ansible);
        $this->assertSame('10.{{x}}.0.21', $module->variables()->where('clau', 'ip_web')->sole()->valor);
    }

    public function test_the_code_passes_the_save_rules(): void
    {
        $validator = app(CodeValidator::class);
        $module = Block::where('numero', 10)->sole()->module;

        foreach ($this->checks() as $check) {
            $version = $check->currentVersion;
            $validator->validate($module, $check->executor, $version->codi);
            Params::values($version->parametres);
            $this->assertSyntax($version->codi, $check->nom);
        }
    }

    public function test_aws_checks_use_the_agent_with_the_aws_role(): void
    {
        $aws = $this->checks()->filter(fn (Check $c) => $c->executor === Executor::Agent);

        $this->assertGreaterThan(0, $aws->count());
        foreach ($aws as $check) {
            $this->assertStringContainsString('# hosts: aws', $check->currentVersion->codi, $check->nom);
        }
    }

    public function test_local_check_fails_with_a_message_when_the_router_does_not_answer(): void
    {
        // Als alumnes de prova, el router és a una adreça de documentació que no respon.
        $check = $this->checks()->firstWhere('nom', '10.5.1 www respon des de l\'aula pel DNAT');

        $result = app(CheckRunner::class)->run($check, Student::where('usuari', 'alumne01')->sole());

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertStringContainsString('www.', $result->message);
    }

    /** @return Collection<int, Check> */
    private function checks()
    {
        return Check::whereIn('tasca_id', Block::where('numero', 10)->sole()->tasks()->pluck('id'))->get();
    }

    private function assertSyntax(string $code, string $name): void
    {
        $process = new Process(['bash', '-n']);
        $process->setInput($code)->run();
        $this->assertTrue($process->isSuccessful(), "{$name}: {$process->getErrorOutput()}");
    }
}
