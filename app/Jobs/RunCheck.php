<?php

namespace App\Jobs;

use App\Enums\CheckStatus;
use App\Models\Check;
use App\Models\CheckRun;
use App\Models\CheckVersion;
use App\Models\RunBatch;
use App\Models\Student;
use App\Runner\CheckResult;
use App\Runner\CheckRunner;
use App\Runner\ResultRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

/**
 * Executa un check per a un alumne dins d'un lot i en desa el resultat. Es fa servir la versió
 * vigent quan es posa a la cua, i és la mateixa que queda registrada a l'execució.
 * Si el treball falla o s'atura per temps màxim abans de tenir resultat, es desa una execució
 * en estat error perquè es vegi a l'historial.
 */
class RunCheck implements ShouldQueue
{
    use Queueable;

    /** Un reintent si l'equip no respon: pot estar reiniciant-se. */
    public int $tries = 2;

    /** Marge sobre el temps màxim de la versió, que ja atura l'script. */
    public int $timeout;

    public bool $failOnTimeout = true;

    public ?CheckVersion $version;

    public function __construct(
        public RunBatch $batch,
        public Check $check,
        public Student $student,
        ?CheckVersion $version = null,
    ) {
        $this->onQueue(config('heimdeye.runner.queue'));
        $this->version = $version ?? $check->currentVersion;
        $this->timeout = ($this->version->temps_max_seg ?? 15) + 60;
    }

    public function handle(CheckRunner $runner, ResultRecorder $recorder): void
    {
        $result = $runner->run($this->check, $this->student, $this->version);

        // Executat ara mateix (sync) no hi ha cua on tornar-lo a posar: es desa tal com surt.
        $transient = in_array($result->status, [CheckStatus::Unavailable, CheckStatus::Timeout], true);
        if ($transient && $this->attempts() < $this->tries && ! $this->job instanceof SyncJob) {
            $this->release((int) config('heimdeye.runner.retry_after'));

            return;
        }

        $this->save($recorder, $result);
    }

    public function failed(?Throwable $e): void
    {
        $saved = CheckRun::where('execucio_id', $this->batch->id)
            ->where('check_id', $this->check->id)
            ->where('alumne_id', $this->student->id)
            ->exists();
        if ($saved) {
            return;
        }

        $this->save(app(ResultRecorder::class), CheckResult::error("No s'ha pogut executar: ".($e?->getMessage() ?: 'error desconegut')));
    }

    private function save(ResultRecorder $recorder, CheckResult $result): void
    {
        $recorder->record($this->batch, $this->check, $this->student, $result, $this->version);
        $this->batch->refreshTotals();
    }
}
