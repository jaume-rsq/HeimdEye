<?php

namespace App\Jobs;

use App\Models\Action;
use App\Models\Student;
use App\Models\User;
use App\Runner\ActionRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Executa (o simula) una acció en segon pla. Sense reintents: una acció canvia els equips. */
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
        $runner->run(
            $this->action,
            Student::whereIn('id', $this->studentIds)->orderBy('table_no')->get(),
            $this->hostTypes,
            $this->params,
            $this->checkMode,
            $this->user,
        );
    }
}
