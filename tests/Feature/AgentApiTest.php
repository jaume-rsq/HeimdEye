<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Enums\CheckKind;
use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Models\Action;
use App\Models\ActionRun;
use App\Models\AgentInstance;
use App\Models\AgentTask;
use App\Models\Check;
use App\Models\CheckRun;
use App\Models\CheckStudent;
use App\Models\Module;
use App\Models\Student;
use App\Runner\ActionRunner;
use App\Runner\CheckRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AgentApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        // Que el long poll no bloquegi els tests quan no hi ha feina.
        config(['heimdeye.agent.poll_hold_seconds' => 0]);
    }

    private function agentAction(string $code = "# hosts: web\necho \"pve=\$HE_IP_PVE\"\n"): Action
    {
        $action = Action::create([
            'modul_id' => Module::where('codi', '9991')->sole()->id,
            'nom' => 'Injectar incidència',
            'executor' => Executor::Agent,
            'posicio' => 1,
        ]);
        $action->versions()->create(['versio' => 1, 'codi' => $code, 'temps_max_seg' => 30]);

        return $action->fresh();
    }

    private function agentCheck(string $code = "# hosts: web\necho \"pve=\$HE_IP_PVE\"\n"): Check
    {
        $module = Module::where('codi', '9991')->sole();
        $numero = ($module->blocks()->max('numero') ?? 89) + 1;
        $block = $module->blocks()->create(['numero' => $numero, 'titol' => 'Bloc agent', 'posicio' => $numero]);
        $task = $block->tasks()->create(['titol' => 'Tasca agent', 'posicio' => 1]);
        $check = $task->checks()->create([
            'nom' => 'Check agent', 'executor' => Executor::Agent, 'tipus' => CheckKind::Required,
            'punts' => 10, 'bonus_puntualitat_pct' => 0, 'es_actiu' => true, 'posicio' => 1,
        ]);
        $check->versions()->create(['versio' => 1, 'codi' => $code, 'temps_max_seg' => 30]);

        return $check->fresh();
    }

    private function student(string $username = 'alumne01'): Student
    {
        return Student::where('usuari', $username)->firstOrFail();
    }

    public function test_launching_an_agent_action_queues_one_pending_job_per_rol(): void
    {
        $action = $this->agentAction();
        $student = $this->student();

        app(ActionRunner::class)->launch($action, collect([$student]));

        $run = ActionRun::where('accio_id', $action->id)->sole();
        $this->assertSame(ActionStatus::Pendent, $run->estat);
        $this->assertSame('web', $run->rol_agent);
        $this->assertNull($run->recollit_a);
        $this->assertSame("# hosts: web\necho \"pve=\$HE_IP_PVE\"\n", $run->payload_agent['code']);
        $this->assertSame('198.51.100.19', $run->payload_agent['env']['HE_IP_PVE']);
    }

    public function test_the_full_pull_cycle_register_poll_result(): void
    {
        $action = $this->agentAction();
        $student = $this->student();
        $token = $student->regenerateAgentToken();
        app(ActionRunner::class)->launch($action, collect([$student]));

        $headers = ['Authorization' => 'Bearer '.$token];

        $this->postJson('/api/agent/register', ['hostname' => 'web', 'ip_publica' => '1.2.3.4', 'sistema' => 'Ubuntu 24.04', 'versio' => '1.0'], $headers)
            ->assertOk()->assertJson(['ok' => true]);

        $instance = AgentInstance::where('alumne_id', $student->id)->where('hostname', 'web')->sole();
        $this->assertSame('activa', $instance->estat);
        $this->assertSame('1.2.3.4', $instance->ip_publica);

        $poll = $this->getJson('/api/agent/poll?hostname=web', $headers)->assertOk();
        $taskId = $poll->json('task.id');
        $this->assertNotNull($taskId);
        $this->assertStringContainsString('pve=', $poll->json('task.code'));
        $this->assertSame('198.51.100.19', $poll->json('task.env.HE_IP_PVE'));

        // Recollida: ja no la torna a donar.
        $this->getJson('/api/agent/poll?hostname=web', $headers)->assertOk()->assertJson(['task' => null]);

        $this->postJson('/api/agent/result', ['task_id' => $taskId, 'exit_code' => 0, 'output' => "pve=198.51.100.19\nHEIMDEYE_MSG: fet"], $headers)
            ->assertOk()->assertJson(['ok' => true]);

        $run = ActionRun::find($taskId);
        $this->assertSame(ActionStatus::Ok, $run->estat);
        $this->assertNotNull($run->data_fi);
        $this->assertStringContainsString('fet', $run->sortida);
    }

    public function test_a_non_zero_exit_is_a_failure_and_omes_is_unavailable(): void
    {
        $student = $this->student();
        $token = $student->regenerateAgentToken();
        $headers = ['Authorization' => 'Bearer '.$token];

        foreach ([['exit' => 2, 'output' => 'ko', 'status' => ActionStatus::Failed], ['exit' => 0, 'output' => 'HEIMDEYE_OMES: apagada', 'status' => ActionStatus::Unavailable]] as $case) {
            $action = $this->agentAction();
            app(ActionRunner::class)->launch($action, collect([$student]));
            $taskId = $this->getJson('/api/agent/poll?hostname=web', $headers)->json('task.id');
            $this->postJson('/api/agent/result', ['task_id' => $taskId, 'exit_code' => $case['exit'], 'output' => $case['output']], $headers)->assertOk();
            $this->assertSame($case['status'], ActionRun::find($taskId)->estat);
        }
    }

    public function test_launching_an_agent_check_queues_one_feina_per_rol(): void
    {
        $check = $this->agentCheck();
        $student = $this->student();

        app(CheckRunner::class)->launch($check, collect([$student]));

        $feina = AgentTask::where('check_id', $check->id)->sole();
        $this->assertSame('pendent', $feina->estat);
        $this->assertSame('web', $feina->rol_agent);
        $this->assertNull($feina->recollit_a);
        $this->assertSame('198.51.100.19', $feina->payload_agent['env']['HE_IP_PVE']);
        // No s'ha desat cap execució encara: només queda la feina pendent per a l'agent.
        $this->assertSame(0, CheckRun::where('check_id', $check->id)->count());
    }

    public function test_the_full_check_pull_cycle_records_a_pass(): void
    {
        $check = $this->agentCheck();
        $student = $this->student();
        $token = $student->regenerateAgentToken();
        app(CheckRunner::class)->launch($check, collect([$student]));

        $headers = ['Authorization' => 'Bearer '.$token];
        $this->postJson('/api/agent/register', ['hostname' => 'web'], $headers)->assertOk();

        $poll = $this->getJson('/api/agent/poll?hostname=web', $headers)->assertOk();
        $taskId = $poll->json('task.id');
        $this->assertStringStartsWith('check:', (string) $taskId);
        $this->assertSame('198.51.100.19', $poll->json('task.env.HE_IP_PVE'));

        // Recollida: ja no la torna a donar.
        $this->getJson('/api/agent/poll?hostname=web', $headers)->assertOk()->assertJson(['task' => null]);

        $this->postJson('/api/agent/result', ['task_id' => $taskId, 'exit_code' => 0, 'output' => "pve=198.51.100.19\nHEIMDEYE_MSG: fet", 'duration_ms' => 42], $headers)
            ->assertOk()->assertJson(['ok' => true]);

        $run = CheckRun::where('check_id', $check->id)->sole();
        $this->assertSame(CheckStatus::Pass, $run->estat);
        $this->assertSame('fet', $run->missatge);
        $this->assertSame(42, $run->durada_ms);

        $state = CheckStudent::where('check_id', $check->id)->where('alumne_id', $student->id)->sole();
        $this->assertSame(CheckStatus::Pass, $state->estat_actual);
        $this->assertNotNull($state->data_primer_assoliment);

        // La feina queda tancada.
        $this->assertSame('feta', AgentTask::where('check_id', $check->id)->sole()->estat);
    }

    public function test_check_result_maps_fail_omes_and_timeout(): void
    {
        $student = $this->student();
        $token = $student->regenerateAgentToken();
        $headers = ['Authorization' => 'Bearer '.$token];

        $cases = [
            ['exit' => 1, 'output' => 'ko', 'timed_out' => false, 'status' => CheckStatus::Fail],
            ['exit' => 0, 'output' => 'HEIMDEYE_OMES: apagada', 'timed_out' => false, 'status' => CheckStatus::Skipped],
            ['exit' => 124, 'output' => 'lent', 'timed_out' => true, 'status' => CheckStatus::Timeout],
        ];
        foreach ($cases as $case) {
            $check = $this->agentCheck();
            app(CheckRunner::class)->launch($check, collect([$student]));
            $taskId = $this->getJson('/api/agent/poll?hostname=web', $headers)->json('task.id');
            $this->postJson('/api/agent/result', ['task_id' => $taskId, 'exit_code' => $case['exit'], 'output' => $case['output'], 'timed_out' => $case['timed_out']], $headers)->assertOk();
            $this->assertSame($case['status'], CheckRun::where('check_id', $check->id)->sole()->estat);
        }
    }

    public function test_prune_expires_a_pending_check_as_unavailable(): void
    {
        $check = $this->agentCheck();
        $student = $this->student();
        app(CheckRunner::class)->launch($check, collect([$student]));
        AgentTask::where('check_id', $check->id)->update(['caduca_a' => now()->subMinute()]);

        Artisan::call('heimdeye:agent-prune');

        $this->assertSame('caducada', AgentTask::where('check_id', $check->id)->sole()->estat);
        $this->assertSame(CheckStatus::Unavailable, CheckRun::where('check_id', $check->id)->sole()->estat);
    }

    public function test_requests_without_a_valid_token_are_rejected(): void
    {
        $this->postJson('/api/agent/register', ['hostname' => 'web'])->assertUnauthorized();
        $this->postJson('/api/agent/register', ['hostname' => 'web'], ['Authorization' => 'Bearer dolent'])->assertUnauthorized();
    }

    public function test_prune_marks_instances_inactive_and_expires_work(): void
    {
        $action = $this->agentAction();
        $student = $this->student();
        app(ActionRunner::class)->launch($action, collect([$student]));

        // Feina que ha passat la seva finestra de caducitat.
        ActionRun::where('accio_id', $action->id)->update(['caduca_a' => now()->subMinute()]);
        // Instància que fa estona que no bat.
        $instance = AgentInstance::create(['alumne_id' => $student->id, 'hostname' => 'web', 'estat' => 'activa', 'darrer_batec' => now()->subHour()]);

        Artisan::call('heimdeye:agent-prune');

        $this->assertSame('inactiva', $instance->fresh()->estat);
        $this->assertSame(ActionStatus::Caducada, ActionRun::where('accio_id', $action->id)->sole()->estat);
    }
}
