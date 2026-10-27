<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Models\Action;
use App\Models\ActionRun;
use App\Models\Module;
use App\Models\RunBatch;
use App\Models\Secret;
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

        $this->module = Module::where('codi', '9991')->firstOrFail();
        $this->module->hostTypes()->create([
            'nom' => 'local',
            'grup_ansible' => 'locals',
            'nom_host' => 'local{{num_taula}}',
            'adreca' => '127.0.0.1',
            'variables_connexio' => ['ansible_connection' => 'local', 'ansible_python_interpreter' => 'auto_silent'],
        ]);
    }

    private function action(string $playbook, array $params = []): Action
    {
        $action = $this->module->actions()->create(['nom' => 'Prova', 'executor' => Executor::Ansible]);
        $action->versions()->create(['versio' => 1, 'codi' => $playbook, 'parametres' => $params, 'temps_max_seg' => 60]);

        return $action->fresh();
    }

    /** @return Collection<int, Student> */
    private function students(): Collection
    {
        return Student::whereIn('usuari', ['alumne01', 'alumne02'])->orderBy('num_taula')->get();
    }

    /**
     * Llança l'acció per a alumne01 i alumne02 i l'executa ara (sense cua).
     *
     * @param  list<string>  $hostTypes
     */
    private function launch(Action $action, array $hostTypes = [], array $params = [], bool $checkMode = false): RunBatch
    {
        return app(ActionRunner::class)->launch($action, $this->students(), $hostTypes, $params, $checkMode, sync: true);
    }

    private function runFor(Action $action, string $username): ActionRun
    {
        return ActionRun::where('accio_id', $action->id)
            ->whereRelation('student', 'usuari', $username)
            ->latest('id')
            ->firstOrFail();
    }

    public function test_each_student_is_a_separate_run(): void
    {
        $action = $this->action(<<<'YAML'
            - hosts: locals
              gather_facts: false
              tasks:
                - ansible.builtin.command: echo "{{ nom_taula }} {{ xarxa_aula }}"
                - ansible.builtin.fail:
                    msg: "Falla la {{ nom_taula }}"
                  when: num_taula == '02'
            YAML);

        $batch = $this->launch($action, ['local']);

        $ok = $this->runFor($action, 'alumne01');
        $this->assertSame(ActionStatus::Changed, $ok->estat);
        $this->assertStringContainsString('Taula01 198.51.100.16/28', $ok->sortida);
        $this->assertStringNotContainsString('Taula02', $ok->sortida);

        $failed = $this->runFor($action, 'alumne02');
        $this->assertSame(ActionStatus::Failed, $failed->estat);
        $this->assertStringContainsString('Falla la Taula02', $failed->sortida);

        $this->assertSame(1, $batch->fresh()->totals['changed']);
        $this->assertSame(1, $batch->fresh()->totals['failed']);
        $this->assertNotNull($batch->fresh()->data_fi);
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
                    content: "hola {{ nom_taula }}\n"
                    dest: "{{ dir }}/{{ nom_taula }}.txt"
            YAML, [['name' => 'dir', 'label' => 'Directori']]);

        $this->launch($action, ['local'], ['dir' => $dir], checkMode: true);

        $run = $this->runFor($action, 'alumne01');
        $this->assertTrue($run->es_simulacio);
        $this->assertSame(ActionStatus::Changed, $run->estat);
        $this->assertSame(['dir' => $dir], $run->parametres);
        $this->assertStringContainsString('hola Taula01', $run->sortida); // diff
        $this->assertFileDoesNotExist("$dir/Taula01.txt");

        $this->launch($action, ['local'], ['dir' => $dir]);
        $this->assertFileExists("$dir/Taula01.txt");
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }

    public function test_secrets_are_passed_but_never_stored(): void
    {
        $this->module->secrets()->updateOrCreate(['clau' => 'jaume_password'], ['valor' => 'molt-secreta-123']);
        $action = $this->action(<<<'YAML'
            - hosts: locals
              gather_facts: false
              tasks:
                - ansible.builtin.debug:
                    msg: "La contrasenya és {{ jaume_password }}"
                  delegate_to: localhost
            YAML);

        $this->launch($action, ['local']);

        $run = $this->runFor($action, 'alumne01');
        $this->assertSame(ActionStatus::Ok, $run->estat);
        $this->assertStringContainsString('La contrasenya és ********', $run->sortida);
        $this->assertStringNotContainsString('molt-secreta-123', $run->sortida);
    }

    public function test_a_login_secret_is_a_dictionary_and_its_name_alone_is_just_text(): void
    {
        Secret::where('clau', 'jaume')->sole()->update(['valor' => 'molt-secreta-123']);
        $action = $this->action(<<<'YAML'
            - hosts: locals
              gather_facts: false
              tasks:
                - ansible.builtin.debug:
                    msg: "usuari {{ jaume.user }} hash {{ jaume.password | password_hash('sha512', 'heimdeye') | length > 0 }}"
            YAML);
        $this->launch($action, ['local']);
        $run = $this->runFor($action, 'alumne01');
        $this->assertSame(ActionStatus::Ok, $run->estat);
        $this->assertStringContainsString('usuari jaume hash True', $run->sortida);

        // Sense valor, "jaume" com a text no demana el secret.
        Secret::where('clau', 'jaume')->sole()->update(['valor' => null]);
        $plain = $this->action("- hosts: locals\n  gather_facts: false\n  tasks:\n    - ansible.builtin.debug:\n        msg: usuari jaume\n");
        $this->launch($plain, ['local']);
        $this->assertSame(ActionStatus::Ok, $this->runFor($plain, 'alumne01')->estat);
    }

    public function test_the_password_of_a_login_secret_cannot_reach_the_student_host(): void
    {
        Secret::where('clau', 'jaume')->sole()->update(['valor' => 'molt-secreta-123']);
        $action = $this->action("- hosts: locals\n  tasks:\n    - ansible.builtin.debug:\n        msg: \"{{ jaume.password }}\"\n");

        $this->expectExceptionMessage('jaume');
        $this->launch($action, ['local']);
    }

    public function test_unreachable_hosts_are_unavailable(): void
    {
        $this->module->hostTypes()->create([
            'nom' => 'tancat',
            'grup_ansible' => 'tancats',
            'nom_host' => 'tancat{{num_taula}}',
            'adreca' => '127.0.0.1',
            'variables_connexio' => ['ansible_port' => 1],
        ]);
        $action = $this->action("- hosts: tancats\n  gather_facts: false\n  tasks:\n    - ansible.builtin.ping:\n");

        $this->launch($action, ['tancat']);

        $this->assertSame(ActionStatus::Unavailable, $this->runFor($action, 'alumne01')->estat);
    }

    public function test_params_are_validated(): void
    {
        $action = $this->action("- hosts: locals\n  tasks: []\n", [['name' => 'dir']]);

        $this->expectException(InvalidArgumentException::class);
        $this->launch($action, ['local'], ['altre' => 'x']);
    }

    public function test_a_secret_that_would_reach_the_student_host_is_refused(): void
    {
        $this->module->secrets()->updateOrCreate(['clau' => 'jaume_password'], ['valor' => 'molt-secreta-123']);
        $action = $this->action("- hosts: locals\n  tasks:\n    - ansible.builtin.debug:\n        msg: \"{{ jaume_password }}\"\n");

        $this->expectExceptionMessage('jaume_password');
        $this->launch($action, ['local']);
    }

    public function test_a_playbook_that_touches_no_host_of_the_student_is_an_error(): void
    {
        $action = $this->action("- hosts: locals:!locals\n  gather_facts: false\n  tasks:\n    - ansible.builtin.ping:\n");

        $this->launch($action, ['local']);

        $run = $this->runFor($action, 'alumne01');
        $this->assertSame(ActionStatus::Error, $run->estat);
        $this->assertStringContainsString('Cap equip de l\'alumne', $run->sortida);
    }

    public function test_hosts_all_is_not_allowed(): void
    {
        $action = $this->action("- hosts: all\n  tasks: []\n");

        $this->expectExceptionMessage('all');
        $this->launch($action);
    }

    public function test_template_params_are_computed_for_each_student(): void
    {
        $action = $this->action(<<<'YAML'
            - hosts: locals
              gather_facts: false
              tasks:
                - ansible.builtin.debug:
                    msg: "ip={{ ip }}"
            YAML, [['name' => 'ip', 'default' => '{{ xarxa_aula | host(3) }}']]);

        $this->launch($action, ['local']);

        $run = $this->runFor($action, 'alumne01');
        $this->assertStringContainsString('ip=198.51.100.19', $run->sortida);
        $this->assertSame(['ip' => '198.51.100.19'], $run->parametres);
        $this->assertStringNotContainsString('ip=198.51.100.19', $this->runFor($action, 'alumne02')->sortida);
    }

    public function test_a_student_with_broken_variables_does_not_stop_the_others(): void
    {
        Student::where('usuari', 'alumne02')->sole()->update(['variables' => ['xarxa_aula' => 'no-és-una-xarxa']]);
        $action = $this->action("- hosts: locals\n  gather_facts: false\n  tasks:\n    - ansible.builtin.ping:\n");

        $this->launch($action, ['local']);

        $this->assertSame(ActionStatus::Ok, $this->runFor($action, 'alumne01')->estat);
        $this->assertSame(ActionStatus::Error, $this->runFor($action, 'alumne02')->estat);
    }

    public function test_an_ansible_check_that_changes_something_is_an_error(): void
    {
        $check = Task::firstOrFail()->checks()->create(['nom' => 'Canvia', 'executor' => Executor::Ansible, 'es_actiu' => true]);
        $check->versions()->create(['versio' => 1, 'temps_max_seg' => 60, 'codi' => "- hosts: locals\n  gather_facts: false\n  tasks:\n    - ansible.builtin.command: 'true'\n"]);

        $result = app(CheckRunner::class)->run($check->fresh(), Student::where('usuari', 'alumne01')->sole());

        $this->assertSame(CheckStatus::Error, $result->status);
        $this->assertStringContainsString('changed_when', $result->message);
    }

    public function test_ansible_check(): void
    {
        $check = Task::firstOrFail()->checks()->create(['nom' => 'Ansible', 'executor' => Executor::Ansible, 'es_actiu' => true]);
        $check->versions()->create(['versio' => 1, 'temps_max_seg' => 60, 'codi' => <<<'YAML'
            - hosts: locals
              gather_facts: false
              tasks:
                - ansible.builtin.assert:
                    that: x == '21'
            YAML]);
        $runner = app(CheckRunner::class);

        $this->assertSame(CheckStatus::Pass, $runner->run($check->fresh(), Student::where('usuari', 'alumne01')->sole())->status);
        $this->assertSame(CheckStatus::Fail, $runner->run($check->fresh(), Student::where('usuari', 'alumne02')->sole())->status);
    }

    public function test_command_simulates_an_action(): void
    {
        $action = $this->action("- hosts: locals\n  gather_facts: false\n  tasks:\n    - ansible.builtin.debug: msg=hola\n");

        $this->artisan('heimdeye:action', ['action' => 'Prova', '--student' => ['alumne01'], '--host' => ['local'], '--simulate' => true])
            ->expectsOutputToContain('alumne01')
            ->assertSuccessful();

        $this->assertTrue($this->runFor($action, 'alumne01')->es_simulacio);
    }
}
