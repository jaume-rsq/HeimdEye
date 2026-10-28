<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\AcademicYears\Pages\ManageAcademicYears;
use App\Filament\Resources\Cycles\Pages\ManageCycles;
use App\Filament\Resources\ModuleAdmin\Pages\CreateModule;
use App\Filament\Resources\ModuleAdmin\Pages\EditModule;
use App\Filament\Resources\ModuleAdmin\Pages\ListModuleAdmin;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Models\AcademicYear;
use App\Models\Cycle;
use App\Models\Module;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Administració: cursos, cicles, mòduls amb blocs i tasques, i alumnes. */
class AdministrationPanelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]));
    }

    public function test_only_one_course_is_current(): void
    {
        $old = AcademicYear::where('es_actual', true)->firstOrFail();

        Livewire::test(ManageAcademicYears::class)
            ->callAction('create', ['nom' => '2027/28', 'data_inici' => '2027-09-01', 'data_fi' => '2028-06-30', 'es_actual' => true])
            ->assertHasNoActionErrors();

        $this->assertFalse($old->fresh()->es_actual);
        $this->assertTrue(AcademicYear::where('nom', '2027/28')->value('es_actual'));
    }

    public function test_courses_and_cycles_in_use_cannot_be_deleted(): void
    {
        $year = AcademicYear::where('es_actual', true)->firstOrFail();
        Livewire::test(ManageAcademicYears::class)->assertActionDisabled(TestAction::make('delete')->table($year));

        $empty = Cycle::create(['codi' => 'DAW', 'nom' => 'Desenvolupament web']);
        Livewire::test(ManageCycles::class)
            ->assertActionDisabled(TestAction::make('delete')->table(Cycle::where('codi', 'SMIX')->first()))
            ->callAction(TestAction::make('delete')->table($empty));
        $this->assertModelMissing($empty);
    }

    public function test_module_is_created_with_blocks_and_tasks(): void
    {
        $component = Livewire::test(CreateModule::class)
            ->assertSet('data.curs_id', AcademicYear::where('es_actual', true)->value('id'))
            ->fillForm([
                'cicle_id' => Cycle::where('codi', 'SMIX')->value('id'),
                'tipus' => 'oficial',
                'codi' => '0226',
                'nom' => 'Seguretat informàtica',
            ]);
        $component->set('data.blocks', ['nou' => ['numero' => 1, 'titol' => 'Còpies', 'tasks' => ['t' => ['titol' => 'Backup', 'enunciat' => null]]]])
            ->call('create')
            ->assertHasNoFormErrors();

        $module = Module::where('codi', '0226')->firstOrFail();
        $this->assertSame('Backup', $module->blocks()->firstOrFail()->tasks()->firstOrFail()->titol);
    }

    public function test_blocks_with_checks_keep_their_delete_button_hidden(): void
    {
        $module = Module::where('codi', '9991')->firstOrFail();
        $withChecks = $module->blocks()->whereHas('tasks.checks')->firstOrFail();
        $empty = $module->blocks()->create(['numero' => 99, 'titol' => 'Buit', 'posicio' => 99]);

        $component = Livewire::test(EditModule::class, ['record' => $module->id]);
        $repeater = collect($component->instance()->form->getFlatComponents())->first(fn ($c) => $c->getKey() === 'form.blocks');
        $hidden = fn (int $id) => $repeater->getAction('delete')->arguments(['item' => "record-{$id}"])->isHidden();
        $this->assertTrue($hidden($withChecks->id));
        $this->assertFalse($hidden($empty->id));
        $component->assertActionDisabled('delete');

        $this->get('/admin/moduls-i-blocs')->assertOk()->assertSee('Mòduls i blocs');
        Livewire::test(ListModuleAdmin::class)->assertCanSeeTableRecords([$module]);
    }

    public function test_students_can_be_deactivated_in_bulk(): void
    {
        $students = Student::orderBy('num_taula')->limit(2)->get();

        Livewire::test(ListStudents::class)->selectTableRecords($students->modelKeys())->callAction(TestAction::make('desactivar')->table()->bulk());

        $this->assertSame(2, Student::whereKey($students->modelKeys())->where('es_actiu', false)->count());
    }

    public function test_students_can_be_deleted_in_bulk_after_typing_esborrar(): void
    {
        $students = Student::orderBy('num_taula')->limit(2)->get();
        $kept = Student::count() - 2;
        $action = TestAction::make('esborrar')->table()->bulk();

        Livewire::test(ListStudents::class)
            ->selectTableRecords($students->modelKeys())
            ->mountAction($action)
            ->assertSee($students[0]->nom_complet)
            ->setActionData(['confirmacio' => 'si'])
            ->callMountedAction()
            ->assertHasFormErrors(['confirmacio']);
        $this->assertSame($kept + 2, Student::count());

        Livewire::test(ListStudents::class)
            ->selectTableRecords($students->modelKeys())
            ->callAction($action, ['confirmacio' => 'esborrar'])
            ->assertHasNoFormErrors();
        $this->assertSame($kept, Student::count());
    }

    public function test_list_filters_are_kept_when_coming_back(): void
    {
        $module = Module::where('codi', '9991')->firstOrFail();

        Livewire::test(ListStudents::class)->filterTable('modul', $module->id);

        Livewire::test(ListStudents::class)->assertSet('tableFilters.modul.value', (string) $module->id);
    }
}
