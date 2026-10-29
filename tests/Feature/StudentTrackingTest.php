<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\Role;
use App\Enums\RunOrigin;
use App\Filament\Pages\StudentTracking;
use App\Filament\Resources\Checks\CheckResource;
use App\Jobs\RunCheck;
use App\Models\Check;
use App\Models\CheckRun;
use App\Models\CheckStudent;
use App\Models\Module;
use App\Models\RunBatch;
use App\Models\Student;
use App\Models\User;
use App\Support\CheckSummary;
use App\Support\CodePreview;
use App\Support\StudentProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Seguiment d'un alumne: mòduls visibles, checks agrupats, canvis d'estat, verificar i corregir. */
class StudentTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    private Student $student;

    private Check $check;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->module = Module::where('codi', '9991')->firstOrFail();
        $this->student = $this->module->students()->orderBy('num_taula')->firstOrFail();
        $this->check = Check::whereIn('tasca_id', $this->module->tasks()->select('tasques.id'))->orderBy('id')->firstOrFail();
        $this->teacher = User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]);
        $this->teacher->modules()->attach($this->module);
        $this->actingAs($this->teacher);
    }

    private function addRun(CheckStatus $status, string $when): void
    {
        $batch = RunBatch::create(['modul_id' => $this->module->id, 'origen' => RunOrigin::Nightly, 'data_inici' => $when]);
        CheckRun::create([
            'execucio_id' => $batch->id, 'check_id' => $this->check->id, 'versio_check_id' => $this->check->currentVersion->id,
            'alumne_id' => $this->student->id, 'estat' => $status, 'missatge' => "estat {$status->value}", 'data_execucio' => $when,
        ]);
    }

    public function test_history_keeps_only_status_changes(): void
    {
        $this->addRun(CheckStatus::Fail, '2026-10-20 03:00');
        $this->addRun(CheckStatus::Fail, '2026-10-21 03:00');
        $this->addRun(CheckStatus::Pass, '2026-10-22 03:00');
        $this->addRun(CheckStatus::Pass, '2026-10-23 03:00');
        $this->addRun(CheckStatus::Pass, '2026-10-24 03:00');

        $groups = app(StudentProgress::class)->build($this->student, $this->module, ['tasca' => $this->check->tasca_id]);
        $row = collect($groups[0]['tasks'][0]['rows'])->firstWhere('check.id', $this->check->id);

        $this->assertSame(5, $row['execucions']);
        $this->assertSame(['pass', 'fail'], array_map(fn ($c) => $c['estat']->value, $row['canvis']));
        $this->assertSame([3, 2], array_column($row['canvis'], 'execucions'));
        $this->assertSame('2026-10-22', $row['canvis'][0]['data']->toDateString());
        $this->assertSame('estat pass', $row['darrera']->missatge);
    }

    public function test_teachers_only_see_the_modules_they_teach(): void
    {
        Livewire::test(StudentTracking::class)
            ->assertSet('alumne', $this->student->id)
            ->assertSet('modul', $this->module->id)
            ->assertSee($this->student->nom_complet)
            ->assertSee($this->check->nom);

        $this->teacher->modules()->detach();
        Livewire::test(StudentTracking::class)->assertSee('No hi ha cap alumne en els teus mòduls.');
    }

    public function test_teacher_correction_counts_on_the_leaderboard(): void
    {
        Livewire::test(StudentTracking::class, ['alumne' => $this->student->id])
            ->callAction('correct', ['estat' => 'assolit', 'comentari' => 'Revisat a classe'], ['check' => $this->check->id])
            ->assertHasNoActionErrors()
            ->assertSee('Revisat a classe')
            ->assertSee('✎ Prof');

        $state = CheckStudent::where('check_id', $this->check->id)->where('alumne_id', $this->student->id)->firstOrFail();
        $this->assertTrue($state->estat_professor);
        $this->assertSame($this->teacher->id, $state->modificat_per);

        Livewire::test(StudentTracking::class, ['alumne' => $this->student->id])
            ->callAction('correct', ['estat' => 'auto', 'comentari' => ''], ['check' => $this->check->id]);
        $this->assertNull($state->fresh()->estat_professor);
        $this->assertNull($state->fresh()->comentari_professor);
    }

    public function test_teacher_correction_shows_in_the_history_and_in_the_check_summary(): void
    {
        $this->addRun(CheckStatus::Fail, '2026-10-20 03:00');
        $this->assertSame(1, CheckSummary::for($this->check)->counts['fail'] ?? 0);

        Livewire::test(StudentTracking::class, ['alumne' => $this->student->id])
            ->callAction('correct', ['estat' => 'assolit', 'comentari' => 'Revisat a classe'], ['check' => $this->check->id])
            ->assertSee('correcció de Prof');

        $groups = app(StudentProgress::class)->build($this->student, $this->module, ['tasca' => $this->check->tasca_id]);
        $row = collect($groups[0]['tasks'][0]['rows'])->firstWhere('check.id', $this->check->id);
        $this->assertSame('Prof', $row['canvis'][0]['professor']);
        $this->assertSame(CheckStatus::Pass, $row['canvis'][0]['estat']);
        $this->assertSame(CheckStatus::Fail, $row['canvis'][1]['estat']);

        // La correcció mana sobre la darrera execució: compta com a assolit, no com a fail.
        $summary = CheckSummary::for($this->check);
        $this->assertSame(1, $summary->counts['pass'] ?? 0);
        $this->assertArrayNotHasKey('fail', $summary->counts);
        $this->assertTrue(collect(CheckSummary::students($this->check))->firstWhere('id', $this->student->id)['correccio']);

        $this->get(CheckResource::getUrl('runs', ['record' => $this->check->id]))
            ->assertOk()
            ->assertSee('Correccions del professor')
            ->assertSee('Revisat a classe');
    }

    public function test_skipped_runs_are_shown_and_verify_refreshes_by_itself(): void
    {
        $this->addRun(CheckStatus::Skipped, '2026-10-20 03:00');

        Livewire::test(StudentTracking::class, ['alumne' => $this->student->id, 'tasca' => $this->check->tasca_id])
            ->assertSee('estat omes');
        $this->assertSame(CheckStatus::Skipped, collect(CheckSummary::students($this->check))->firstWhere('id', $this->student->id)['estat']);

        Queue::fake();
        $component = Livewire::test(StudentTracking::class, ['alumne' => $this->student->id])
            ->call('verify', null, null, $this->check->task->bloc_id)
            ->assertSee('es posa al dia sola');
        Queue::assertPushed(RunCheck::class, Check::whereIn('tasca_id', $this->check->task->block->tasks()->select('id'))->count());

        RunBatch::query()->update(['data_fi' => now()]);
        $component->call('refreshPending')->assertSet('pending', []);
    }

    public function test_verify_queues_the_checks_of_a_task(): void
    {
        Queue::fake();

        Livewire::test(StudentTracking::class, ['alumne' => $this->student->id])->call('verify', null, $this->check->tasca_id);

        $expected = Check::where('tasca_id', $this->check->tasca_id)->count();
        Queue::assertPushed(RunCheck::class, $expected);
        Queue::assertPushed(RunCheck::class, fn (RunCheck $job) => $job->student->is($this->student));
    }

    public function test_task_in_the_url_selects_its_module_and_filters(): void
    {
        $other = Check::whereIn('tasca_id', $this->module->tasks()->select('tasques.id'))->where('tasca_id', '!=', $this->check->tasca_id)->first();

        $component = Livewire::test(StudentTracking::class, ['alumne' => $this->student->id, 'tasca' => $this->check->tasca_id])
            ->assertSet('modul', $this->module->id)
            ->assertSee($this->check->nom);
        if ($other) {
            $component->assertDontSee($other->nom);
        }
    }

    public function test_block_summary_counts_every_check_and_rows_follow_the_position(): void
    {
        CheckStudent::create(['check_id' => $this->check->id, 'alumne_id' => $this->student->id, 'estat_professor' => true]);
        $task = $this->check->task;
        $last = $task->checks()->create(['nom' => 'Darrer', 'executor' => $this->check->executor, 'es_actiu' => true, 'posicio' => 99]);
        $first = $task->checks()->create(['nom' => 'Primer', 'executor' => $this->check->executor, 'es_actiu' => true, 'posicio' => 0]);

        $groups = app(StudentProgress::class)->build($this->student, $this->module, ['tasca' => $task->id, 'estat' => 'pendents']);
        $block = collect($groups)->firstWhere('block.id', $task->bloc_id);
        $all = Check::where('tasca_id', $task->id)->orWhereHas('task', fn ($q) => $q->where('bloc_id', $task->bloc_id))->get()
            ->filter(fn (Check $c) => $c->isActiveOn());

        // El filtre amaga l'assolit, però el resum del bloc el compta.
        $this->assertSame(1, $block['resum']['assolits']);
        $this->assertSame($all->count(), $block['resum']['total']);
        $names = array_map(fn ($r) => $r['check']->nom, $block['tasks'][0]['rows']);
        $this->assertSame('Primer', $names[0]);
        $this->assertSame('Darrer', end($names));
        $this->assertSame("{$task->block->numero}.{$task->posicio}.99", $last->load('task.block')->positionLabel());
    }

    public function test_code_preview_uses_the_student_data_but_never_secrets(): void
    {
        $this->check->versions()->create([
            'versio' => 99,
            'codi' => 'echo $HE_USUARI ${HE_PARAM_DESTI} $HE_SECRET_CLAU $HE_NO_EXISTEIX',
            'parametres' => [['name' => 'DESTI', 'label' => null, 'default' => 'ns1.{{ usuari }}']],
            'temps_max_seg' => 10,
        ]);
        $check = $this->check->fresh(['currentVersion', 'task.block.module']);

        $preview = app(CodePreview::class)->for($check, $this->student);

        $this->assertSame("echo {$this->student->usuari} ns1.{$this->student->usuari} \$HE_SECRET_CLAU \$HE_NO_EXISTEIX", $preview['codi']);
    }

    public function test_info_popup_shows_the_markdown_and_the_code(): void
    {
        $this->check->update(['info' => "**Objectiu del test:** que el router respon.\n\n<script>alert(1)</script>"]);

        Livewire::test(StudentTracking::class)
            ->mountAction('info', ['check' => $this->check->id])
            ->assertActionMounted('info')
            ->assertMountedActionModalSeeHtml('<strong>Objectiu del test:</strong>')
            ->assertMountedActionModalDontSeeHtml('<script>alert(1)</script>')
            ->assertMountedActionModalSee('Codi del check amb les teves dades');
    }

    public function test_the_edit_icon_is_only_for_who_can_edit_the_check(): void
    {
        $editUrl = CheckResource::getUrl('edit', ['record' => $this->check->id]);

        Livewire::test(StudentTracking::class, ['alumne' => $this->student->id])->assertSee($editUrl, false);

        // Un professor que no imparteix el mòdul no el pot editar (aquí, sense mòduls: un admin el veu tot).
        $this->teacher->modules()->detach();
        $admin = User::create(['nom' => 'Admin', 'email' => 'admin2@example.com', 'password' => 'secret', 'rol' => Role::Admin, 'es_actiu' => true]);
        $component = Livewire::actingAs($admin)->test(StudentTracking::class, ['alumne' => $this->student->id]);
        $this->assertTrue($component->instance()->canEdit($this->check));
        $this->assertFalse($this->teacher->canEditCheck($this->check));
    }
}
