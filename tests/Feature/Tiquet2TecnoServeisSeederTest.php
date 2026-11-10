<?php

namespace Tests\Feature;

use App\Enums\CheckKind;
use App\Enums\IncidentStatus;
use App\Models\Action;
use App\Models\ActionRun;
use App\Models\Block;
use App\Models\Check;
use App\Models\Incident;
use App\Models\IncidentStudent;
use App\Models\Module;
use App\Models\Task;
use App\Runner\CodeValidator;
use App\Runner\Incidents\IncidentLauncher;
use App\Runner\Params;
use Database\Seeders\Tiquet2TecnoServeisSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Tiquet2TecnoServeisSeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    private Block $block;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        // El professor crea el mòdul 0378, el bloc 3 i les tasques.
        $base = Module::where('codi', '9991')->sole();
        $this->module = $base->replicate();
        $this->module->codi = '0378';
        $this->module->save();
        $this->module->students()->attach($base->students()->pluck('alumnes.id'));
        $this->block = $this->module->blocks()->create(['numero' => 3, 'titol' => 'Bloc 3', 'posicio' => 3]);
        $this->block->tasks()->create(['titol' => 'Tiquet 1', 'enunciat' => 'T1', 'posicio' => 1]);
        $this->task = $this->block->tasks()->create(['titol' => 'Tiquet 2', 'enunciat' => 'El del professor', 'posicio' => 2]);
    }

    public function test_it_cleans_the_block_and_creates_checks_actions_and_the_incident(): void
    {
        // Ja hi ha coses al bloc. Se'n van: un check i una incidència del T2, la incidència d'abans
        // (sense tasca) i una acció del seeder. Es queden: el del T1 i el que no és del seeder (T3).
        $tiquet1 = $this->block->tasks()->where('titol', 'Tiquet 1')->sole();
        $t1 = $tiquet1->checks()->create(['nom' => 'Del T1', 'executor' => 'agent']);
        $this->task->checks()->create(['nom' => 'Vell', 'executor' => 'agent']);
        $old = $this->module->actions()->create(['nom' => 'TecnoServeis: injectar avaria', 'bloc_id' => $this->block->id, 'executor' => 'agent']);
        $t3action = $this->module->actions()->create(['nom' => 'T3: una altra', 'bloc_id' => $this->block->id, 'executor' => 'agent']);
        $base = ['modul_id' => $this->module->id, 'bloc_id' => $this->block->id, 'equip' => 'aws', 'simptoma' => 'x'];
        Incident::create([...$base, 'nom' => 'Vella', 'tasca_id' => $this->task->id]);
        Incident::create([...$base, 'nom' => Tiquet2TecnoServeisSeeder::INCIDENT]);
        $t1incident = Incident::create([...$base, 'nom' => 'Del T1', 'tasca_id' => $tiquet1->id]);
        $other = Module::where('codi', '9991')->sole()->actions()->create(['nom' => 'D\'un altre mòdul', 'executor' => 'agent']);

        $this->seed(Tiquet2TecnoServeisSeeder::class);
        $this->seed(Tiquet2TecnoServeisSeeder::class); // es pot tornar a executar: torna a començar

        $this->assertModelExists($t1);
        $this->assertModelExists($other);
        $this->assertModelMissing($old);
        $this->assertModelExists($t3action);
        $this->assertModelExists($t1incident);
        $this->assertSame('El del professor', $this->task->fresh()->enunciat);
        $this->assertSame(2, $this->block->tasks()->count());

        $checks = $this->task->checks()->get();
        $this->assertCount(21, $checks);
        $required = $checks->where('tipus', CheckKind::Required);
        $this->assertCount(14, $required);
        $this->assertSame(14, $required->sum('punts'));
        $this->assertTrue($required->every(fn (Check $c) => filled($c->info) && $c->isActiveOn()));
        $this->assertTrue($checks->where('tipus', CheckKind::Optional)->every(fn (Check $c) => $c->punts === 0 && $c->es_actiu === false));
        $this->assertTrue($checks->every(fn (Check $c) => filled($c->descripcio) && $c->versions()->count() === 1));

        $actions = Action::where('bloc_id', $this->block->id)->whereKeyNot($t3action->id)->get();
        $this->assertCount(8, $actions);
        $this->assertSame(['TecnoServeis: injectar avaria'], $actions->where('es_incidencia', true)->pluck('nom')->all());
        $all = $actions->firstWhere('nom', 'T2: muntar tot el tiquet 2');
        $this->assertCount(5, $all->currentVersion->checks_verificacio);
        $this->assertTrue(Check::whereIn('id', $all->currentVersion->checks_verificacio)->get()->every(fn (Check $c) => $c->tasca_id === $this->task->id));

        $incident = Incident::where('tasca_id', $this->task->id)->sole();
        $this->assertSame(2, Incident::where('bloc_id', $this->block->id)->count());
        $this->assertSame('La web de TecnoServeis no respon', $incident->nom);
        $this->assertCount(7, $incident->options);
        $this->assertTrue($incident->options->every(fn ($o) => $o->check_id && $o->accio_reparacio_id));
    }

    public function test_all_code_passes_the_save_rules_and_bash_syntax(): void
    {
        $this->seed(Tiquet2TecnoServeisSeeder::class);
        $validator = app(CodeValidator::class);

        $codes = $this->task->checks()->get()->map(fn (Check $c) => [$c->nom, $c->executor, $c->currentVersion->codi, $c->currentVersion->parametres])
            ->merge(Action::where('bloc_id', $this->block->id)->get()->map(fn (Action $a) => [$a->nom, $a->executor, $a->currentVersion->codi, $a->currentVersion->parametres]));

        foreach ($codes as [$name, $executor, $code, $params]) {
            $validator->validate($this->module, $executor, $code);
            Params::values($params);
            $this->assertStringStartsWith("# hosts: aws\n", $code, $name);

            $process = new Process(['bash', '-n']);
            $process->setInput($code)->run();
            $this->assertTrue($process->isSuccessful(), "{$name}: {$process->getErrorOutput()}");
        }
    }

    public function test_the_incident_launches_on_the_aws_agent_role(): void
    {
        $this->seed(Tiquet2TecnoServeisSeeder::class);
        $incident = Incident::where('bloc_id', $this->block->id)->sole();
        $student = $this->module->students()->orderBy('num_taula')->first();

        app(IncidentLauncher::class)->launch($incident, [$student->id => $incident->options->take(3)->modelKeys()], 'aleatori', 3, null, null);

        $this->assertSame(3, IncidentStudent::where('estat', IncidentStatus::Pendent)->count());
        $this->assertSame(['aws'], ActionRun::pluck('rol_agent')->unique()->values()->all());
    }

    public function test_it_needs_the_module_and_the_block(): void
    {
        $this->block->delete();
        $this->expectExceptionMessage('El mòdul 0378 no té el bloc 3');
        $this->seed(Tiquet2TecnoServeisSeeder::class);
    }
}
