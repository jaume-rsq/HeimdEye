<?php

namespace Tests\Feature;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Models\Action;
use App\Models\Block;
use App\Models\Check;
use App\Models\Incident;
use App\Models\Module;
use App\Runner\CodeValidator;
use App\Runner\Params;
use Database\Seeders\Block10IncidentsSeeder;
use Database\Seeders\Block10WebSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Block10IncidentsSeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Block10WebSeeder::class);
        $this->seed(Block10IncidentsSeeder::class);
    }

    public function test_it_creates_twelve_incidents_with_check_and_repair(): void
    {
        $this->seed(Block10IncidentsSeeder::class); // es pot repetir

        $block = Block::where('numero', 10)->sole();
        $task = $block->tasks()->where('titol', Block10IncidentsSeeder::TASK)->sole();
        $incidents = Incident::where('tasca_id', $task->id)->orderBy('posicio')->get();

        $this->assertCount(12, $incidents);
        $this->assertSame(['Connectivitat' => 4, 'Certificats' => 3, 'Llocs web' => 5], $incidents->groupBy(fn ($i) => explode(':', explode(' · ', $i->nom)[1])[0])->map->count()->all());
        $this->assertTrue($incidents->every(fn (Incident $i) => $i->options->count() === 1 && filled($i->simptoma) && filled($i->notes)));
        foreach ($incidents as $incident) {
            $option = $incident->options->sole();
            $this->assertNotNull($option->check_id);
            $this->assertNotNull($option->accio_reparacio_id);
            $this->assertTrue($option->action->es_incidencia);
            $this->assertFalse($option->repairAction->es_incidencia);
        }
        $this->assertSame(12, $task->checks()->count());
        $this->assertTrue($task->checks()->get()->every(fn (Check $c) => $c->tipus === CheckKind::Optional && $c->punts === 0 && $c->es_actiu === false));
        $this->assertSame(4, Action::where('tasca_id', $task->id)->count());
        // El Block10WebSeeder no se'n va els tiquets que no li pertanyen.
        $this->assertSame(['2', '3'], $incidents->filter(fn ($i) => $i->equip === 'router')->map(fn ($i) => $i->options->sole()->parametres['AVARIA'])->values()->all());
    }

    public function test_all_code_passes_the_save_rules_and_bash_syntax(): void
    {
        $module = Module::where('codi', '9991')->sole();
        $validator = app(CodeValidator::class);
        $block = Block::where('numero', 10)->sole();
        $task = $block->tasks()->where('titol', Block10IncidentsSeeder::TASK)->sole();

        $codes = $task->checks()->get()->map(fn (Check $c) => [$c->nom, $c->executor, $c->currentVersion->codi, $c->currentVersion->parametres])
            ->merge(Action::where('tasca_id', $task->id)->get()->map(fn (Action $a) => [$a->nom, $a->executor, $a->currentVersion->codi, $a->currentVersion->parametres]));

        foreach ($codes as [$name, $executor, $code, $params]) {
            $validator->validate($module, $executor, $code);
            Params::values($params);
            $process = new Process(['bash', '-n']);
            $process->setInput($code)->run();
            $this->assertTrue($process->isSuccessful(), "{$name}: {$process->getErrorOutput()}");
            $this->assertSame($executor === Executor::Ssh, str_starts_with($code, "# hosts: vm_web\n"), $name);
        }
    }

    public function test_it_needs_the_block(): void
    {
        Block::where('numero', 10)->delete();

        $this->expectExceptionMessage('Block10WebSeeder');
        $this->seed(Block10IncidentsSeeder::class);
    }
}
