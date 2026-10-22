<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Models\Action;
use App\Models\ActionRun;
use App\Models\Module;
use App\Models\Student;
use App\Models\Task;
use App\Runner\ActionRunner;
use App\Runner\CheckRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Tests\TestCase;

/** Proves amb Ansible real contra equips amb connexió local. */
class AnsibleTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Module $module;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_executable(trim((string) shell_exec('command -v ansible-playbook')))) {
            $this->markTestSkipped('ansible-playbook no està instal·lat');
        }

        $this->module = Module::where('code', '9991')->firstOrFail();
        $this->module->hostTypes()->create([
            'name' => 'local',
            'ansible_group' => 'locals',
            'host_name' => 'local{{num_taula}}',
            'address' => '127.0.0.1',
            'connection_vars' => ['ansible_connection' => 'local', 'ansible_python_interpreter' => 'auto_silent'],
        ]);
    }

    private function action(string $playbook, array $params = []): Action
    {
        $action = $this->module->actions()->create(['name' => 'Prova', 'executor' => Executor::Ansible, 'params' => $params, 'timeout_s' => 60]);
        $action->versions()->create(['version' => 1, 'code' => $playbook]);

        return $action->fresh();
    }

    /** @return Collection<int, Student> */
    private function students(): Collection
    {
        return Student::whereIn('username', ['alumne01', 'alumne02'])->orderBy('table_no')->get();
    }

    private function runFor(Action $action, string $username): ActionRun
    {
        return ActionRun::where('action_id', $action->id)
            ->whereRelation('student', 'username', $username)
            ->latest('id')
            ->firstOrFail();
    }

    public function test_one_playbook_run_is_split_by_student(): void
    {
        $action = $this->action(<<<'YAML'
            - hosts: locals
              gather_facts: false
              tasks:
                - ansible.builtin.command: echo "{{ taula }} {{ xarxa }}"
                - ansible.builtin.fail:
                    msg: "Falla la {{ taula }}"
                  when: num_taula == '02'
            YAML);

        $batch = app(ActionRunner::class)->run($action, $this->students(), ['local']);

        $ok = $this->runFor($action, 'alumne01');
        $this->assertSame(ActionStatus::Changed, $ok->status);
        $this->assertStringContainsString('Taula01 192.168.20.32/28', $ok->output);
        $this->assertStringNotContainsString('Taula02', $ok->output);

        $failed = $this->runFor($action, 'alumne02');
        $this->assertSame(ActionStatus::Failed, $failed->status);
        $this->assertStringContainsString('Falla la Taula02', $failed->output);

        $this->assertSame(1, $batch->fresh()->totals['changed']);
        $this->assertSame(1, $batch->fresh()->totals['failed']);
        $this->assertNotNull($batch->fresh()->finished_at);
    }

    public function test_simulation_does_not_change_anything(): void
    {
        $dir = sys_get_temp_dir().'/heimdeye-test-'.uniqid();
        mkdir($dir);
        $action = $this->action(<<<'YAML'
            - hosts: locals
              gather_facts: false
              tasks:
                - ansible.builtin.copy:
                    content: "hola {{ taula }}\n"
                    dest: "{{ dir }}/{{ taula }}.txt"
            YAML, [['name' => 'dir', 'label' => 'Directori']]);

        app(ActionRunner::class)->run($action, $this->students(), ['local'], ['dir' => $dir], checkMode: true);

        $run = $this->runFor($action, 'alumne01');
        $this->assertTrue($run->check_mode);
        $this->assertSame(ActionStatus::Changed, $run->status);
        $this->assertSame(['dir' => $dir], $run->params);
        $this->assertStringContainsString('hola Taula01', $run->output); // diff
        $this->assertFileDoesNotExist("$dir/Taula01.txt");

        app(ActionRunner::class)->run($action, $this->students(), ['local'], ['dir' => $dir]);
        $this->assertFileExists("$dir/Taula01.txt");
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }

    public function test_secrets_are_passed_but_never_stored(): void
    {
        $this->module->secrets()->updateOrCreate(['key' => 'jaume_password'], ['value' => 'molt-secreta-123']);
        $action = $this->action(<<<'YAML'
            - hosts: locals
              gather_facts: false
              tasks:
                - ansible.builtin.debug:
                    msg: "La contrasenya és {{ jaume_password }}"
            YAML);

        app(ActionRunner::class)->run($action, $this->students(), ['local']);

        $run = $this->runFor($action, 'alumne01');
        $this->assertSame(ActionStatus::Ok, $run->status);
        $this->assertStringContainsString('La contrasenya és ********', $run->output);
        $this->assertStringNotContainsString('molt-secreta-123', $run->output);
    }

    public function test_unreachable_hosts_are_unavailable(): void
    {
        $this->module->hostTypes()->create([
            'name' => 'tancat',
            'ansible_group' => 'tancats',
            'host_name' => 'tancat{{num_taula}}',
            'address' => '127.0.0.1',
            'connection_vars' => ['ansible_port' => 1],
        ]);
        $action = $this->action("- hosts: tancats\n  gather_facts: false\n  tasks:\n    - ansible.builtin.ping:\n");

        app(ActionRunner::class)->run($action, $this->students(), ['tancat']);

        $this->assertSame(ActionStatus::Unavailable, $this->runFor($action, 'alumne01')->status);
    }

    public function test_params_are_validated(): void
    {
        $action = $this->action("- hosts: locals\n  tasks: []\n", [['name' => 'dir']]);

        $this->expectException(InvalidArgumentException::class);
        app(ActionRunner::class)->run($action, $this->students(), ['local'], ['altre' => 'x']);
    }

    public function test_ansible_check(): void
    {
        $check = Task::firstOrFail()->checks()->create(['name' => 'Ansible', 'executor' => Executor::Ansible, 'active' => true, 'timeout_s' => 60]);
        $check->versions()->create(['version' => 1, 'code' => <<<'YAML'
            - hosts: locals
              gather_facts: false
              tasks:
                - ansible.builtin.assert:
                    that: x == '21'
            YAML]);
        $runner = app(CheckRunner::class);

        $this->assertSame(CheckStatus::Pass, $runner->run($check->fresh(), Student::where('username', 'alumne01')->sole())->status);
        $this->assertSame(CheckStatus::Fail, $runner->run($check->fresh(), Student::where('username', 'alumne02')->sole())->status);
    }

    public function test_command_simulates_an_action(): void
    {
        $action = $this->action("- hosts: locals\n  gather_facts: false\n  tasks:\n    - ansible.builtin.debug: msg=hola\n");

        $this->artisan('heimdeye:action', ['action' => 'Prova', '--student' => ['alumne01'], '--host' => ['local'], '--simulate' => true])
            ->expectsOutputToContain('alumne01')
            ->assertSuccessful();

        $this->assertTrue($this->runFor($action, 'alumne01')->check_mode);
    }
}
