<?php

namespace App\Jobs;

use App\Enums\CheckStatus;
use App\Models\Check;
use App\Models\CheckVersion;
use App\Models\RunBatch;
use App\Models\Student;
use App\Runner\CheckRunner;
use App\Runner\ResultRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Executa un check per a un alumne dins d'un lot i en desa el resultat. Es fa servir la versió
 * vigent quan es posa a la cua, i és la mateixa que queda registrada a l'execució.
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

        $transient = in_array($result->status, [CheckStatus::Unavailable, CheckStatus::Timeout], true);
        if ($transient && $this->attempts() < $this->tries) {
            $this->release((int) config('heimdeye.runner.retry_after'));

            return;
        }

        $recorder->record($this->batch, $this->check, $this->student, $result, $this->version);
        $this->batch->refreshTotals();
    }
}
