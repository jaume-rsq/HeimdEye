<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Jobs\RunCheck;
use App\Models\Check;
use App\Models\CheckRun;
use App\Models\CheckStudent;
use App\Models\Credential;
use App\Models\Module;
use App\Models\RunBatch;
use App\Models\Student;
use App\Models\Task;
use App\Runner\CheckResult;
use App\Runner\CheckRunner;
use App\Runner\CodeHeaders;
use App\Runner\ResultRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunnerTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function check(string $code, Executor $executor = Executor::Local, int $timeout = 10, ?array $params = null): Check
    {
        $check = Task::firstOrFail()->checks()->create([
            'nom' => 'Prova',
            'executor' => $executor,
            'es_actiu' => true,
        ]);
        $check->versions()->create(['versio' => 1, 'codi' => $code, 'temps_max_seg' => $timeout, 'parametres' => $params]);

        return $check->fresh();
    }

    /** Tipus d'equip que no respon: SSH a un port tancat. */
    private function closedHostType(string $name = 'tancat'): void
    {
        Module::where('codi', '9991')->sole()->hostTypes()->create([
            'nom' => $name,
            'grup_ansible' => "{$name}s",
            'nom_host' => "{$name}{{num_taula}}",
            'adreca' => '127.0.0.1',
            'variables_connexio' => ['ansible_port' => 1],
        ]);
    }

    private function execute(Check $check, string $username = 'alumne01'): CheckResult
    {
        return app(CheckRunner::class)->run($check, Student::where('usuari', $username)->firstOrFail());
    }

    public function test_local_check_gets_student_variables_and_reports_the_first_line(): void
    {
        $result = $this->execute($this->check('echo "pve=$HE_IP_PVE x=$HE_X user=$HE_SSH_USER"; echo segona'));

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame('pve=198.51.100.19 x=21 user=jaume', $result->message);
        $this->assertSame(0, $result->exitCode);
    }

    public function test_explicit_message_and_failure(): void
    {
        $result = $this->execute($this->check("echo soroll\necho 'HEIMDEYE_MSG: no hi ha cap VM'\nexit 3"));

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame('no hi ha cap VM', $result->message);
        $this->assertSame(3, $result->exitCode);
    }

    public function test_timeout(): void
    {
        $result = $this->execute($this->check('sleep 5', timeout: 1));

        $this->assertSame(CheckStatus::Timeout, $result->status);
    }

    public function test_ssh_to_an_unreachable_host_is_unavailable(): void
    {
        $this->closedHostType();

        $result = $this->execute($this->check("# hosts: tancats\ntrue", Executor::Ssh));

        $this->assertSame(CheckStatus::Unavailable, $result->status);
        $this->assertSame(255, $result->exitCode);
    }

    public function test_ssh_runs_on_every_host_of_the_groups(): void
    {
        $this->closedHostType('tancat');
        $this->closedHostType('tapiat');

        $result = $this->execute($this->check("# hosts: tancats:tapiat\ntrue", Executor::Ssh));

        $this->assertSame(CheckStatus::Unavailable, $result->status);
        $this->assertStringStartsWith('tancat01: ', $result->message);
        $this->assertStringContainsString('== tancat01: unavailable', $result->output);
        $this->assertStringContainsString('== tapiat01: unavailable', $result->output);
    }

    public function test_ssh_needs_a_known_hosts_header(): void
    {
        $missing = $this->execute($this->check('true', Executor::Ssh));
        $this->assertSame(CheckStatus::Error, $missing->status);
        $this->assertStringContainsString('# hosts:', $missing->message);

        $unknown = $this->execute($this->check("# hosts: no_existeix\ntrue", Executor::Ssh));
        $this->assertSame(CheckStatus::Error, $unknown->status);
        $this->assertStringContainsString('no_existeix', $unknown->message);

        // Els Windows es connecten per WinRM: no es poden fer servir amb l'executor ssh.
        $winrm = $this->execute($this->check("# hosts: vm_dns_windows\ntrue", Executor::Ssh));
        $this->assertSame(CheckStatus::Error, $winrm->status);
        $this->assertStringContainsString('winrm', $winrm->message);
    }

    public function test_credential_header_replaces_the_module_credential(): void
    {
        Credential::where('nom', 'API Zabbix')->sole()->update(['secret' => 'zbx-secret-1']);
        Credential::create(['nom' => 'SSH altre', 'tipus' => 'ssh', 'nom_usuari' => 'altre']);

        $ssh = $this->execute($this->check("# credencial: SSH altre\necho \"user=\$HE_SSH_USER\""));
        $this->assertSame('user=altre', $ssh->message);

        $password = $this->execute($this->check("# credencial: API Zabbix\necho \"\$HE_CRED_USER \$HE_CRED_PASSWORD\""));
        $this->assertSame('ansible ********', $password->message);

        $unknown = $this->execute($this->check("# credencial: No hi és\ntrue"));
        $this->assertSame(CheckStatus::Error, $unknown->status);
    }

    public function test_check_parameters_use_their_defaults(): void
    {
        $result = $this->execute($this->check('echo "min=$HE_PARAM_MIN_VMS"', params: [['name' => 'min_vms', 'default' => 2]]));
        $this->assertSame('min=2', $result->message);

        $noDefault = $this->execute($this->check('true', params: [['name' => 'min_vms']]));
        $this->assertSame(CheckStatus::Error, $noDefault->status);
    }

    public function test_headers_are_read_from_the_first_comment_lines(): void
    {
        $headers = CodeHeaders::parse("#!/bin/bash\n# Comprova les VM\n#hosts: pve, vm_router\n# Credencial: SSH jaume\n\necho hola\n# hosts: dns\n");

        $this->assertSame(['pve', 'vm_router'], $headers->hosts);
        $this->assertSame('SSH jaume', $headers->credential);
        $this->assertSame([], CodeHeaders::parse("echo hola\n# hosts: pve")->hosts);
    }

    public function test_executor_cannot_change(): void
    {
        $check = $this->check('true');

        $this->expectException(\LogicException::class);
        $check->update(['executor' => Executor::Ssh]);
    }

    public function test_a_secret_without_value_is_an_error(): void
    {
        $result = $this->execute($this->check('[ -n "$HE_SECRET_JAUME_PASSWORD" ]'));

        $this->assertSame(CheckStatus::Error, $result->status);
        $this->assertStringContainsString('jaume_password no té valor', $result->message);
    }

    public function test_not_implemented_executor_is_an_error(): void
    {
        $this->assertSame(CheckStatus::Error, $this->execute($this->check('GET /', Executor::Http))->status);
    }

    public function test_recorder_keeps_the_first_pass_date(): void
    {
        $check = $this->check('true');
        $student = Student::firstOrFail();
        $batch = RunBatch::create(['modul_id' => $check->task->block->modul_id, 'origen' => 'manual', 'totals' => ['expected' => 2]]);
        $recorder = app(ResultRecorder::class);

        $this->travelTo('2026-11-03 02:00');
        $recorder->record($batch, $check, $student, new CheckResult(CheckStatus::Pass));
        $this->travelTo('2026-11-04 02:00');
        $recorder->record($batch, $check, $student, new CheckResult(CheckStatus::Fail));
        $batch->refreshTotals();

        $state = CheckStudent::where(['check_id' => $check->id, 'alumne_id' => $student->id])->sole();
        $this->assertSame(CheckStatus::Fail, $state->estat_actual);
        $this->assertSame('2026-11-03', $state->data_primer_assoliment->toDateString());
        $this->assertSame('2026-11-03', $state->data_ultim_assoliment->toDateString());
        $this->assertSame(2, CheckRun::where('check_id', $check->id)->count());
        $this->assertSame(['expected' => 2, 'fail' => 1, 'pass' => 1], collect($batch->totals)->sortKeys()->all());
        $this->assertNotNull($batch->data_fi);
    }

    public function test_command_runs_and_records_selected_checks(): void
    {
        $check = $this->check('echo ok');

        $this->artisan('heimdeye:run', ['--check' => [$check->id], '--student' => ['alumne01', 'alumne02']])
            ->assertSuccessful();

        $this->assertSame(2, CheckStudent::where('check_id', $check->id)->where('estat_actual', 'pass')->count());
        $this->assertSame(['expected' => 2, 'pass' => 2], collect(RunBatch::sole()->totals)->sortKeys()->all());
    }

    public function test_student_option_accepts_the_table_number(): void
    {
        $check = $this->check('echo ok');
        $table = Student::where('usuari', 'alumne03')->value('num_taula');

        $this->artisan('heimdeye:run', ['--check' => [$check->id], '--student' => [(string) $table], '--dry' => true])
            ->expectsOutputToContain('alumne03')
            ->assertSuccessful();
    }

    public function test_unknown_student_is_reported(): void
    {
        $check = $this->check('echo ok');

        $this->artisan('heimdeye:run', ['--check' => [$check->id], '--student' => ['ningu'], '--dry' => true])
            ->expectsOutput("No s'ha trobat cap dels alumnes indicats (usuari o número de taula)")
            ->assertSuccessful();
    }

    public function test_dry_run_saves_nothing(): void
    {
        $check = $this->check('echo ok');

        $this->artisan('heimdeye:run', ['--check' => [$check->id], '--dry' => true])->assertSuccessful();

        $this->assertSame(0, RunBatch::count());
        $this->assertSame(0, CheckRun::count());
    }

    public function test_nightly_queues_every_active_check_for_every_student(): void
    {
        Queue::fake();
        $this->travelTo('2026-11-03 02:00');

        $this->artisan('heimdeye:run', ['--nightly' => true])->assertSuccessful();

        // Bloc 2: 3 checks actius × 10 alumnes.
        Queue::assertPushed(RunCheck::class, 30);
        $this->assertSame('nocturna', RunBatch::sole()->origen->value);
    }

    public function test_job_retries_once_when_the_host_is_unavailable(): void
    {
        $this->closedHostType();
        $check = $this->check("# hosts: tancats\ntrue", Executor::Ssh);
        $batch = RunBatch::create(['modul_id' => $check->task->block->modul_id, 'origen' => 'nocturna']);

        $job = (new RunCheck($batch, $check, Student::firstOrFail()))->withFakeQueueInteractions();
        $job->handle(app(CheckRunner::class), app(ResultRecorder::class));

        $job->assertReleased(180);
        $this->assertSame(0, CheckRun::count());
    }

    public function test_wrapper_prefixes_the_command(): void
    {
        config(['heimdeye.runner.wrapper' => 'env']);

        $result = $this->execute($this->check('echo "x=$HE_X"'));

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame('x=21', $result->message);
    }

    public function test_ssh_code_cannot_use_secrets(): void
    {
        $this->closedHostType();
        Module::where('codi', '9991')->sole()->secrets()->updateOrCreate(['clau' => 'jaume_password'], ['valor' => 'molt-secreta-123']);

        $result = $this->execute($this->check("# hosts: tancats\necho \"\$HE_SECRET_JAUME_PASSWORD\"", Executor::Ssh));

        $this->assertSame(CheckStatus::Error, $result->status);
        $this->assertStringContainsString('jaume_password', $result->message);
    }

    public function test_secrets_are_matched_by_whole_name(): void
    {
        Module::where('codi', '9991')->sole()->secrets()->updateOrCreate(['clau' => 'pass'], ['valor' => 'secret-curt']);

        // "password" no és el secret "pass": no se n'entrega cap.
        $result = $this->execute($this->check('echo "password: $(env | grep -c ^HE_SECRET_)"'));

        $this->assertSame('password: 0', $result->message);
    }

    public function test_ssh_hosts_all_is_not_allowed(): void
    {
        $result = $this->execute($this->check("# hosts: all\ntrue", Executor::Ssh));

        $this->assertSame(CheckStatus::Error, $result->status);
        $this->assertStringContainsString('all', $result->message);
    }

    public function test_template_params_are_computed_for_the_student(): void
    {
        $result = $this->execute($this->check('echo "ip=$HE_PARAM_IP"', params: [['name' => 'ip', 'default' => '{{ xarxa_aula | host(3) }}']]));

        $this->assertSame('ip=198.51.100.19', $result->message);
    }

    public function test_local_scripts_get_ssh_options_that_do_not_store_host_keys(): void
    {
        $result = $this->execute($this->check('echo "$HE_SSH_OPTS"'));

        $this->assertStringContainsString('UserKnownHostsFile=/dev/null', $result->message);
    }

    public function test_job_runs_and_records_the_version_queued(): void
    {
        $check = $this->check('echo v1');
        $batch = RunBatch::create(['modul_id' => $check->task->block->modul_id, 'origen' => 'nocturna', 'totals' => ['expected' => 1]]);
        $job = new RunCheck($batch, $check, Student::firstOrFail());
        $first = $check->currentVersion;
        $check->versions()->create(['versio' => 2, 'codi' => 'echo v2', 'temps_max_seg' => 10]);

        $job->handle(app(CheckRunner::class), app(ResultRecorder::class));

        $run = CheckRun::sole();
        $this->assertSame('v1', $run->missatge);
        $this->assertSame($first->id, $run->versio_check_id);
        $this->assertSame(70, $job->timeout);
    }
}
