<?php

namespace App\Console\Commands;

use App\Enums\RunOrigin;
use App\Jobs\RunCheck;
use App\Models\AcademicYear;
use App\Models\Check;
use App\Models\Module;
use App\Models\RunBatch;
use App\Models\Student;
use App\Runner\CheckRunner;
use App\Runner\ResultRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RunChecks extends Command
{
    protected $signature = 'heimdeye:run
        {--module= : Codi del mòdul (per defecte, tots els del curs actual)}
        {--block= : Número de bloc}
        {--check=* : Id de check; s\'executa encara que no estigui actiu}
        {--student=* : Usuari de l\'alumne}
        {--nightly : Execució nocturna: tot a la cua}
        {--queue : Envia les execucions a la cua en lloc d\'executar-les ara}
        {--dry : Prova: mostra els resultats però no desa res}';

    protected $description = 'Executa els checks actius sobre els alumnes matriculats';

    public function handle(CheckRunner $runner, ResultRecorder $recorder): int
    {
        $queued = $this->option('nightly') || $this->option('queue');
        $origin = $this->option('nightly') ? RunOrigin::Nightly : RunOrigin::Manual;
        $rows = [];

        foreach ($this->modules() as $module) {
            $checks = $this->checks($module);
            $students = $this->students($module);
            if ($checks->isEmpty() || $students->isEmpty()) {
                continue;
            }

            $batch = $this->option('dry') ? null : RunBatch::create([
                'module_id' => $module->id,
                'origin' => $origin,
                'started_at' => now(),
                'totals' => ['expected' => $checks->count() * $students->count()],
            ]);

            foreach ($checks as $check) {
                foreach ($students as $student) {
                    if ($batch && $queued) {
                        RunCheck::dispatch($batch, $check, $student);

                        continue;
                    }

                    // A mà no hi ha reintents: el resultat es desa tal com surt.
                    $result = $runner->run($check, $student);
                    if ($batch) {
                        $recorder->record($batch, $check, $student, $result);
                    }

                    $rows[] = [$student->username, $check->id.' '.$check->name, $result->status->value, $result->message, $result->durationMs];
                }
            }

            if ($batch && ! $queued) {
                $batch->refreshTotals();
            }
            if ($batch && $queued) {
                $this->info("Lot {$batch->id} ({$module->code}): {$batch->totals['expected']} execucions a la cua");
            }
        }

        if ($rows) {
            $this->table(['Alumne', 'Check', 'Estat', 'Missatge', 'ms'], $rows);
        } elseif (! $queued) {
            $this->warn('No hi ha cap check actiu per executar');
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, Module> */
    private function modules(): Collection
    {
        if ($code = $this->option('module')) {
            return Module::where('code', $code)
                ->whereHas('academicYear', fn (Builder $q) => $q->where('is_current', true))
                ->get();
        }

        return AcademicYear::where('is_current', true)->first()?->modules ?? collect();
    }

    /** @return Collection<int, Check> */
    private function checks(Module $module): Collection
    {
        $query = Check::query()
            ->with(['currentVersion', 'credential', 'task.block.module.credential'])
            ->whereHas('task.block', fn (Builder $q) => $q
                ->where('module_id', $module->id)
                ->when($this->option('block'), fn (Builder $q, $number) => $q->where('number', $number)));

        return $this->option('check')
            ? $query->whereIn('id', $this->option('check'))->get()
            : $query->activeOn()->get();
    }

    /** @return Collection<int, Student> */
    private function students(Module $module): Collection
    {
        return $module->students()
            ->where('active', true)
            ->when($this->option('student'), fn (Builder $q, $usernames) => $q->whereIn('username', $usernames))
            ->get();
    }
}
