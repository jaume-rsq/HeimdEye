<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Enums\Role;
use App\Enums\RunOrigin;
use App\Filament\Resources\Actions\Pages\EditAction;
use App\Filament\Resources\Checks\Pages\EditCheck;
use App\Filament\Resources\Checks\Pages\ListChecks;
use App\Models\Block;
use App\Models\Check;
use App\Models\Module;
use App\Models\RunBatch;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Moure checks i accions a un altre lloc i canviar-ne l'executor (l'executor es desa a cada versió). */
class MoveAndExecutorTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    private Module $other;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->module = Module::where('codi', '9991')->firstOrFail();
        $this->other = $this->module->replicate()->fill(['codi' => '9992', 'nom' => 'Un altre mòdul']);
        $this->other->save();
        $this->teacher = User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]);
        $this->teacher->modules()->attach([$this->module->id, $this->other->id]);
        $this->actingAs($this->teacher);
    }

    private function task(Module $module): Task
    {
        $block = Block::create(['modul_id' => $module->id, 'numero' => 50, 'titol' => "Bloc de {$module->codi}", 'posicio' => 50]);

        return $block->tasks()->create(['titol' => "Tasca de {$module->codi}", 'posicio' => 1]);
    }

    private function check(Executor $executor, string $code = 'echo hola'): Check
    {
        $check = $this->task($this->module)->checks()->create(['nom' => 'Prova', 'executor' => $executor, 'es_actiu' => true]);
        $check->versions()->create(['versio' => 1, 'codi' => $code, 'temps_max_seg' => 10]);

        return $check->fresh();
    }

    public function test_a_version_takes_the_executor_of_its_check(): void
    {
        $check = $this->check(Executor::Ssh);

        $this->assertSame(Executor::Ssh, $check->currentVersion->executor);
    }

    public function test_changing_the_executor_needs_a_test_and_creates_a_new_version(): void
    {
        $check = $this->check(Executor::Ssh);
        $student = $this->module->students()->where('es_actiu', true)->firstOrFail();

        $edit = Livewire::test(EditCheck::class, ['record' => $check->id])
            ->fillForm(['executor' => 'local'])
            ->assertNotified('Revisa el codi')
            ->call('save');
        $this->assertSame(Executor::Ssh, $check->fresh()->executor, 'sense provar no es desa');

        $edit->callAction('provar', ['alumnes' => [(string) $student->id]])
            ->assertSet('testResults.0.estat', 'pass')
            ->call('save')
            ->assertHasNoFormErrors();

        $check->refresh();
        $this->assertSame(Executor::Local, $check->executor);
        $this->assertSame(2, $check->currentVersion->versio);
        $this->assertSame(Executor::Local, $check->currentVersion->executor);
        $this->assertSame(Executor::Ssh, $check->versions()->where('versio', 1)->first()->executor, 'les versions antigues no canvien');
    }

    public function test_the_new_executor_rules_are_checked_before_saving(): void
    {
        $this->module->secrets()->updateOrCreate(['clau' => 'jaume_password'], ['valor' => 'molt-secreta']);
        $check = $this->check(Executor::Local, 'echo "$HE_SECRET_JAUME_PASSWORD" | wc -c');

        Livewire::test(EditCheck::class, ['record' => $check->id])
            ->fillForm(['executor' => 'agent'])
            ->set('testedHash', 'qualsevol')
            ->call('save')
            ->assertNotified('El codi no es pot desar');

        $this->assertSame(Executor::Local, $check->fresh()->executor);
        $this->assertSame(1, $check->versions()->count());
    }

    public function test_a_check_moves_to_a_task_of_another_module_with_its_history(): void
    {
        $check = $this->check(Executor::Local);
        $student = $this->module->students()->firstOrFail();
        $batch = RunBatch::create(['modul_id' => $this->module->id, 'origen' => RunOrigin::Manual, 'data_inici' => now(), 'data_fi' => now()]);
        $check->runs()->create(['execucio_id' => $batch->id, 'versio_check_id' => $check->currentVersion->id, 'alumne_id' => $student->id, 'estat' => CheckStatus::Pass, 'data_execucio' => now()]);
        $target = $this->task($this->other);

        Livewire::test(EditCheck::class, ['record' => $check->id])
            ->assertSchemaStateSet(['curs_id' => $this->module->curs_id, 'modul_id' => $this->module->id])
            ->fillForm(['modul_id' => $this->other->id])
            ->assertNotified('Compte en moure-ho')
            ->assertSchemaStateSet(['tasca_id' => null])
            ->fillForm(['tasca_id' => $target->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $check->refresh();
        $this->assertSame($target->id, $check->tasca_id);
        $this->assertSame($this->other->id, $check->module->id);
        $this->assertSame(1, $check->versions()->count(), 'moure no crea cap versió');
        $this->assertSame(1, $check->runs()->count());
    }

    public function test_an_action_moves_and_changes_executor(): void
    {
        $action = $this->module->actions()->create(['nom' => 'Acció', 'executor' => Executor::Local, 'bloc_id' => $this->task($this->module)->bloc_id]);
        $action->versions()->create(['versio' => 1, 'codi' => 'echo hola', 'temps_max_seg' => 60]);

        Livewire::test(EditAction::class, ['record' => $action->id])
            ->fillForm(['modul_id' => $this->other->id])
            ->assertSchemaStateSet(['bloc_id' => null])
            ->call('save')
            ->assertHasNoFormErrors();
        $action->refresh();
        $this->assertSame($this->other->id, $action->modul_id);
        $this->assertNull($action->bloc_id);
        $this->assertSame(1, $action->versions()->count());

        Livewire::test(EditAction::class, ['record' => $action->id])
            ->fillForm(['executor' => 'ssh'])
            ->assertNotified('Revisa el codi')
            ->call('save')
            ->assertNotified('El codi no es pot desar'); // amb ssh cal la capçalera "# hosts:"
        $this->assertSame(Executor::Local, $action->fresh()->executor);
        $this->assertSame(1, $action->versions()->count());
    }

    public function test_the_checks_list_filters_by_task_of_the_chosen_block(): void
    {
        $task = $this->task($this->module);
        $sibling = $task->block->tasks()->create(['titol' => 'Una altra tasca', 'posicio' => 2]);
        $mine = $task->checks()->create(['nom' => 'De la tasca', 'executor' => Executor::Local, 'es_actiu' => true]);
        $theirs = $sibling->checks()->create(['nom' => 'De l\'altra', 'executor' => Executor::Local, 'es_actiu' => true]);

        Livewire::test(ListChecks::class)
            ->filterTable('bloc', $task->bloc_id)
            ->assertCanSeeTableRecords([$mine, $theirs])
            ->filterTable('tasca', $task->id)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }
}
