<?php

namespace Tests\Feature;

use App\Enums\ModuleKind;
use App\Enums\Role;
use App\Filament\Pages\LeaderboardPage;
use App\Filament\Pages\StudentTracking;
use App\Filament\Resources\Groups\Pages\ManageGroups;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Models\AcademicYear;
use App\Models\Group;
use App\Models\Module;
use App\Models\Student;
use App\Models\User;
use App\Support\Leaderboard;
use App\Support\StudentImporter;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Grups-classe: CRUD, assignar alumnes, i el filtre de grup al leaderboard i al seguiment. */
class GroupsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    private Group $smix2;

    private Group $other;

    private Student $moved;

    protected function setUp(): void
    {
        parent::setUp();

        $this->module = Module::where('codi', '9991')->firstOrFail();
        $this->smix2 = Group::where('codi', 'SMIX2')->firstOrFail();
        $this->other = Group::create(['curs_id' => $this->module->curs_id, 'codi' => 'SMIX1', 'nom' => '1r SMIX']);
        $this->moved = $this->module->students()->orderBy('num_taula')->firstOrFail();
        $this->moved->update(['grup_id' => $this->other->id]);

        $teacher = User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]);
        $teacher->modules()->attach($this->module);
        $this->actingAs($teacher);
    }

    public function test_the_student_seeder_puts_the_class_in_a_group(): void
    {
        $this->assertSame($this->module->students()->count() - 1, $this->smix2->students()->count());
    }

    public function test_groups_are_created_and_a_group_with_students_cannot_be_deleted(): void
    {
        Livewire::test(ManageGroups::class)
            ->callAction('create', ['curs_id' => $this->module->curs_id, 'codi' => 'ASIX1', 'nom' => '1r ASIX'])
            ->assertHasNoActionErrors()
            ->assertActionDisabled(TestAction::make('delete')->table($this->smix2));

        $this->assertDatabaseHas('grups', ['codi' => 'ASIX1', 'curs_id' => $this->module->curs_id]);

        Livewire::test(ManageGroups::class)
            ->callAction('create', ['curs_id' => $this->module->curs_id, 'codi' => 'ASIX1'])
            ->assertHasActionErrors(['codi' => 'unique']);
    }

    public function test_students_are_moved_to_a_group_in_bulk(): void
    {
        Livewire::test(ListStudents::class)
            ->selectTableRecords([$this->moved->id])
            ->callAction(TestAction::make('grup')->table()->bulk(), ['grup_id' => $this->smix2->id]);

        $this->assertSame($this->smix2->id, $this->moved->fresh()->grup_id);
    }

    public function test_the_importer_puts_the_list_in_the_group(): void
    {
        $rows = app(StudentImporter::class)->parse("xarxa,taula,alumne,email,alum\n198.51.100.240/28,Taula30,\"Nou, Alumne\",nou01@example.com,nou01\n");
        app(StudentImporter::class)->apply($this->module, $rows, deactivateMissing: false, groupId: $this->other->id);

        $this->assertSame($this->other->id, Student::where('usuari', 'nou01')->value('grup_id'));
    }

    public function test_the_leaderboard_is_filtered_by_group(): void
    {
        $board = app(Leaderboard::class)->build($this->module, groupId: $this->other->id);
        $this->assertSame([$this->moved->id], collect($board['rows'])->pluck('student.id')->all());

        Livewire::test(LeaderboardPage::class, ['grup' => $this->other->id])
            ->assertSee($this->moved->nom_complet)
            ->assertDontSee($this->smix2->students()->orderBy('num_taula')->first()->nom_complet);
    }

    public function test_the_public_leaderboard_lists_groups_and_only_their_modules(): void
    {
        $empty = Module::create(['curs_id' => $this->module->curs_id, 'cicle_id' => $this->module->cicle_id, 'codi' => '0000', 'nom' => 'Sense alumnes del grup', 'tipus' => ModuleKind::Free]);
        $empty->students()->attach($this->smix2->students()->first());

        $this->get('/leaderboard?grup='.$this->other->id)
            ->assertOk()
            ->assertSee('SMIX1 · 1r SMIX')
            ->assertSee($this->module->label())
            ->assertDontSee('Sense alumnes del grup');

        $this->get('/leaderboard')->assertOk()->assertSee('Sense alumnes del grup');
    }

    public function test_tracking_picks_group_then_module_then_student(): void
    {
        Livewire::test(StudentTracking::class, ['grup' => $this->other->id])
            ->assertSet('modul', $this->module->id)
            ->assertSet('alumne', $this->moved->id)
            ->assertSee('SMIX1 · 1r SMIX');

        $first = $this->smix2->students()->orderBy('num_taula')->firstOrFail();
        Livewire::test(StudentTracking::class, ['grup' => $this->other->id])
            ->set('grup', $this->smix2->id)
            ->assertSet('alumne', $first->id);

        // Un alumne a l'enllaç mana sobre un grup que no és el seu.
        Livewire::test(StudentTracking::class, ['grup' => $this->smix2->id, 'alumne' => $this->moved->id])
            ->assertSet('grup', null)
            ->assertSet('alumne', $this->moved->id);
    }

    public function test_groups_of_other_years_are_not_offered_publicly(): void
    {
        $old = AcademicYear::create(['nom' => '2025/26', 'data_inici' => '2025-09-01', 'data_fi' => '2026-08-31', 'es_actual' => false]);
        Group::create(['curs_id' => $old->id, 'codi' => 'VELL']);

        $this->get('/leaderboard')->assertOk()->assertDontSee('VELL');
    }
}
