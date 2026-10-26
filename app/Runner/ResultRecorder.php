<?php

namespace App\Runner;

use App\Enums\CheckStatus;
use App\Models\Check;
use App\Models\CheckRun;
use App\Models\CheckStudent;
use App\Models\CheckVersion;
use App\Models\RunBatch;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Desa una execució al log (amb la versió que s'ha executat) i actualitza l'estat del check per a l'alumne. */
class ResultRecorder
{
    public function record(RunBatch $batch, Check $check, Student $student, CheckResult $result, ?CheckVersion $version = null): CheckRun
    {
        return DB::transaction(function () use ($batch, $check, $student, $result, $version) {
            $now = now();

            $run = CheckRun::create([
                'execucio_id' => $batch->id,
                'check_id' => $check->id,
                'versio_check_id' => ($version ?? $check->currentVersion)?->id,
                'alumne_id' => $student->id,
                'estat' => $result->status,
                'missatge' => $result->message === null ? null : Str::limit($result->message, 250), // la columna és de 255
                'sortida' => $result->output,
                'codi_sortida' => $result->exitCode,
                'durada_ms' => $result->durationMs,
                'data_execucio' => $now,
            ]);

            $state = CheckStudent::firstOrNew(['check_id' => $check->id, 'alumne_id' => $student->id]);
            $state->estat_actual = $result->status;
            $state->data_darrera_execucio = $now;
            if ($result->status === CheckStatus::Pass) {
                $state->data_ultim_assoliment = $now;
                $state->data_primer_assoliment ??= $now; // no es torna a tocar mai
            }
            $state->save();

            return $run;
        });
    }
}
