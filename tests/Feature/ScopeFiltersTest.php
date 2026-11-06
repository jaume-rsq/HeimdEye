<?php

namespace Tests\Feature;

use App\Enums\ModuleKind;
use App\Enums\Role;
use App\Filament\Resources\Actions\Pages\ListActions;
use App\Filament\Resources\Checks\Pages\ListChecks;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Models\AcademicYear;
use App\Models\Group;
use App\Models\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Filtres encadenats: el curs limita els mòduls (i els grups) i el mòdul limita els blocs. */
class ScopeFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    private Module $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Admin, 'es_actiu' => true]));
        $this->module = Module::where('codi', '9991')->firstOrFail();
        $year = AcademicYear::create(['nom' => '2099/00', 'data_inici' => '2099-09-01', 'data_fi' => '2100-06-30', 'es_actual' => false]);
        $this->other = Module::create(['curs_id' => $year->id, 'cicle_id' => $this->module->cicle_id, 'codi' => '7777', 'nom' => 'Mòdul futur', 'tipus' => ModuleKind::Free]);
        $this->other->blocks()->create(['numero' => 1, 'titol' => 'Bloc del futur']);
        Group::create(['curs_id' => $year->id, 'codi' => 'FUTUR']);
    }

    private function filterOptions(Testable $page, string $filter): array
    {
        return $page->instance()->getTable()->getFilter($filter)->getOptions();
    }

    public function test_checks_course_limits_modules_and_module_limits_blocks(): void
    {
        $page = Livewire::test(ListChecks::class)->filterTable('curs', $this->module->curs_id);
        $this->assertArrayHasKey($this->module->id, $this->filterOptions($page, 'modul'));
        $this->assertArrayNotHasKey($this->other->id, $this->filterOptions($page, 'modul'));

        // Com el navegador: canvia només el valor del desplegable.
        $page->set('tableFilters.curs.value', $this->other->curs_id)
            ->assertSet('tableFilters.modul.value', null)
            ->assertSet('tableFilters.bloc.value', null);
        $this->assertSame([$this->other->id], array_keys($this->filterOptions($page, 'modul')));
        $this->assertSame($this->other->blocks()->pluck('id')->all(), array_keys($this->filterOptions($page, 'bloc')));

        $page->filterTable('curs', $this->module->curs_id)->filterTable('modul', $this->module->id);
        $this->assertEqualsCanonicalizing($this->module->blocks()->pluck('id')->all(), array_keys($this->filterOptions($page, 'bloc')));
    }

    public function test_actions_have_course_module_and_block(): void
    {
        $page = Livewire::test(ListActions::class)->filterTable('curs', $this->other->curs_id);
        $this->assertSame([$this->other->id], array_keys($this->filterOptions($page, 'modul_id')));
        $this->assertSame($this->other->blocks()->pluck('id')->all(), array_keys($this->filterOptions($page, 'bloc')));
    }

    public function test_students_course_limits_modules_and_groups(): void
    {
        $page = Livewire::test(ListStudents::class);
        $this->assertArrayHasKey($this->other->id, $this->filterOptions($page, 'modul'));

        $page->set('tableDeferredFilters.modul.value', $this->module->id)
            ->set('tableDeferredFilters.curs_id.value', $this->other->curs_id)
            ->assertSet('tableDeferredFilters.modul.value', null);
        $this->assertSame([$this->other->id], array_keys($this->filterOptions($page, 'modul')));
        $this->assertSame(['FUTUR'], Group::whereKey(array_keys($this->filterOptions($page, 'grup_id')))->pluck('codi')->all());
    }
}
