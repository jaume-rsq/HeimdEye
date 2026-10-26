<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\Role;
use App\Enums\RunOrigin;
use App\Filament\Pages\StudentTracking;
use App\Jobs\RunCheck;
use App\Models\Check;
use App\Models\CheckRun;
use App\Models\CheckStudent;
use App\Models\Module;
use App\Models\RunBatch;
use App\Models\Student;
use App\Models\User;
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
            ->assertSee('corregit per Prof');

        $state = CheckStudent::where('check_id', $this->check->id)->where('alumne_id', $this->student->id)->firstOrFail();
        $this->assertTrue($state->estat_professor);
        $this->assertSame($this->teacher->id, $state->modificat_per);

        Livewire::test(StudentTracking::class, ['alumne' => $this->student->id])
            ->callAction('correct', ['estat' => 'auto', 'comentari' => ''], ['check' => $this->check->id]);
        $this->assertNull($state->fresh()->estat_professor);
        $this->assertNull($state->fresh()->comentari_professor);
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
}
