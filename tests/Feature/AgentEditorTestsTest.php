<?php

namespace Tests\Feature;

use App\Enums\CheckKind;
use App\Enums\Executor;
use App\Jobs\RunEditorTest;
use App\Models\AgentEditorTest;
use App\Models\AgentInstance;
use App\Models\Module;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Botó Provar de l'editor amb l'executor agent: la prova la recull la instància activa de l'alumne. */
class AgentEditorTestsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['heimdeye.agent.poll_hold_seconds' => 0]);
    }

    private function student(): Student
    {
        return Student::where('usuari', 'alumne01')->firstOrFail();
    }

    private function draftActionTest(string $key, string $code = "# hosts: web\necho \"pve=\$HE_IP_PVE\"\n"): RunEditorTest
    {
        return new RunEditorTest(
            $key,
            'Execució de l\'esborrany',
            $this->student()->id,
            action: ['id' => null, 'modul_id' => Module::where('codi', '9991')->sole()->id, 'nom' => 'Esborrany', 'executor' => Executor::Agent->value],
            version: ['codi' => $code, 'temps_max_seg' => 30, 'parametres' => null],
        );
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->student()->regenerateAgentToken()];
    }

    public function test_without_an_active_instance_the_test_fails_at_once(): void
    {
        dispatch_sync($this->draftActionTest('k1'));

        $result = Cache::get(RunEditorTest::cacheKey('k1'));
        $this->assertSame('error', $result['estat']);
        $this->assertStringContainsString('cap instància activa amb el rol web', $result['resum']);
        $this->assertSame(0, AgentEditorTest::count());
    }

    public function test_a_draft_action_is_picked_up_by_the_agent_and_its_result_reaches_the_page(): void
    {
        $headers = $this->headers();
        $this->postJson('/api/agent/register', ['hostname' => 'web'], $headers)->assertOk();

        dispatch_sync($this->draftActionTest('k2'));
        $this->assertNull(Cache::get(RunEditorTest::cacheKey('k2')));

        $poll = $this->getJson('/api/agent/poll?hostname=web', $headers)->assertOk();
        $taskId = $poll->json('task.id');
        $this->assertMatchesRegularExpression('/^prova:\d+$/', $taskId);
        $this->assertSame('198.51.100.19', $poll->json('task.env.HE_IP_PVE'));

        $this->postJson('/api/agent/result', ['task_id' => $taskId, 'exit_code' => 0, 'output' => "pve=198.51.100.19\nHEIMDEYE_MSG: fet"], $headers)
            ->assertOk()->assertJson(['ok' => true]);

        $result = Cache::get(RunEditorTest::cacheKey('k2'));
        $this->assertSame('ok', $result['estat']);
        $this->assertSame('fet', $result['resum']);
        $this->assertStringContainsString('198.51.100.19', $result['comanda']);
        $this->assertSame(0, AgentEditorTest::count());
    }

    public function test_a_draft_agent_check_maps_the_exit_code(): void
    {
        $headers = $this->headers();
        $this->postJson('/api/agent/register', ['hostname' => 'web'], $headers)->assertOk();
        $block = Module::where('codi', '9991')->sole()->blocks()->create(['numero' => 90, 'titol' => 'Bloc agent', 'posicio' => 90]);
        $task = $block->tasks()->create(['titol' => 'Tasca agent', 'posicio' => 1]);

        dispatch_sync(new RunEditorTest(
            'k3',
            'Prova de l\'esborrany',
            $this->student()->id,
            version: ['codi' => "# hosts: web\nexit 1\n", 'temps_max_seg' => 20, 'parametres' => null],
            check: ['id' => null, 'tasca_id' => $task->id, 'nom' => 'Esborrany', 'executor' => Executor::Agent->value, 'tipus' => CheckKind::Required->value],
        ));

        $taskId = $this->getJson('/api/agent/poll?hostname=web', $headers)->json('task.id');
        $this->postJson('/api/agent/result', ['task_id' => $taskId, 'exit_code' => 1, 'output' => 'HEIMDEYE_MSG: res no escolta'], $headers)->assertOk();

        $result = Cache::get(RunEditorTest::cacheKey('k3'));
        $this->assertSame('fail', $result['estat']);
        $this->assertSame('res no escolta', $result['resum']);
    }

    public function test_an_unclaimed_test_expires_as_unavailable(): void
    {
        AgentInstance::create(['alumne_id' => $this->student()->id, 'hostname' => 'web', 'estat' => 'activa', 'darrer_batec' => now()]);
        dispatch_sync($this->draftActionTest('k4'));
        AgentEditorTest::query()->update(['caduca_a' => now()->subMinute()]);

        Artisan::call('heimdeye:agent-prune');

        $result = Cache::get(RunEditorTest::cacheKey('k4'));
        $this->assertSame('caducada', $result['estat']);
        $this->assertStringContainsString('a temps', $result['resum']);
    }
}
