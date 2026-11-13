<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Enums\CheckKind;
use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Filament\Resources\Incidents\Pages\EditIncident;
use App\Filament\Resources\Incidents\Pages\ListIncidents;
use App\Filament\Resources\Incidents\Pages\ManageIncidentAssignments;
use App\Filament\Student\Pages\MyProgress;
use App\Models\Action;
use App\Models\ActionRun;
use App\Models\AgentInstance;
use App\Models\AgentTask;
use App\Models\Check;
use App\Models\Incident;
use App\Models\IncidentLaunch;
use App\Models\IncidentStudent;
use App\Models\Module;
use App\Models\RunBatch;
use App\Models\Student;
use App\Models\User;
use App\Runner\CheckResult;
use App\Runner\Incidents\IncidentLauncher;
use App\Runner\ResultRecorder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

/** Incidències: sorteig, injecció amb l'acció, seguiment amb el check i pantalles. */
class IncidentsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = Module::where('codi', '9991')->sole();
    }

    private function agentAction(string $name, bool $fault = true): Action
    {
        $action = $this->module->actions()->create(['nom' => $name, 'executor' => Executor::Agent, 'es_incidencia' => $fault]);
        $action->versions()->create([
            'versio' => 1,
            'codi' => "# hosts: web\necho \"avaria \$HE_PARAM_N\"\n",
            'parametres' => [['name' => 'N', 'label' => 'Avaria', 'default' => '0']],
            'temps_max_seg' => 30,
        ]);

        return $action->fresh();
    }

    private function agentCheck(string $name): Check
    {
        $block = $this->module->blocks()->firstOrCreate(['numero' => 90], ['titol' => 'Incidències', 'posicio' => 90]);
        $task = $block->tasks()->firstOrCreate(['titol' => 'Resolució'], ['posicio' => 1]);
        $check = $task->checks()->create([
            'nom' => $name, 'executor' => Executor::Agent, 'tipus' => CheckKind::Optional,
            'punts' => 0, 'bonus_puntualitat_pct' => 0, 'es_actiu' => false, 'posicio' => 1,
        ]);
        $check->versions()->create(['versio' => 1, 'codi' => "# hosts: web\ntrue\n", 'temps_max_seg' => 30]);

        return $check->fresh();
    }

    /** Incidència amb $n opcions: totes amb la mateixa acció (paràmetre N) i un check propi. */
    private function incident(int $n = 3): Incident
    {
        $inject = $this->agentAction('Injectar');
        $repair = $this->agentAction('Desfer', fault: false);
        $incident = Incident::create(['modul_id' => $this->module->id, 'nom' => 'Web caiguda', 'equip' => 'web', 'simptoma' => 'La web no carrega']);
        for ($i = 0; $i < $n; $i++) {
            $incident->options()->create([
                'nom' => "Avaria {$i}",
                'accio_id' => $inject->id,
                'parametres' => ['N' => (string) $i],
                'check_id' => $this->agentCheck("Check {$i}")->id,
                'accio_reparacio_id' => $repair->id,
                'posicio' => $i,
            ]);
        }

        return $incident->fresh();
    }

    private function students(int $n = 2)
    {
        return $this->module->students()->orderBy('num_taula')->limit($n)->get();
    }

    /** Simula que l'agent torna el resultat de totes les execucions pendents de l'acció. */
    private function finishActions(ActionStatus $status = ActionStatus::Ok): void
    {
        ActionRun::where('estat', ActionStatus::Pendent)->get()
            ->each(fn (ActionRun $run) => $run->update(['estat' => $status, 'recollit_a' => now(), 'data_fi' => now()]));
    }

    private function recordCheck(Check $check, Student $student, CheckStatus $status): void
    {
        $batch = RunBatch::create(['modul_id' => $this->module->id, 'origen' => 'manual', 'data_inici' => now()]);
        $this->travel(1)->seconds();
        app(ResultRecorder::class)->record($batch, $check, $student, new CheckResult($status, 'prova'));
    }

    public function test_random_proposal_gives_distinct_options_and_avoids_repeats(): void
    {
        $incident = $this->incident(3);
        $student = $this->students(1)->first();
        $launcher = app(IncidentLauncher::class);
        $ids = $incident->options->modelKeys();

        $plan = $launcher->propose($incident, collect([$student]), 'aleatori', 2, random: fn (int $max) => 0)['plan'];
        $this->assertSame([$ids[0], $ids[1]], $plan[$student->id]);

        // Ja ha tingut les dues primeres (tancades): ara li toca la tercera.
        $launch = $incident->launches()->create(['mode' => 'aleatori', 'quantitat' => 2]);
        foreach ([0, 1] as $i) {
            IncidentStudent::create(['incidencia_id' => $incident->id, 'llancament_id' => $launch->id, 'opcio_id' => $ids[$i], 'nom_opcio' => 'x', 'alumne_id' => $student->id, 'estat' => IncidentStatus::Tancada]);
        }
        $plan = $launcher->propose($incident, collect([$student]), 'aleatori', 1, random: fn (int $max) => 0)['plan'];
        $this->assertSame([$ids[2]], $plan[$student->id]);
    }

    public function test_students_with_an_active_one_are_skipped_and_same_mode_gives_the_chosen_option(): void
    {
        $incident = $this->incident(2);
        [$a, $b] = $this->students(2)->all();
        $option = $incident->options->last();
        $launch = $incident->launches()->create(['mode' => 'igual', 'quantitat' => 1]);
        IncidentStudent::create(['incidencia_id' => $incident->id, 'llancament_id' => $launch->id, 'opcio_id' => $option->id, 'nom_opcio' => 'x', 'alumne_id' => $a->id, 'estat' => IncidentStatus::Oberta]);

        $result = app(IncidentLauncher::class)->propose($incident, collect([$a, $b]), 'igual', 1, $option->id);

        $this->assertSame([$b->id => [$option->id]], $result['plan']);
        $this->assertSame([$a->id], $result['skipped']);
    }

    public function test_full_cycle_inject_verify_open_and_resolve(): void
    {
        $incident = $this->incident(2);
        $student = $this->students(1)->first();
        $option = $incident->options->first();

        app(IncidentLauncher::class)->launch($incident, [$student->id => [$option->id]], 'aleatori', 1, null, null);

        $row = IncidentStudent::sole();
        $this->assertSame(IncidentStatus::Pendent, $row->estat);
        $run = ActionRun::sole();
        $this->assertSame('0', $run->payload_agent['env']['HE_PARAM_N']);
        $this->assertSame($row->execucio_id, $run->execucio_id);

        // L'agent torna l'acció: es verifica amb el check (una feina d'agent pendent).
        $this->finishActions();
        $this->assertSame(IncidentStatus::Verificant, $row->fresh()->estat);
        $this->assertNotNull($row->fresh()->data_injeccio);
        $this->assertSame($option->check_id, AgentTask::sole()->check_id);

        // El check falla: l'avaria és oberta. Després passa: resolta.
        $this->recordCheck($option->check, $student, CheckStatus::Fail);
        $this->assertSame(IncidentStatus::Oberta, $row->fresh()->estat);
        $this->recordCheck($option->check, $student, CheckStatus::Pass);
        $row->refresh();
        $this->assertSame(IncidentStatus::Resolta, $row->estat);
        $this->assertNotNull($row->data_resolucio);
        $this->assertNotNull($row->execucio_check_id);
        $this->assertGreaterThan(0, $row->resolutionSeconds());
    }

    public function test_a_check_that_already_passes_marks_it_not_effective(): void
    {
        $incident = $this->incident(1);
        $student = $this->students(1)->first();
        $option = $incident->options->first();
        app(IncidentLauncher::class)->launch($incident, [$student->id => [$option->id]], 'igual', 1, null, null);
        $this->finishActions();

        // Un altre check de l'alumne no hi fa res.
        $this->recordCheck($this->agentCheck('Un altre'), $student, CheckStatus::Pass);
        $this->assertSame(IncidentStatus::Verificant, IncidentStudent::sole()->estat);

        $this->recordCheck($option->check, $student, CheckStatus::Pass);
        $this->assertSame(IncidentStatus::NoEfectiva, IncidentStudent::sole()->estat);
    }

    public function test_failed_or_expired_actions_end_in_error_or_expired(): void
    {
        $incident = $this->incident(2);
        [$a, $b] = $this->students(2)->all();
        [$o1, $o2] = $incident->options->all();
        app(IncidentLauncher::class)->launch($incident, [$a->id => [$o1->id], $b->id => [$o2->id]], 'manual', 1, null, null);

        ActionRun::where('alumne_id', $a->id)->sole()->update(['estat' => ActionStatus::Failed, 'data_fi' => now()]);
        // Caducada amb una actualització massiva (com PruneAgents): la recull la tasca de cada minut.
        ActionRun::where('alumne_id', $b->id)->update(['estat' => ActionStatus::Caducada, 'data_fi' => now()]);
        Artisan::call('heimdeye:agent-prune');

        $this->assertSame(IncidentStatus::Error, IncidentStudent::where('alumne_id', $a->id)->sole()->estat);
        $this->assertSame(IncidentStatus::Caducada, IncidentStudent::where('alumne_id', $b->id)->sole()->estat);
        $this->assertSame(0, AgentTask::count());
    }

    public function test_several_options_per_student_each_one_is_tracked(): void
    {
        $incident = $this->incident(3);
        $student = $this->students(1)->first();
        $ids = $incident->options->modelKeys();

        app(IncidentLauncher::class)->launch($incident, [$student->id => $ids], 'aleatori', 3, null, null);

        $this->assertSame(3, IncidentStudent::where('alumne_id', $student->id)->count());
        $this->assertSame(3, RunBatch::count());
        $this->assertSame(['0', '1', '2'], ActionRun::orderBy('id')->get()->map(fn (ActionRun $r) => $r->payload_agent['env']['HE_PARAM_N'])->all());
    }

    public function test_repair_launches_the_repair_action_with_the_option_params(): void
    {
        $incident = $this->incident(2);
        $student = $this->students(1)->first();
        $option = $incident->options->last();
        app(IncidentLauncher::class)->launch($incident, [$student->id => [$option->id]], 'igual', 1, null, null);
        $this->finishActions();
        $row = IncidentStudent::sole();

        app(IncidentLauncher::class)->repair($row, null);

        $this->assertSame(IncidentStatus::Reparada, $row->fresh()->estat);
        $repairRun = ActionRun::where('accio_id', $option->accio_reparacio_id)->sole();
        $this->assertSame('1', $repairRun->payload_agent['env']['HE_PARAM_N']);
    }

    public function test_an_option_that_cannot_launch_leaves_its_students_in_error(): void
    {
        $incident = $this->incident(1);
        $student = $this->students(1)->first();
        $option = $incident->options->first();
        $option->action->versions()->create(['versio' => 2, 'codi' => "echo sense hosts\n", 'temps_max_seg' => 30]);

        app(IncidentLauncher::class)->launch($incident, [$student->id => [$option->id]], 'igual', 1, null, null);

        $row = IncidentStudent::sole();
        $this->assertSame(IncidentStatus::Error, $row->estat);
        $this->assertNotEmpty($row->missatge);
    }

    public function test_bulk_repair_runs_the_repair_action_for_the_selected_faults(): void
    {
        $teacher = User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]);
        $this->actingAs($teacher);
        $incident = $this->incident(2);
        [$a, $b] = $this->students(2)->all();
        app(IncidentLauncher::class)->launch($incident, [$a->id => [$incident->options[0]->id], $b->id => [$incident->options[1]->id]], 'manual', 1, null, null);
        $this->finishActions();
        IncidentStudent::query()->update(['estat' => IncidentStatus::Oberta]);
        $rows = IncidentStudent::orderBy('id')->get();
        $before = ActionRun::count();

        Livewire::test(ManageIncidentAssignments::class, ['record' => $incident->id])
            ->filterTable('alumne_id', [$a->id])
            ->assertCanSeeTableRecords($rows->where('alumne_id', $a->id))
            ->assertCanNotSeeTableRecords($rows->where('alumne_id', $b->id))
            ->removeTableFilters()
            ->callTableBulkAction('repararTots', $rows)
            ->assertNotified('2 reparacions a la cua');

        $this->assertSame([IncidentStatus::Reparada, IncidentStatus::Reparada], IncidentStudent::orderBy('id')->pluck('estat')->all());
        $this->assertSame($before + 2, ActionRun::count());
        $this->assertSame(['Desfer'], ActionRun::latest('id')->limit(2)->get()->map(fn (ActionRun $r) => $r->action->nom)->unique()->values()->all());
    }

    public function test_delete_removes_the_rows_and_launches_left_empty(): void
    {
        $teacher = User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]);
        $this->actingAs($teacher);
        $incident = $this->incident(2);
        [$a, $b] = $this->students(2)->all();
        app(IncidentLauncher::class)->launch($incident, [$a->id => [$incident->options[0]->id], $b->id => [$incident->options[1]->id]], 'manual', 1, null, null);
        app(IncidentLauncher::class)->launch($incident, [$a->id => [$incident->options[1]->id]], 'manual', 1, null, null);
        $this->finishActions();
        [$first, $second, $third] = IncidentStudent::orderBy('id')->get()->all();
        $runs = ActionRun::count();

        Livewire::test(ManageIncidentAssignments::class, ['record' => $incident->id])
            ->callTableAction('esborrar', $third)
            ->assertNotified('1 avaries esborrades')
            ->callTableBulkAction('esborrarTots', [$first])
            ->assertNotified('1 avaries esborrades');

        $this->assertSame([$second->id], IncidentStudent::pluck('id')->all());
        $this->assertSame([$second->llancament_id], IncidentLaunch::pluck('id')->all()); // el buit fora, el que té files es queda
        $this->assertSame($runs, ActionRun::count()); // l'historial d'execucions no es toca
    }

    public function test_the_student_sees_only_their_faults_with_a_neutral_name(): void
    {
        $incident = $this->incident(3);
        [$a] = $this->students(1)->all();
        app(IncidentLauncher::class)->launch($incident, [$a->id => [$incident->options[1]->id]], 'manual', 1, null, null);
        $this->finishActions();
        $check = $incident->options[1]->check;
        $this->recordCheck($check, $a, CheckStatus::Fail);

        $user = User::create(['nom' => 'Alumne', 'email' => 'alumne@example.com', 'rol' => Role::Student, 'es_actiu' => true]);
        $a->update(['usuari_id' => $user->id]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('alumne'));

        Livewire::test(MyProgress::class, ['tasca' => $check->tasca_id])
            ->assertSee('Incidència · Web caiguda')
            ->assertDontSee('Check 0')
            ->assertDontSee('Check 1')
            ->assertDontSee('Check 2')
            ->call('mountAction', 'info', ['check' => $check->id])
            ->assertDontSee('Check 1');
    }

    public function test_the_agent_gets_a_neutral_name_for_incident_work(): void
    {
        $incident = $this->incident(1);
        $student = $this->students(1)->first();
        $headers = ['Authorization' => 'Bearer '.$student->regenerateAgentToken()];
        $this->postJson('/api/agent/register', ['hostname' => 'web'], $headers)->assertOk();
        app(IncidentLauncher::class)->launch($incident, [$student->id => [$incident->options[0]->id]], 'manual', 1, null, null);

        $this->getJson('/api/agent/poll?hostname=web', $headers)->assertOk()->assertJsonPath('task.name', 'acció «Incidència»')
            ->assertJsonPath('task.show_output', false); // l'agent no n'ensenya la sortida a l'alumne
    }

    public function test_panel_pages_render_and_close_works(): void
    {
        $teacher = User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]);
        $this->actingAs($teacher);
        $incident = $this->incident(2);
        $student = $this->students(1)->first();
        app(IncidentLauncher::class)->launch($incident, [$student->id => [$incident->options->first()->id]], 'igual', 1, null, null);
        $this->finishActions();
        $row = IncidentStudent::sole();

        Livewire::test(ListIncidents::class)->assertOk()->assertSee('Web caiguda');
        Livewire::test(EditIncident::class, ['record' => $incident->id])->assertOk()->assertSee('Avaria 1');
        Livewire::test(ManageIncidentAssignments::class, ['record' => $incident->id])
            ->assertOk()
            ->assertSee('Avaria 0')
            ->callTableAction('tancar', $row)
            ->assertHasNoTableActionErrors();

        $this->assertSame(IncidentStatus::Tancada, $row->fresh()->estat);
        $this->assertSame($teacher->id, $row->fresh()->tancat_per);
    }

    public function test_follow_up_filters_by_student_fault_status_and_resolved_and_sorts(): void
    {
        $this->actingAs(User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]));
        $incident = $this->incident(2);
        [$a, $b] = $this->students(2)->all();
        [$o1, $o2] = $incident->options->all();
        app(IncidentLauncher::class)->launch($incident, [$a->id => [$o1->id], $b->id => [$o2->id]], 'manual', 1, null, null);

        // L'avaria d'$a la recull la seva instància «web»; la de $b caduca sense instància.
        $instance = AgentInstance::create(['alumne_id' => $a->id, 'hostname' => 'web', 'estat' => 'activa', 'darrer_batec' => now(), 'registrat_a' => now()]);
        ActionRun::where('alumne_id', $a->id)->update(['instancia_id' => $instance->id]);
        ActionRun::where('alumne_id', $a->id)->sole()->update(['estat' => ActionStatus::Ok, 'recollit_a' => now(), 'data_fi' => now()]);
        ActionRun::where('alumne_id', $b->id)->sole()->update(['estat' => ActionStatus::Caducada, 'data_fi' => now()]);
        $rowA = IncidentStudent::where('alumne_id', $a->id)->sole();
        $rowB = IncidentStudent::where('alumne_id', $b->id)->sole();
        $this->recordCheck($o1->check, $a, CheckStatus::Fail);
        $this->recordCheck($o1->check, $a, CheckStatus::Pass);
        $this->assertSame(IncidentStatus::Resolta, $rowA->fresh()->estat);

        // Els filtres es recorden a la sessió: cada prova comença sense cap.
        $page = fn () => Livewire::test(ManageIncidentAssignments::class, ['record' => $incident->id])->resetTableFilters();

        $page()->assertCanSeeTableRecords([$rowA, $rowB])->assertSee('web')
            ->filterTable('alumne_id', [$b->id])->assertCanSeeTableRecords([$rowB])->assertCanNotSeeTableRecords([$rowA]);
        $page()->filterTable('estat', [IncidentStatus::Resolta->value])->assertCanSeeTableRecords([$rowA])->assertCanNotSeeTableRecords([$rowB]);
        $page()->filterTable('resolta', true)->assertCanSeeTableRecords([$rowA])->assertCanNotSeeTableRecords([$rowB]);
        $page()->filterTable('resolta', false)->assertCanSeeTableRecords([$rowB])->assertCanNotSeeTableRecords([$rowA]);
        $page()->filterTable('opcio_id', [$o2->id])->assertCanSeeTableRecords([$rowB])->assertCanNotSeeTableRecords([$rowA]);

        foreach (['launch.created_at', 'student.usuari', 'instancia', 'nom_opcio', 'estat', 'data_injeccio', 'data_resolucio', 'temps', 'launch.data_limit'] as $column) {
            $page()->sortTable($column)->assertOk()->sortTable($column, 'desc')->assertOk();
        }
        $page()->sortTable('student.usuari')->assertCanSeeTableRecords([$rowA, $rowB], inOrder: true)
            ->sortTable('student.usuari', 'desc')->assertCanSeeTableRecords([$rowB, $rowA], inOrder: true);
        $page()->sortTable('temps')->assertCanSeeTableRecords([$rowA, $rowB], inOrder: true);
    }

    public function test_the_list_filters_by_equip_and_sorts_by_every_column(): void
    {
        $this->actingAs(User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]));
        $web = $this->incident(1); // equip «web»
        $aws = Incident::create(['modul_id' => $this->module->id, 'nom' => 'SG tancat', 'equip' => 'aws', 'simptoma' => 'x']);

        Livewire::test(ListIncidents::class)
            ->resetTableFilters()
            ->filterTable('equip', ['aws'])
            ->assertCanSeeTableRecords([$aws])
            ->assertCanNotSeeTableRecords([$web]);

        foreach (['nom', 'module.codi', 'block.numero', 'task.titol', 'equip', 'options_count', 'obertes_count', 'resoltes_count'] as $column) {
            Livewire::test(ListIncidents::class)->sortTable($column)->assertOk()->sortTable($column, 'desc')->assertOk();
        }
        Livewire::test(ListIncidents::class)->resetTableFilters()->sortTable('equip')->assertCanSeeTableRecords([$aws, $web], inOrder: true);
    }

    public function test_the_list_filters_by_block_and_task(): void
    {
        $this->actingAs(User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]));
        $web = $this->incident(1); // checks a la tasca «Resolució» del bloc 90
        $task = $web->options->first()->check->task;
        $web->update(['bloc_id' => $task->bloc_id, 'tasca_id' => $task->id]);
        $other = Incident::create(['modul_id' => $this->module->id, 'nom' => 'DNS trencat', 'equip' => 'web', 'simptoma' => 'x']);

        Livewire::test(ListIncidents::class)
            ->filterTable('modul', $this->module->id)
            ->assertCanSeeTableRecords([$web, $other])
            ->filterTable('bloc', $task->bloc_id)
            ->assertCanSeeTableRecords([$web])
            ->assertCanNotSeeTableRecords([$other])
            ->filterTable('tasca', $task->id)
            ->assertCanSeeTableRecords([$web]);

        $empty = $task->block->tasks()->create(['titol' => 'Una altra', 'posicio' => 2]);
        Livewire::test(ListIncidents::class)
            ->filterTable('bloc', $task->bloc_id)
            ->filterTable('tasca', $empty->id)
            ->assertCanNotSeeTableRecords([$web, $other]);
    }

    public function test_launch_wizard_injects_the_previewed_plan(): void
    {
        $teacher = User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]);
        $this->actingAs($teacher);
        $incident = $this->incident(2);
        $student = $this->students(1)->first();
        $option = $incident->options->last();

        Livewire::test(EditIncident::class, ['record' => $incident->id])
            ->mountAction('llancar')
            ->setActionData([
                'alumnes' => [(string) $student->id],
                'mode' => 'igual',
                'opcio' => $option->id,
                'proposta' => json_encode([$student->id => [$option->id]]),
                'pla' => [['alumne_id' => $student->id, 'opcions' => [(string) $option->id]]],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $row = IncidentStudent::sole();
        $this->assertSame($option->id, $row->opcio_id);
        $this->assertSame('igual', $row->launch->mode);
        $this->assertSame($teacher->id, $row->launch->llancat_per);
    }
}
