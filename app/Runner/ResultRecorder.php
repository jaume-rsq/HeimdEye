<?php

namespace App\Runner;

use App\Enums\CheckStatus;
use App\Models\Check;
use App\Models\CheckRun;
use App\Models\CheckStudent;
use App\Models\RunBatch;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

/** Desa una execució al log i actualitza l'estat del check per a l'alumne. */
class ResultRecorder
{
    public function record(RunBatch $batch, Check $check, Student $student, CheckResult $result): CheckRun
    {
        return DB::transaction(function () use ($batch, $check, $student, $result) {
            $now = now();

            $run = CheckRun::create([
                'run_batch_id' => $batch->id,
                'check_id' => $check->id,
                'check_version_id' => $check->currentVersion?->id,
                'student_id' => $student->id,
                'status' => $result->status,
                'message' => $result->message,
                'output' => $result->output,
                'exit_code' => $result->exitCode,
                'duration_ms' => $result->durationMs,
                'ran_at' => $now,
            ]);

            $state = CheckStudent::firstOrNew(['check_id' => $check->id, 'student_id' => $student->id]);
            $state->current_status = $result->status;
            $state->last_run_at = $now;
            if ($result->status === CheckStatus::Pass) {
                $state->last_passed_at = $now;
                $state->first_passed_at ??= $now; // no es torna a tocar mai
            }
            $state->save();

            return $run;
        });
    }
}
