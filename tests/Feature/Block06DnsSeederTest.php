<?php

namespace Tests\Feature;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Models\Action;
use App\Models\Block;
use App\Models\Check;
use App\Runner\Ansible\Playbook;
use App\Runner\CodeValidator;
use App\Runner\Params;
use Database\Seeders\Block06DnsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Block06DnsSeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Block06DnsSeeder::class);
    }

    public function test_seeding_twice_changes_nothing(): void
    {
        $this->seed(Block06DnsSeeder::class);

        $this->assertSame(19, Block::where('numero', 6)->sole()->tasks()->withCount('checks')->get()->sum('checks_count'));
        $this->assertTrue(Check::all()->every(fn (Check $c) => $c->versions()->count() === 1));
    }

    public function test_block_6_has_its_tasks_and_checks(): void
    {
        $block = Block::where('numero', 6)->firstOrFail();

        $this->assertSame(6, $block->tasks()->count());
        $checks = Check::whereIn('tasca_id', $block->tasks()->pluck('id'))->get();
        $this->assertCount(19, $checks);
        $this->assertSame(24, $checks->where('tipus', CheckKind::Required)->sum('punts'));
        $this->assertSame(3, $checks->where('tipus', CheckKind::Optional)->sum('punts'));
        $this->assertTrue($checks->every(fn (Check $c) => filled($c->descripcio)));
    }

    public function test_block_6_code_passes_the_save_rules(): void
    {
        $validator = app(CodeValidator::class);
        $block = Block::where('numero', 6)->firstOrFail();
        $module = $block->module;

        $checks = Check::whereIn('tasca_id', $block->tasks()->pluck('id'))->get();
        foreach ($checks as $check) {
            $version = $check->currentVersion;
            $validator->validate($module, $check->executor, $version->codi);
            // Un check s'executa sol: tots els paràmetres necessiten valor per defecte.
            Params::values($version->parametres);
            foreach ($version->parametres ?? [] as $param) {
                $this->assertMatchesRegularExpression(Params::NAME_RULE, $param['name']);
            }
            $this->assertSyntax($check->executor, $version->codi, $check->nom);
        }

        $action = Action::where('bloc_id', $block->id)->sole();
        $validator->validate($module, $action->executor, $action->currentVersion->codi);
        $this->assertSyntax($action->executor, $action->currentVersion->codi, $action->nom);
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
