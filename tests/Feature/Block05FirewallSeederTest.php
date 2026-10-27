<?php

namespace Tests\Feature;

use App\Enums\CheckKind;
use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Models\Action;
use App\Models\Block;
use App\Models\Check;
use App\Models\Student;
use App\Runner\Ansible\Playbook;
use App\Runner\CheckRunner;
use App\Runner\CodeValidator;
use App\Runner\Params;
use Database\Seeders\Block05FirewallSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Block05FirewallSeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Block05FirewallSeeder::class);
    }

    public function test_block_5_has_its_tasks_and_checks(): void
    {
        $this->seed(Block05FirewallSeeder::class);
        $block = Block::where('numero', 5)->sole();

        $this->assertSame(4, $block->tasks()->count());
        $checks = $this->checks();
        $this->assertCount(12, $checks);
        $this->assertSame(8, $checks->where('tipus', CheckKind::Required)->sum('punts'));
        $this->assertSame(11, $checks->where('tipus', CheckKind::Optional)->sum('punts'));
        $this->assertTrue($checks->every(fn (Check $c) => filled($c->descripcio) && filled($c->info) && $c->versions()->count() === 1));
    }

    public function test_block_5_code_passes_the_save_rules(): void
    {
        $validator = app(CodeValidator::class);
        $module = Block::where('numero', 5)->sole()->module;

        foreach ($this->checks() as $check) {
            $version = $check->currentVersion;
            $validator->validate($module, $check->executor, $version->codi);
            Params::values($version->parametres);
            $this->assertSyntax($check->executor, $version->codi, $check->nom);
        }

        $action = Action::where('nom', 'like', 'Accés de jaume a una VM Linux%')->sole();
        $validator->validate($module, $action->executor, $action->currentVersion->codi);
        Params::values($action->currentVersion->parametres);
        $this->assertSyntax($action->executor, $action->currentVersion->codi, $action->nom);
    }

    public function test_system_specific_checks_are_skipped_when_their_router_does_not_answer(): void
    {
        // Als alumnes de prova, el router és a una adreça de documentació que no respon.
        $check = $this->checks()->firstWhere('nom', 'Les regles es recuperen en arrencar');

        $result = app(CheckRunner::class)->run($check, Student::where('usuari', 'alumne01')->sole());

        $this->assertSame(CheckStatus::Skipped, $result->status);
        $this->assertSame('ara no respon el router Linux', $result->message);
    }

    /** @return Collection<int, Check> */
    private function checks()
    {
        return Check::whereIn('tasca_id', Block::where('numero', 5)->sole()->tasks()->pluck('id'))->get();
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
    }
}
