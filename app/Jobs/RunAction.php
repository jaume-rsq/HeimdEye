<?php

namespace App\Jobs;

use App\Enums\ActionStatus;
use App\Models\Action;
use App\Models\ActionRun;
use App\Models\ActionVersion;
use App\Models\Check;
use App\Models\RunBatch;
use App\Models\Student;
use App\Models\User;
use App\Runner\ActionRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Executa (o simula) una versió d'una acció per a un alumne i en desa el resultat.
 * Sense reintents: una acció canvia els equips. Si falla o s'atura per temps màxim abans
 * de tenir resultat, es desa una execució en estat error perquè es vegi a l'historial.
 */
class RunAction implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Marge sobre el temps màxim de la versió: Ansible o l'script ja s'aturen sols abans. */
    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * @param  list<string>  $hostTypes
     * @param  array<string, string>  $params
     */
    public function __construct(
        public RunBatch $batch,
        public Action $action,
        public ActionVersion $version,
        public Student $student,
        public array $hostTypes = [],
        public array $params = [],
        public bool $checkMode = false,
        public ?User $user = null,
        public ?RunBatch $verification = null,
    ) {
        $this->onQueue(config('heimdeye.runner.queue'));
        $this->timeout = $version->temps_max_seg + 60;
    }

    public function handle(ActionRunner $runner): void
    {
        $this->save($runner->runFor($this->action, $this->version, $this->student, $this->hostTypes, $this->params, $this->checkMode));

        if ($this->verification) {
            foreach (Check::whereIn('id', $this->version->checks_verificacio ?? [])->get() as $check) {
                RunCheck::dispatch($this->verification, $check, $this->student);
            }
        }
    }

    public function failed(?Throwable $e): void
    {
        $saved = ActionRun::where('execucio_id', $this->batch->id)->where('alumne_id', $this->student->id)->exists();
        if ($saved) {
            return;
        }

        $this->save(new ActionRun([
            'accio_id' => $this->action->id,
            'versio_accio_id' => $this->version->id,
            'alumne_id' => $this->student->id,
            'es_simulacio' => $this->checkMode,
            'estat' => ActionStatus::Error,
            'parametres' => $this->params ?: null,
            'sortida' => "No s'ha pogut executar: ".($e?->getMessage() ?: 'error desconegut'),
            'data_inici' => now(),
            'data_fi' => now(),
        ]));
    }

    private function save(ActionRun $run): void
    {
        $run->fill(['execucio_id' => $this->batch->id, 'llancat_per' => $this->user?->id])->save();
        $this->batch->refreshTotals();
    }
}
