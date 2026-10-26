<?php

namespace App\Support;

use App\Enums\CheckKind;
use App\Enums\CheckStatus;
use App\Models\Block;
use App\Models\Check;
use App\Models\CheckRun;
use App\Models\CheckStudent;
use App\Models\Module;
use App\Models\Student;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Seguiment d'un alumne en un mòdul: cada check, agrupat per bloc i tasca, amb l'estat, la darrera
 * execució, els punts i els canvis d'estat (només les execucions en què el resultat va canviar).
 */
class StudentProgress
{
    /**
     * @param  array{bloc?: ?int, tasca?: ?int, estat?: ?string, obligatoris?: bool}  $filters
     *                                                                                          estat: pendents (no assolits), assolits o error (la darrera execució no va poder comprovar res)
     * @return list<array{block: Block, tasks: list<array{task: Task, assolits: int, total: int, rows: list<array<string, mixed>>}>}>
     */
    public function build(Student $student, Module $module, array $filters = []): array
    {
        $checks = Check::query()
            ->whereIn('tasca_id', $module->tasks()->select('tasques.id'))
            ->with(['task.block', 'currentVersion'])
            ->orderBy('posicio')
            ->get();
        $states = CheckStudent::where('alumne_id', $student->id)->whereIn('check_id', $checks->modelKeys())
            ->with('teacherUpdatedBy')->get()->keyBy('check_id');
        $runs = CheckRun::where('alumne_id', $student->id)->whereIn('check_id', $checks->modelKeys())
            ->with('checkVersion')
            ->orderBy('data_execucio')->orderBy('id')
            ->get(['id', 'check_id', 'versio_check_id', 'estat', 'missatge', 'durada_ms', 'data_execucio'])
            ->groupBy('check_id');

        $rows = $checks->map(function (Check $check) use ($states, $runs) {
            /** @var Collection<int, CheckRun> $history */
            $history = $runs->get($check->id, collect());
            $state = $states->get($check->id);

            return [
                'check' => $check,
                'state' => $state,
                'assolit' => $state?->isPassing() ?? false,
                'corregit' => $state?->estat_professor !== null,
                'compta' => Leaderboard::counts($check),
                'punts' => Leaderboard::points($check, $state),
                'a_temps' => $state?->data_primer_assoliment && $check->data_limit ? $state->data_primer_assoliment->lessThanOrEqualTo($check->data_limit) : null,
                'darrera' => $history->last(),
                'execucions' => $history->count(),
                'canvis' => self::changes($history),
            ];
        });

        // La sortida només de la darrera execució de cada check (pot ser llarga).
        $outputs = CheckRun::whereKey($rows->pluck('darrera')->filter()->map(fn ($run) => $run->id)->values()->all())->pluck('sortida', 'id');
        $rows = $rows->map(fn (array $r) => [...$r, 'sortida' => $r['darrera'] ? $outputs->get($r['darrera']->id) : null]);

        $rows = $rows->filter(fn (array $r) => match (true) {
            ($filters['bloc'] ?? null) && $r['check']->task->bloc_id !== (int) $filters['bloc'] => false,
            ($filters['tasca'] ?? null) && $r['check']->tasca_id !== (int) $filters['tasca'] => false,
            ($filters['obligatoris'] ?? false) && $r['check']->tipus !== CheckKind::Required => false,
            default => match ($filters['estat'] ?? null) {
                'pendents' => ! $r['assolit'],
                'assolits' => $r['assolit'],
                'error' => $r['darrera'] !== null && ! in_array($r['darrera']->estat, [CheckStatus::Pass, CheckStatus::Fail], true),
                default => true,
            },
        });

        return $rows->groupBy(fn (array $r) => $r['check']->task->bloc_id)
            ->map(fn (Collection $blockRows) => [
                'block' => $blockRows->first()['check']->task->block,
                'tasks' => $blockRows->groupBy(fn (array $r) => $r['check']->tasca_id)
                    ->map(fn (Collection $taskRows) => [
                        'task' => $taskRows->first()['check']->task,
                        'assolits' => $taskRows->where('assolit', true)->count(),
                        'total' => $taskRows->count(),
                        'rows' => $taskRows->values()->all(),
                    ])
                    ->sortBy(fn (array $t) => [$t['task']->posicio, $t['task']->id])
                    ->values()->all(),
            ])
            ->sortBy(fn (array $b) => [$b['block']->numero, $b['block']->id])
            ->values()->all();
    }

    /**
     * Les execucions en què l'estat va canviar respecte de l'anterior (la primera sempre hi és),
     * de la més recent a la més antiga: si cada dia surt el mateix, només n'hi ha una.
     *
     * @param  Collection<int, CheckRun>  $history  ordenat de la més antiga a la més recent
     * @return list<array{estat: CheckStatus, data: CarbonInterface, missatge: ?string, versio: ?int, execucions: int}>
     */
    public static function changes(Collection $history): array
    {
        $changes = [];
        foreach ($history as $run) {
            $last = array_key_last($changes);
            if ($last !== null && $changes[$last]['estat'] === $run->estat) {
                $changes[$last]['execucions']++;

                continue;
            }
            $changes[] = ['estat' => $run->estat, 'data' => $run->data_execucio, 'missatge' => $run->missatge, 'versio' => $run->checkVersion?->versio, 'execucions' => 1];
        }

        return array_reverse($changes);
    }
}
