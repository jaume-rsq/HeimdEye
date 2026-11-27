<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Enums\CheckKind;
use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Filament\Pages\StudentTracking;
use App\Filament\Resources\Checks\Pages\EditCheck;
use App\Filament\Student\Pages\MyProgress;
use App\Models\Action;
use App\Models\ActionRun;
use App\Models\Check;
use App\Models\Incident;
use App\Models\IncidentStudent;
use App\Models\Module;
use App\Models\RunBatch;
use App\Models\Student;
use App\Models\User;
use App\Runner\CheckResult;
use App\Runner\Incidents\IncidentLauncher;
use App\Runner\ResultRecorder;
use App\Support\Leaderboard;
use App\Support\StudentProgress;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Els checks de tipus «incidència» (no compten) i el que en veu i en pot fer l'alumne. */
class IncidentStudentViewTest extends TestCase
{
    use RefreshDatabase;

    private const TASK = 'Pas d\'avaries';

    protected bool $seed = true;

    private Module $module;

    private Student $student;

    private Incident $incident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = Module::where('codi', '9991')->sole();
        $this->student = $this->module->students()->orderBy('num_taula')->firstOrFail();

        $block = $this->module->blocks()->create(['numero' => 90, 'titol' => 'Bloc d\'avaries', 'posicio' => 90]);
        $task = $block->tasks()->create(['titol' => self::TASK, 'posicio' => 1]);
        $inject = $this->action('Injectar', fault: true);
        $this->incident = Incident::create([
            'modul_id' => $this->module->id, 'bloc_id' => $block->id, 'tasca_id' => $task->id,
            'nom' => 'Cas 1', 'simptoma' => 'Símptoma general', 'minuts_verificacio' => 2,
        ]);
        foreach (['Causa alfa', 'Causa beta'] as $i => $name) {
            $check = $task->checks()->create([
                'nom' => $name, 'executor' => Executor::Agent, 'tipus' => CheckKind::Incident, 'posicio' => $i + 1,
            ]);
            $check->versions()->create(['versio' => 1, 'codi' => "# hosts: web\ntrue\n", 'temps_max_seg' => 30]);
            $this->incident->options()->create([
                'nom' => "Opció {$name}", 'simptoma' => $i === 0 ? 'Els correus tornen' : null,
                'accio_id' => $inject->id, 'check_id' => $check->id, 'posicio' => $i,
            ]);
        }
    }

    private function action(string $name, bool $fault): Action
    {
        $action = $this->module->actions()->create(['nom' => $name, 'executor' => Executor::Agent, 'es_incidencia' => $fault]);
        $action->versions()->create(['versio' => 1, 'codi' => "# hosts: web\ntrue\n", 'temps_max_seg' => 30]);

        return $action->fresh();
    }

    private function check(int $option = 0): Check
    {
        return $this->incident->options()->orderBy('posicio')->get()[$option]->check;
    }

    private function launch(int $option = 0, ?CarbonInterface $deadline = null): IncidentStudent
    {
        $id = $this->incident->options()->orderBy('posicio')->get()[$option]->id;
        app(IncidentLauncher::class)->launch($this->incident, [$this->student->id => [$id]], 'manual', 1, $deadline, null);

        return IncidentStudent::latest('id')->firstOrFail();
    }

    private function inject(): void
    {
        $this->travel(1)->seconds();
        ActionRun::where('estat', ActionStatus::Pendent)->get()
            ->each(fn (ActionRun $run) => $run->update(['estat' => ActionStatus::Ok, 'recollit_a' => now(), 'data_fi' => now()]));
    }

    private function record(Check $check, CheckStatus $status, string $message = 'missatge secret'): void
    {
        $batch = RunBatch::create(['modul_id' => $this->module->id, 'origen' => 'manual', 'data_inici' => now()]);
        $this->travel(1)->seconds();
        app(ResultRecorder::class)->record($batch, $check, $this->student, new CheckResult($status, $message));
    }

    /** Llança l'avaria i la deixa oberta (el check falla després d'injectar-la). */
    private function open(int $option = 0, ?CarbonInterface $deadline = null): IncidentStudent
    {
        $row = $this->launch($option, $deadline);
        $this->inject();
        $this->record($this->check($option), CheckStatus::Fail);
        $this->assertSame(IncidentStatus::Oberta, $row->fresh()->estat);

        return $row->fresh();
    }

    private function asStudent(): void
    {
        $user = User::create(['nom' => 'Alumne', 'email' => 'alumne@example.com', 'rol' => Role::Student, 'es_actiu' => true]);
        $this->student->update(['usuari_id' => $user->id]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('alumne'));
    }

    private function asTeacher(): void
    {
        $this->actingAs(User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Admin, 'es_actiu' => true]));
    }

    private function page()
    {
        return Livewire::withQueryParams(['modul' => $this->module->id])->test(MyProgress::class);
    }

    public function test_an_incident_check_never_scores_has_dates_nor_is_active(): void
    {
        $check = $this->check();
        $check->update(['punts' => 5, 'bonus_puntualitat_pct' => 10, 'es_actiu' => true, 'actiu_des' => '2026-01-01', 'data_limit' => '2027-01-01']);
        $check->refresh();

        $this->assertSame([0, 0, false, null, null], [$check->punts, $check->bonus_puntualitat_pct, $check->es_actiu, $check->actiu_des, $check->data_limit]);
        $this->assertFalse(Leaderboard::counts($check));
        $this->assertSame(0.0, (float) Leaderboard::maxPoints($check));
    }

    public function test_it_does_not_count_even_when_it_passes(): void
    {
        $board = fn () => collect(app(Leaderboard::class)->build($this->module)['rows'])->firstWhere('student.id', $this->student->id);
        $before = $board();
        $this->open();
        $this->record($this->check(), CheckStatus::Pass);

        $after = $board();
        $this->assertSame($before['punts'], $after['punts']);
        $this->assertSame($before['obligatoris_total'], $after['obligatoris_total']);
        $this->assertFalse(collect(app(Leaderboard::class)->build($this->module)['blocks'])->contains(fn (array $b) => $b['block']->numero === 90));

        $block = collect(app(StudentProgress::class)->build($this->student, $this->module))->firstWhere('block.numero', 90);
        $this->assertSame(0, $block['resum']['total']);
        $this->assertSame([0, 0, 1, 1], [$block['tasks'][0]['assolits'], $block['tasks'][0]['total'], $block['tasks'][0]['resoltes'], $block['tasks'][0]['avaries']]);
    }

    public function test_the_nightly_run_and_bulk_verification_leave_them_out(): void
    {
        Queue::fake();
        $check = $this->check();
        $check->update(['executor' => Executor::Local]);
        $check->versions()->create(['versio' => 2, 'executor' => Executor::Local, 'codi' => 'true', 'temps_max_seg' => 10]);

        $this->artisan('heimdeye:run', ['--module' => '9991', '--block' => 90, '--nightly' => true]);
        $this->assertSame(0, RunBatch::count());

        $this->asTeacher();
        Livewire::test(StudentTracking::class, ['alumne' => $this->student->id, 'modul' => $this->module->id])
            ->call('verify', null, $check->tasca_id)
            ->assertNotified('No hi ha cap check per verificar');
        $this->assertSame(0, RunBatch::count());

        // D'un en un, sí.
        Livewire::test(StudentTracking::class, ['alumne' => $this->student->id, 'modul' => $this->module->id])
            ->call('verify', $check->id)
            ->assertNotified('Check a la cua');
        $this->assertSame(1, RunBatch::count());
    }

    public function test_the_student_sees_nothing_until_a_fault_is_injected(): void
    {
        // Ni abans de llançar, ni passades anteriors, ni mentre s'injecta, ni si no fa efecte.
        $this->record($this->check(), CheckStatus::Pass);
        $this->asStudent();
        $hidden = fn () => $this->page()
            ->assertOk()
            ->assertDontSee(self::TASK)
            ->assertDontSee('Bloc d\'avaries')
            ->assertDontSee('Cas 1')
            ->assertDontSee('Causa alfa')
            ->assertDontSee('Símptoma general')
            ->assertDontSee('Els correus tornen');

        $hidden();
        $row = $this->launch();
        $hidden();
        $row->update(['estat' => IncidentStatus::NoEfectiva]);
        $hidden();
        $this->page()->call('verify', $this->check()->id)->assertForbidden();
    }

    public function test_the_student_sees_the_received_fault_with_its_symptom(): void
    {
        $this->record($this->check(), CheckStatus::Pass, 'abans d\'injectar');
        $this->open();
        $this->asStudent();

        $page = $this->page()
            ->assertSee(self::TASK)
            ->assertSee('Incidència · Cas 1')
            ->assertSee('Els correus tornen')
            ->assertSee('Oberta')
            ->assertSee('0/1 incidències resoltes')
            ->assertSee('Verificar ara (un cop cada 2 minuts)')
            ->assertDontSee('Símptoma general')
            ->assertDontSee('Causa alfa')
            ->assertDontSee('Causa beta')
            ->assertDontSee('Opció')
            ->assertDontSee('missatge secret')
            ->assertDontSee('abans d\'injectar');

        $row = collect($page->instance()->progress)->firstWhere('block.numero', 90)['tasks'][0]['rows'];
        $this->assertCount(1, $row);
        $this->assertSame(1, $row[0]['execucions'], 'només les execucions de després d\'injectar-la');
    }

    public function test_an_option_without_symptom_shows_the_one_of_the_incident(): void
    {
        $this->open(1);
        $this->asStudent();

        $this->page()->assertSee('Símptoma general')->assertDontSee('Els correus tornen');
    }

    public function test_the_student_verifies_an_open_fault_every_few_minutes_until_solved(): void
    {
        $this->open();
        $this->asStudent();
        $check = $this->check();

        $before = RunBatch::count();
        $this->page()->call('verify', $check->id)->assertNotified('Check a la cua');
        $this->page()->call('verify', $check->id)->assertNotified('Encara no');
        $this->assertSame($before + 1, RunBatch::count());
        $this->travel(3)->minutes();
        $this->page()->call('verify', $check->id)->assertNotified('Check a la cua');
        $this->assertSame($before + 2, RunBatch::count());

        // L'altre check de la incidència no és seu.
        $this->page()->call('verify', $this->check(1)->id)->assertForbidden();

        $this->record($check, CheckStatus::Pass);
        $this->page()->assertSee('Resolta')->assertSee('1/1 incidències resoltes');
        $this->travel(3)->minutes();
        $this->page()->call('verify', $check->id)->assertForbidden();
    }

    public function test_the_deadline_closes_the_students_verification(): void
    {
        $this->open(0, now()->addHour());
        $this->asStudent();
        $this->page()->assertSee('Termini:')->assertSee('Verificar ara (un cop cada 2 minuts)');

        $this->travel(2)->hours();
        $this->page()
            ->assertSee('(acabat)')
            ->assertDontSee('Verificar ara (un cop cada 2 minuts)')
            ->call('verify', $this->check()->id)
            ->assertForbidden();
    }

    public function test_the_teacher_sees_the_state_of_each_fault(): void
    {
        $this->open();
        $this->asTeacher();

        Livewire::test(StudentTracking::class, ['alumne' => $this->student->id, 'modul' => $this->module->id, 'tasca' => $this->check()->tasca_id])
            ->assertSee('Causa alfa')
            ->assertSee('Causa beta')
            ->assertSee('No rebuda')
            ->assertSee('Oberta')
            ->assertDontSee('Verificar la tasca');
    }

    public function test_the_check_editor_saves_an_incident_check(): void
    {
        $this->asTeacher();
        $check = $this->check();
        $check->update(['tipus' => CheckKind::Optional, 'punts' => 3, 'es_actiu' => null]);

        Livewire::test(EditCheck::class, ['record' => $check->id])
            ->fillForm(['tipus' => CheckKind::Incident->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([CheckKind::Incident, 0, false], [$check->fresh()->tipus, $check->fresh()->punts, $check->fresh()->es_actiu]);
    }

    public function test_the_migration_converts_checks_used_only_by_incidents(): void
    {
        $migration = require database_path('migrations/2026_11_27_000001_add_incident_student_view.php');
        $migration->down();
        $this->assertSame(0, Check::where('tipus', 'incidencia')->count());

        [$only, $active] = [$this->check(0), $this->check(1)];
        $active->update(['es_actiu' => true, 'punts' => 2]); // també puntua: es queda com està

        $migration->up();

        $this->assertSame(CheckKind::Incident, $only->fresh()->tipus);
        $this->assertSame(CheckKind::Optional, $active->fresh()->tipus);
    }
}
