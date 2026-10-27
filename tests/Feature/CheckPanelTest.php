<?php

namespace Tests\Feature;

use App\Enums\CheckOverview;
use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Enums\Role;
use App\Enums\RunOrigin;
use App\Filament\Resources\CheckRuns\Pages\ListCheckRuns;
use App\Filament\Resources\Checks\Pages\CreateCheck;
use App\Filament\Resources\Checks\Pages\EditCheck;
use App\Filament\Resources\Checks\Pages\ListChecks;
use App\Filament\Resources\Checks\Pages\ManageCheckRuns;
use App\Jobs\RunCheck;
use App\Jobs\RunEditorTest;
use App\Models\Action;
use App\Models\Check;
use App\Models\CheckRun;
use App\Models\CheckStudent;
use App\Models\Module;
use App\Models\RunBatch;
use App\Models\Student;
use App\Models\Task;
use App\Models\User;
use App\Runner\CheckRunner;
use App\Support\CheckSummary;
use App\Support\DeletionImpact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Panell de checks: estat global, versions, proves de l'editor, verificar ara i historial. */
class CheckPanelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->module = Module::where('codi', '9991')->firstOrFail();
        $this->teacher = User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]);
    }

    private function task(): Task
    {
        return Task::whereHas('block', fn ($q) => $q->where('modul_id', $this->module->id))->firstOrFail();
    }

    private function check(string $code = 'echo hola', array $params = []): Check
    {
        $check = $this->task()->checks()->create(['nom' => 'Prova', 'executor' => Executor::Local, 'es_actiu' => true]);
        $check->versions()->create(['versio' => 1, 'codi' => $code, 'parametres' => $params ?: null, 'temps_max_seg' => 10]);

        return $check->fresh();
    }

    private function record(Check $check, Student $student, CheckStatus $status): void
    {
        $batch = RunBatch::create(['modul_id' => $this->module->id, 'origen' => RunOrigin::Manual, 'data_inici' => now(), 'data_fi' => now()]);
        $check->runs()->create([
            'execucio_id' => $batch->id, 'versio_check_id' => $check->currentVersion->id, 'alumne_id' => $student->id,
            'estat' => $status, 'data_execucio' => now(),
        ]);
    }

    private function students()
    {
        return $this->module->students()->where('es_actiu', true)->orderBy('num_taula')->get();
    }

    public function test_pages_open(): void
    {
        $this->actingAs($this->teacher);
        $check = $this->check();

        $this->get('/admin/checks')->assertOk()->assertSee('Prova');
        $this->get('/admin/checks/nou')->assertOk();
        $this->get("/admin/checks/{$check->id}/editar")->assertOk();
        $this->get("/admin/checks/{$check->id}/versions")->assertOk();
        $this->get("/admin/checks/{$check->id}/historial")->assertOk()->assertSee('Estat per alumne');
        $this->get('/admin/historic-checks')->assertOk();
    }

    public function test_overview_uses_the_latest_run_of_each_active_student(): void
    {
        $check = $this->check();
        $students = $this->students();
        $this->assertSame(CheckOverview::Never, CheckSummary::for($check)->overview());

        $this->record($check, $students[0], CheckStatus::Fail);
        $this->assertSame(CheckOverview::NonePassed, CheckSummary::for($check)->overview());

        $this->record($check, $students[0], CheckStatus::Pass);
        $this->assertSame(CheckOverview::Partial, CheckSummary::for($check)->overview());

        foreach ($students as $student) {
            $this->record($check, $student, CheckStatus::Pass);
        }
        $this->assertSame(CheckOverview::Ok, CheckSummary::for($check)->overview());

        $this->record($check, $students[1], CheckStatus::Unavailable);
        $summary = CheckSummary::for($check);
        $this->assertSame(CheckOverview::Problems, $summary->overview());
        $this->assertTrue($summary->has(CheckStatus::Unavailable, all: false));
        $this->assertFalse($summary->has(CheckStatus::Pass, all: true));
    }

    public function test_list_filters_by_status_for_all_or_some_students(): void
    {
        $this->actingAs($this->teacher);
        $failing = $this->check();
        $clean = $this->check();
        foreach ($this->students() as $i => $student) {
            $this->record($failing, $student, $i === 0 ? CheckStatus::Fail : CheckStatus::Pass);
            $this->record($clean, $student, CheckStatus::Pass);
        }

        Livewire::test(ListChecks::class)
            ->filterTable('execucions', ['estat' => 'fail', 'abast' => 'algun'])
            ->assertCanSeeTableRecords([$failing])
            ->assertCanNotSeeTableRecords([$clean])
            ->filterTable('execucions', ['estat' => 'pass', 'abast' => 'tots'])
            ->assertCanSeeTableRecords([$clean])
            ->assertCanNotSeeTableRecords([$failing]);
    }

    public function test_create_makes_version_one_and_edit_only_versions_real_changes(): void
    {
        $this->actingAs($this->teacher);
        $student = $this->students()->first();
        $task = $this->task();

        $create = Livewire::test(CreateCheck::class)
            ->fillForm([
                'nom' => 'Nou', 'modul_id' => $this->module->id, 'tasca_id' => $task->id, 'executor' => 'local',
                'codi' => 'echo "hola $HE_PARAM_NOM"', 'parametres' => [['name' => 'nom', 'label' => 'Nom', 'default' => 'x']],
                'temps_max_seg' => 10, 'punts' => 3,
            ])
            ->call('create');
        $this->assertSame(0, Check::where('nom', 'Nou')->count(), 'no es desa sense provar');

        $create->callAction('provar', ['alumnes' => [(string) $student->id]])
            ->assertSet('testResults.0.estat', 'pass')
            ->assertSet('testResults.0.resum', 'hola x')
            ->assertSet('testResults.0.comanda', "# bash al servidor HeimdEye\necho \"hola x\"")
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame(0, CheckRun::count(), 'les proves no es desen');
        $this->assertSame(0, CheckStudent::count(), 'les proves no compten');
        $check = Check::where('nom', 'Nou')->firstOrFail();
        $this->assertSame($task->id, $check->tasca_id);
        $this->assertSame(3, $check->punts);
        $this->assertSame([1], $check->versions()->pluck('versio')->all());
        $this->assertSame($this->teacher->id, $check->currentVersion->creat_per);
        $this->assertSame('NOM', $check->currentVersion->parametres[0]['name'], 'els noms dels paràmetres van en majúscules');

        Livewire::test(EditCheck::class, ['record' => $check->id])
            ->fillForm(['punts' => 5, 'descripcio' => 'Només canvien dades del check'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(1, $check->versions()->count());
        $this->assertSame(5, $check->fresh()->punts);

        $edit = Livewire::test(EditCheck::class, ['record' => $check->id])
            ->fillForm(['codi' => 'echo adeu', 'comentari' => 'Corregeix el missatge'])
            ->call('save');
        $this->assertSame(1, $check->versions()->count(), 'una versió nova cal provar-la');

        $edit->callAction('provar', ['alumnes' => [(string) $student->id]])
            ->call('save')
            ->assertHasNoFormErrors();
        $check->refresh();
        $this->assertSame(2, $check->currentVersion->versio);
        $this->assertSame('echo adeu', $check->currentVersion->codi);
        $this->assertSame('Corregeix el missatge', $check->currentVersion->comentari);
        $this->assertSame(Executor::Local, $check->executor);
    }

    public function test_code_that_would_leak_a_secret_cannot_be_saved(): void
    {
        $this->actingAs($this->teacher);
        $this->module->secrets()->updateOrCreate(['clau' => 'jaume_password'], ['valor' => 'molt-secreta']);
        $check = $this->task()->checks()->create(['nom' => 'Ssh', 'executor' => Executor::Ssh]);
        $group = $this->module->hostTypes()->firstOrFail()->grup_ansible;
        $check->versions()->create(['versio' => 1, 'codi' => "# hosts: {$group}\ntrue", 'temps_max_seg' => 10]);

        Livewire::test(EditCheck::class, ['record' => $check->id])
            ->fillForm(['codi' => "# hosts: {$group}\necho \"\$HE_SECRET_JAUME_PASSWORD\""])
            ->set('testedHash', 'qualsevol')
            ->call('save')
            ->assertNotified('El codi no es pot desar');
        $this->assertSame(1, $check->versions()->count());
    }

    public function test_editor_tests_go_through_the_queue(): void
    {
        Queue::fake();
        $this->actingAs($this->teacher);
        $check = $this->check();
        $student = $this->students()->first();

        $edit = Livewire::test(EditCheck::class, ['record' => $check->id])
            ->fillForm(['codi' => 'echo esborrany'])
            ->callAction('provar', ['alumnes' => [(string) $student->id]])
            ->assertSet('testResults.0.estat', 'en cua');

        $job = null;
        Queue::assertPushed(RunEditorTest::class, function (RunEditorTest $pushed) use (&$job, $student) {
            $job = $pushed;

            return $pushed->studentId === $student->id && $pushed->version['codi'] === 'echo esborrany';
        });
        $job->handle();

        $edit->call('refreshTestResults')
            ->assertSet('testResults.0.estat', 'pass')
            ->assertSet('testResults.0.resum', 'esborrany');
        $this->assertSame(0, CheckRun::count());
    }

    public function test_verify_now_queues_one_job_per_student_with_the_current_version(): void
    {
        Queue::fake();
        $this->actingAs($this->teacher);
        $check = $this->check();
        $students = $this->students();

        Livewire::test(ManageCheckRuns::class, ['record' => $check->id])
            ->mountAction('verificar')
            ->assertActionDataSet(['alumnes' => array_map('strval', $students->modelKeys())])
            ->setActionData(['alumnes' => [(string) $students[0]->id, (string) $students[1]->id]])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        Queue::assertPushed(RunCheck::class, 2);
        Queue::assertPushed(RunCheck::class, fn (RunCheck $job) => $job->version->is($check->currentVersion) && $job->student->is($students[0]));
        $batch = RunBatch::sole();
        $this->assertSame(RunOrigin::Manual, $batch->origen);
        $this->assertSame($this->teacher->id, $batch->llancat_per);
        $this->assertSame(2, $batch->totals['expected']);
    }

    public function test_verify_now_records_like_the_nightly_run(): void
    {
        $check = $this->check('echo "taula $HE_NUM_TAULA"');
        $student = $this->students()->first();

        $batch = app(CheckRunner::class)->launch($check, collect([$student]), $this->teacher);

        $run = CheckRun::sole();
        $this->assertSame(CheckStatus::Pass, $run->estat);
        $this->assertSame($check->currentVersion->id, $run->versio_check_id);
        $this->assertNotNull(CheckStudent::where('check_id', $check->id)->sole()->data_primer_assoliment);
        $this->assertNotNull($batch->fresh()->data_fi);
    }

    public function test_a_check_without_default_parameters_cannot_be_launched(): void
    {
        Queue::fake();
        $check = $this->check(params: [['name' => 'ip', 'label' => null, 'default' => null]]);

        try {
            app(CheckRunner::class)->launch($check, $this->students());
            $this->fail('Calia una excepció');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('ip', $e->getMessage());
        }
        Queue::assertNothingPushed();
        $this->assertSame(0, RunBatch::count());
    }

    public function test_students_with_an_older_version_are_shown_and_can_be_verified_again(): void
    {
        $this->actingAs($this->teacher);
        $check = $this->check();
        $students = $this->students();
        foreach ($students as $i => $student) {
            $this->record($check, $student, $i === 1 ? CheckStatus::Fail : CheckStatus::Pass);
        }
        Livewire::test(ManageCheckRuns::class, ['record' => $check->id])->assertActionHidden('actualitzar');

        $check->versions()->create(['versio' => 2, 'codi' => 'echo v2', 'temps_max_seg' => 10]);
        $check = $check->fresh();
        $this->record($check, $students[0], CheckStatus::Pass);

        $summary = CheckSummary::for($check);
        $this->assertSame(CheckOverview::Partial, $summary->overview());
        $this->assertStringContainsString(($students->count() - 1).' amb una versió anterior', $summary->describe());
        $grid = collect(CheckSummary::students($check))->keyBy('id');
        $this->assertTrue($grid[$students[0]->id]['vigent']);
        $this->assertFalse($grid[$students[1]->id]['vigent']);
        $this->assertSame(1, $grid[$students[1]->id]['versio']);
        // També els que fallaven amb la versió anterior: la corregida els pot donar per bons.
        $this->assertSame($students->skip(1)->values()->modelKeys(), CheckSummary::outdatedStudentIds($check));

        Livewire::test(ManageCheckRuns::class, ['record' => $check->id])
            ->assertActionVisible('actualitzar')
            ->mountAction('actualitzar')
            ->assertActionDataSet(['alumnes' => array_map('strval', $students->skip(1)->values()->modelKeys())]);
    }

    public function test_a_failing_job_leaves_an_error_in_the_history(): void
    {
        $check = $this->check();
        $student = $this->students()->first();
        $batch = RunBatch::create(['modul_id' => $this->module->id, 'origen' => RunOrigin::Nightly, 'totals' => ['expected' => 1]]);

        (new RunCheck($batch, $check, $student))->failed(new \RuntimeException('temps màxim superat'));

        $run = $check->runs()->sole();
        $this->assertSame(CheckStatus::Error, $run->estat);
        $this->assertSame($check->currentVersion->id, $run->versio_check_id);
        $this->assertStringContainsString('temps màxim superat', $run->missatge);
        $this->assertNotNull($batch->fresh()->data_fi);
    }

    public function test_history_lists_runs_of_every_check(): void
    {
        $this->actingAs($this->teacher);
        $a = $this->check();
        $b = $this->check();
        $student = $this->students()->first();
        $this->record($a, $student, CheckStatus::Pass);
        $this->record($b, $student, CheckStatus::Fail);

        Livewire::test(ListCheckRuns::class)
            ->assertCanSeeTableRecords(CheckRun::all())
            ->filterTable('estat', ['fail'])
            ->assertCanSeeTableRecords($b->runs)
            ->assertCanNotSeeTableRecords($a->runs);
        Livewire::test(ManageCheckRuns::class, ['record' => $a->id])
            ->assertCanSeeTableRecords($a->runs)
            ->assertCanNotSeeTableRecords($b->runs);
    }

    public function test_delete_warns_about_what_is_lost(): void
    {
        $check = $this->check();
        $student = $this->students()->first();
        app(CheckRunner::class)->launch($check, collect([$student]), sync: true);
        $action = $this->module->actions()->create(['nom' => 'Instal·lar', 'executor' => Executor::Local]);
        $action->versions()->create(['versio' => 1, 'codi' => 'true', 'temps_max_seg' => 10, 'checks_verificacio' => [$check->id]]);

        $text = DeletionImpact::check($check);
        $this->assertStringContainsString('1 versions, 1 execucions de 1 alumnes', $text);
        $this->assertStringContainsString('assoliments de 1 alumnes', $text);
        $this->assertStringContainsString('Instal·lar', $text);

        $this->actingAs($this->teacher);
        Livewire::test(EditCheck::class, ['record' => $check->id])->callAction('delete');
        $this->assertNull(Check::find($check->id));
        $this->assertSame(0, CheckRun::count());
        $this->assertSame(0, CheckStudent::count());
        $this->assertNotNull(Action::find($action->id));
    }

    public function test_the_draft_test_validates_the_form_like_saving(): void
    {
        $this->actingAs($this->teacher);
        $student = $this->students()->first();

        Livewire::test(CreateCheck::class)
            ->fillForm([
                'nom' => 'Nou', 'modul_id' => $this->module->id, 'tasca_id' => $this->task()->id, 'executor' => 'local',
                'codi' => 'true', 'parametres' => [['name' => 'nom-dns', 'label' => null, 'default' => 'x']],
                'temps_max_seg' => 10,
            ])
            ->mountAction('provar')
            ->assertActionNotMounted('provar')
            ->assertHasFormErrors(['parametres.0.name' => 'regex'])
            ->assertNotified('No es pot provar: el formulari té errors')
            ->assertSet('testResults', []);
    }

    public function test_a_check_can_be_duplicated_as_the_base_of_a_new_one(): void
    {
        $this->actingAs($this->teacher);
        // Nom en minúscules, com els checks d'abans de la regla de les majúscules.
        $source = $this->check('echo "$HE_PARAM_IP"', [['name' => 'ip', 'label' => null, 'default' => '{{ip_dns}}']]);
        $count = Check::count();

        $create = Livewire::test(CreateCheck::class, ['copia' => $source->id])
            ->assertSchemaStateSet([
                'nom' => "{$source->nom} (còpia)",
                'tasca_id' => $source->tasca_id,
                'codi' => $source->currentVersion->codi,
            ]);
        $this->assertSame('IP', $create->get('data.parametres')[array_key_first($create->get('data.parametres'))]['name']);

        $create->call('create');
        $this->assertSame($count, Check::count(), 'la còpia també cal provar-la abans de desar');

        $create->callAction('provar', ['alumnes' => [(string) $this->students()->first()->id]])
            ->assertHasNoFormErrors()
            ->assertSet('testResults.0.estat', 'pass')
            ->call('create')
            ->assertHasNoFormErrors();
        $copy = Check::where('nom', "{$source->nom} (còpia)")->sole();
        $this->assertSame('IP', $copy->currentVersion->parametres[0]['name']);
        $this->assertSame($source->posicio + 1, $copy->posicio);
    }

    public function test_the_list_is_ordered_by_position(): void
    {
        $this->actingAs($this->teacher);
        $second = $this->check();
        $second->forceFill(['posicio' => 2, 'updated_at' => now()->subDay()])->saveQuietly();
        $first = $this->check();
        $first->forceFill(['posicio' => 1, 'updated_at' => now()->subWeek()])->saveQuietly();
        $task = $first->task;

        Livewire::test(ListChecks::class)
            ->assertCanSeeTableRecords([$first, $second], inOrder: true)
            ->assertTableColumnStateSet('posicio', "{$task->block->numero}.{$task->posicio}.1", $first)
            ->sortTable('updated_at', 'desc')
            ->assertCanSeeTableRecords([$second, $first], inOrder: true);
    }
}
