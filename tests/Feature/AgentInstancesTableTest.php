<?php

namespace Tests\Feature;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Enums\Role;
use App\Filament\Pages\AgentConsolePage;
use App\Filament\Resources\AgentInstances\Pages\ListAgentInstances;
use App\Models\AcademicYear;
use App\Models\AgentInstance;
use App\Models\Check;
use App\Models\ConsoleSession;
use App\Models\Module;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Vista d'instàncies a l'inventari: llista del curs actual, obrir consola i llançar-hi un check. */
class AgentInstancesTableTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $teacher;

    private Student $student;

    private AgentInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::create(['nom' => 'Prof', 'email' => 'prof@example.com', 'password' => 'secret', 'rol' => Role::Teacher, 'es_actiu' => true]);
        $this->student = Student::where('usuari', 'alumne01')->firstOrFail();
        $this->instance = AgentInstance::create(['alumne_id' => $this->student->id, 'hostname' => 'web', 'estat' => 'activa', 'ip_publica' => '203.0.113.5', 'versio_agent' => '1.2.0', 'darrer_batec' => now()]);
        $this->actingAs($this->teacher);
    }

    public function test_the_list_shows_current_course_instances_with_their_student(): void
    {
        Livewire::test(ListAgentInstances::class)
            ->assertCanSeeTableRecords([$this->instance])
            ->assertSee('web')
            ->assertSee($this->student->nom_complet);
    }

    public function test_instances_of_other_courses_are_hidden(): void
    {
        $otherYear = AcademicYear::create(['nom' => 'Curs vell', 'data_inici' => '2020-09-01', 'data_fi' => '2021-06-30', 'es_actual' => false]);
        $otherStudent = Student::create(['curs_id' => $otherYear->id, 'cicle_id' => $this->student->cicle_id, 'usuari' => 'vell01', 'nom_complet' => 'Alumne Vell', 'num_taula' => 1, 'x' => 0, 'es_actiu' => true]);
        $old = AgentInstance::create(['alumne_id' => $otherStudent->id, 'hostname' => 'old', 'estat' => 'activa', 'darrer_batec' => now()]);

        Livewire::test(ListAgentInstances::class)
            ->assertCanSeeTableRecords([$this->instance])
            ->assertCanNotSeeTableRecords([$old]);
    }

    public function test_opening_the_console_from_a_row_creates_a_pending_session(): void
    {
        Livewire::test(ListAgentInstances::class)
            ->callTableAction('consola', $this->instance);

        $session = ConsoleSession::sole();
        $this->assertSame('pendent', $session->estat);
        $this->assertSame($this->instance->id, $session->instancia_id);
        $this->assertSame($this->teacher->id, $session->obert_per);
    }

    public function test_open_for_reuses_an_already_open_session(): void
    {
        $first = AgentConsolePage::openFor($this->instance);
        $second = AgentConsolePage::openFor($this->instance);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ConsoleSession::count());
    }

    public function test_launching_an_agent_check_queues_it_for_the_student(): void
    {
        $check = $this->agentCheck();

        Livewire::test(ListAgentInstances::class)
            ->callTableAction('check', $this->instance, ['check' => $check->id]);

        $this->assertDatabaseHas('feines_agent', [
            'check_id' => $check->id,
            'alumne_id' => $this->student->id,
        ]);
    }

    public function test_a_teacher_can_delete_an_instance_from_the_list(): void
    {
        Livewire::test(ListAgentInstances::class)
            ->callTableAction('delete', $this->instance);

        $this->assertModelMissing($this->instance);
    }

    private function agentCheck(): Check
    {
        $module = Module::where('codi', '9991')->sole();
        $numero = ($module->blocks()->max('numero') ?? 89) + 1;
        $block = $module->blocks()->create(['numero' => $numero, 'titol' => 'Bloc agent', 'posicio' => $numero]);
        $task = $block->tasks()->create(['titol' => 'Tasca agent', 'posicio' => 1]);
        $check = $task->checks()->create([
            'nom' => 'Check agent', 'executor' => Executor::Agent, 'tipus' => CheckKind::Required,
            'punts' => 10, 'bonus_puntualitat_pct' => 0, 'es_actiu' => true, 'posicio' => 1,
        ]);
        $check->versions()->create(['versio' => 1, 'codi' => "# hosts: web\necho ok\n", 'temps_max_seg' => 30]);

        return $check->fresh();
    }
}
