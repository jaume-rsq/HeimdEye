<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Jobs\RunCheck;
use App\Models\Check;
use App\Models\CheckRun;
use App\Models\CheckStudent;
use App\Models\RunBatch;
use App\Models\Student;
use App\Models\Task;
use App\Runner\CheckResult;
use App\Runner\CheckRunner;
use App\Runner\ResultRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunnerTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function check(string $code, Executor $executor = Executor::Local, array $config = [], int $timeout = 10): Check
    {
        $check = Task::firstOrFail()->checks()->create([
            'name' => 'Prova',
            'executor' => $executor,
            'active' => true,
            'timeout_s' => $timeout,
        ]);
        $check->versions()->create(['version' => 1, 'code' => $code, 'config' => $config]);

        return $check->fresh();
    }

    private function execute(Check $check, string $username = 'alumne01'): CheckResult
    {
        return app(CheckRunner::class)->run($check, Student::where('username', $username)->firstOrFail());
    }

    public function test_local_check_gets_student_variables_and_reports_the_first_line(): void
    {
        $result = $this->execute($this->check('echo "pve=$HE_PVE_IP x=$HE_X user=$HE_SSH_USER"; echo segona'));

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame('pve=192.168.20.35 x=21 user=jaume', $result->message);
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
        $result = $this->execute($this->check('true', Executor::Ssh, ['host' => '127.0.0.1', 'port' => 1]));

        $this->assertSame(CheckStatus::Unavailable, $result->status);
        $this->assertSame(255, $result->exitCode);
    }

    public function test_missing_variable_in_config_is_an_error(): void
    {
        $result = $this->execute($this->check('true', Executor::Ssh, ['host' => '{{ no_existeix }}']));

        $this->assertSame(CheckStatus::Error, $result->status);
        $this->assertStringContainsString('no_existeix', $result->message);
    }

    public function test_not_implemented_executor_is_an_error(): void
    {
        $this->assertSame(CheckStatus::Error, $this->execute($this->check('GET /', Executor::Http))->status);
    }

    public function test_recorder_keeps_the_first_pass_date(): void
    {
        $check = $this->check('true');
        $student = Student::firstOrFail();
        $batch = RunBatch::create(['module_id' => $check->task->block->module_id, 'origin' => 'manual', 'totals' => ['expected' => 2]]);
        $recorder = app(ResultRecorder::class);

        $this->travelTo('2026-11-03 02:00');
        $recorder->record($batch, $check, $student, new CheckResult(CheckStatus::Pass));
        $this->travelTo('2026-11-04 02:00');
        $recorder->record($batch, $check, $student, new CheckResult(CheckStatus::Fail));
        $batch->refreshTotals();

        $state = CheckStudent::where(['check_id' => $check->id, 'student_id' => $student->id])->sole();
        $this->assertSame(CheckStatus::Fail, $state->current_status);
        $this->assertSame('2026-11-03', $state->first_passed_at->toDateString());
        $this->assertSame('2026-11-03', $state->last_passed_at->toDateString());
        $this->assertSame(2, CheckRun::where('check_id', $check->id)->count());
        $this->assertSame(['expected' => 2, 'fail' => 1, 'pass' => 1], collect($batch->totals)->sortKeys()->all());
        $this->assertNotNull($batch->finished_at);
    }

    public function test_command_runs_and_records_selected_checks(): void
    {
        $check = $this->check('echo ok');

        $this->artisan('heimdeye:run', ['--check' => [$check->id], '--student' => ['alumne01', 'alumne02']])
            ->assertSuccessful();

        $this->assertSame(2, CheckStudent::where('check_id', $check->id)->where('current_status', 'pass')->count());
        $this->assertSame(['expected' => 2, 'pass' => 2], collect(RunBatch::sole()->totals)->sortKeys()->all());
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
        $this->assertSame('nightly', RunBatch::sole()->origin->value);
    }

    public function test_job_retries_once_when_the_host_is_unavailable(): void
    {
        $check = $this->check('true', Executor::Ssh, ['host' => '127.0.0.1', 'port' => 1]);
        $batch = RunBatch::create(['module_id' => $check->task->block->module_id, 'origin' => 'nightly']);

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
}
