<?php

namespace App\Jobs;

use App\Enums\ActionStatus;
use App\Enums\RunOrigin;
use App\Models\Action;
use App\Models\ActionRun;
use App\Models\RunBatch;
use App\Models\Student;
use App\Models\User;
use App\Runner\ActionRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Executa (o simula) una acció en segon pla. Sense reintents: una acció canvia els equips.
 * Si falla abans de tenir resultat, l'error es desa com a execució en estat error per a
 * cada alumne (i al log), perquè es vegi a l'historial.
 */
class RunAction implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  list<int>  $studentIds
     * @param  list<string>  $hostTypes
     * @param  array<string, string>  $params
     */
    public function __construct(
        public Action $action,
        public array $studentIds,
        public array $hostTypes = [],
        public array $params = [],
        public bool $checkMode = false,
        public ?User $user = null,
    ) {
        $this->onQueue(config('heimdeye.runner.queue'));
    }

    public function handle(ActionRunner $runner): void
    {
        $students = Student::whereIn('id', $this->studentIds)->orderBy('num_taula')->get();

        try {
            $runner->run($this->action, $students, $this->hostTypes, $this->params, $this->checkMode, $this->user);
        } catch (Throwable $e) {
            report($e);
            $this->recordError($students->modelKeys(), $e);
        }
    }

    /** @param  list<int>  $studentIds */
    private function recordError(array $studentIds, Throwable $e): void
    {
        $batch = RunBatch::create([
            'modul_id' => $this->action->modul_id,
            'origen' => RunOrigin::Action,
            'llancat_per' => $this->user?->id,
            'data_inici' => now(),
            'totals' => ['expected' => count($studentIds), 'es_simulacio' => $this->checkMode],
        ]);
        foreach ($studentIds as $id) {
            ActionRun::create([
                'execucio_id' => $batch->id,
                'accio_id' => $this->action->id,
                'versio_accio_id' => $this->action->currentVersion?->id,
                'alumne_id' => $id,
                'llancat_per' => $this->user?->id,
                'es_simulacio' => $this->checkMode,
                'estat' => ActionStatus::Error,
                'parametres' => $this->params ?: null,
                'sortida' => "No s'ha pogut executar: {$e->getMessage()}",
                'data_inici' => now(),
                'data_fi' => now(),
            ]);
        }
        $batch->refreshTotals();
    }
}
