<?php

namespace Tests\Feature;

use App\Enums\Executor;
use App\Enums\Role;
use App\Filament\Resources\Actions\Pages\ListActions;
use App\Filament\Resources\CheckRuns\Pages\ListCheckRuns;
use App\Filament\Resources\Checks\Pages\ListChecks;
use App\Models\Block;
use App\Models\Module;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Els desplegables encadenats de les llistes són coherents i l'àmbit (curs, grup, mòdul) es comparteix. */
class ScopeFiltersCoherenceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    private Module $other;

    private Task $task;

    private Task $otherTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->module = Module::where('codi', '9991')->firstOrFail();
        $this->other = $this->module->replicate()->fill(['codi' => '9992', 'nom' => 'Un altre mòdul']);
        $this->other->save();
        $this->task = $this->taskIn($this->module);
        $this->otherTask = $this->taskIn($this->other);
        foreach ([$this->task, $this->otherTask] as $task) {
            $task->checks()->create(['nom' => "Check de {$task->titol}", 'executor' => Executor::Local, 'es_actiu' => true]);
        }

        $admin = User::create(['nom' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret', 'rol' => Role::Admin, 'es_actiu' => true]);
        $this->actingAs($admin);
    }

    private function taskIn(Module $module): Task
    {
        $block = Block::create(['modul_id' => $module->id, 'numero' => 40, 'titol' => "Bloc de {$module->codi}", 'posicio' => 40]);

        return $block->tasks()->create(['titol' => "Tasca de {$module->codi}", 'posicio' => 1]);
    }

    public function test_removing_a_parent_filter_clears_its_children(): void
    {
        Livewire::test(ListChecks::class)
            ->filterTable('modul', $this->module->id)
            ->filterTable('bloc', $this->task->bloc_id)
            ->filterTable('tasca', $this->task->id)
            ->removeTableFilter('modul')
            ->assertSet('tableFilters.bloc.value', null)
            ->assertSet('tableFilters.tasca.value', null);
    }

    public function test_changing_a_parent_in_the_filter_form_clears_its_children(): void
    {
        Livewire::test(ListChecks::class)
            ->filterTable('modul', $this->module->id)
            ->filterTable('bloc', $this->task->bloc_id)
            ->filterTable('tasca', $this->task->id)
            ->set('tableDeferredFilters.modul.value', (string) $this->other->id)
            ->assertSet('tableDeferredFilters.bloc.value', null)
            ->assertSet('tableDeferredFilters.tasca.value', null);
    }

    public function test_the_module_chosen_in_one_list_is_the_one_in_the_next(): void
    {
        Livewire::test(ListChecks::class)
            ->filterTable('modul', $this->other->id);
        $this->assertSame($this->other->id, session('heimdeye.ambit')['modul']);

        Livewire::test(ListActions::class)
            ->assertSet('tableFilters.modul_id.value', (string) $this->other->id);
    }

    public function test_a_remembered_module_drops_a_block_of_another_module(): void
    {
        // El llistat de checks recordava un bloc del mòdul 9991; al seguiment s'ha triat el 9992.
        Livewire::test(ListChecks::class)
            ->filterTable('modul', $this->module->id)
            ->filterTable('bloc', $this->task->bloc_id);
        session(['heimdeye.ambit' => ['curs' => $this->other->curs_id, 'grup' => null, 'modul' => $this->other->id]]);

        Livewire::test(ListChecks::class)
            ->assertSet('tableFilters.modul.value', (string) $this->other->id)
            ->assertSet('tableFilters.bloc.value', null);
    }

    public function test_with_a_group_the_module_and_student_options_are_those_of_the_group(): void
    {
        $student = $this->module->students()->whereNotNull('grup_id')->firstOrFail();
        $outsider = $this->module->students()->where('grup_id', '!=', $student->grup_id)->first();

        $list = Livewire::test(ListCheckRuns::class)
            ->set('tableDeferredFilters.grup.value', (string) $student->grup_id);
        $table = $list->instance()->getTable();

        $this->assertArrayHasKey($this->module->id, $table->getFilter('modul')->getOptions());
        $this->assertArrayNotHasKey($this->other->id, $table->getFilter('modul')->getOptions(), 'el 9992 no té alumnes del grup');
        $this->assertArrayHasKey($student->id, $table->getFilter('alumne_id')->getOptions());
        if ($outsider) {
            $this->assertArrayNotHasKey($outsider->id, $table->getFilter('alumne_id')->getOptions());
        }
    }
}
