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
     * @return list<array{block: Block, resum: array<string, mixed>, tasks: list<array{task: Task, assolits: int, total: int, rows: list<array<string, mixed>>}>}>
     */
    public function build(Student $student, Module $module, array $filters = []): array
    {
        $checks = Check::query()
            ->whereIn('tasca_id', $module->tasks()->select('tasques.id'))
            ->with(['task.block', 'currentVersion'])
            ->orderByPosition()
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
            // Les omeses no canvien res per a l'alumne: ni són la darrera execució ni un canvi d'estat.
            $history = $runs->get($check->id, collect())->reject(fn (CheckRun $run) => $run->estat === CheckStatus::Skipped)->values();
            $state = $states->get($check->id);

            return [
                'check' => $check,
                'state' => $state,
                'assolit' => $state?->isPassing() ?? false,
                'corregit' => $state?->estat_professor !== null,
                'compta' => Leaderboard::counts($check),
                'punts' => Leaderboard::points($check, $state),
                'punts_maxims' => Leaderboard::maxPoints($check),
                'a_temps' => $state?->data_primer_assoliment && $check->data_limit ? $state->data_primer_assoliment->lessThanOrEqualTo($check->data_limit) : null,
                'darrera' => $history->last(),
                // Una execució omesa posterior a la darrera: es mostra, però no canvia res.
                'omesa' => self::laterSkipped($runs->get($check->id, collect()), $history->last()),
                'execucions' => $history->count(),
                'canvis' => self::withCorrection(self::changes($history), $state),
            ];
        });

        // La sortida només de la darrera execució de cada check (pot ser llarga).
        $outputs = CheckRun::whereKey($rows->pluck('darrera')->filter()->map(fn ($run) => $run->id)->values()->all())->pluck('sortida', 'id');
        $rows = $rows->map(fn (array $r) => [...$r, 'sortida' => $r['darrera'] ? $outputs->get($r['darrera']->id) : null]);

        // El resum de cada bloc és de tots els seus checks, encara que els filtres n'amaguin alguns.
        $summaries = $rows->groupBy(fn (array $r) => $r['check']->task->bloc_id)->map(fn (Collection $r) => self::summary($r));

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
                'resum' => $summaries->get($blockRows->first()['check']->task->bloc_id),
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
            ->sortBy(fn (array $b) => [$b['block']->posicio, $b['block']->numero, $b['block']->id])
            ->values()->all();
    }

    /**
     * Dades agregades d'un bloc, com al leaderboard: només compten els checks que ja han començat.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{assolits: int, total: int, obligatoris: int, obligatoris_total: int, percentatge: int, punts: float, punts_maxims: float, errors: int, darrer_assoliment: ?CarbonInterface}
     */
    private static function summary(Collection $rows): array
    {
        $counted = $rows->where('compta', true);
        $required = $counted->filter(fn (array $r) => $r['check']->tipus === CheckKind::Required);
        $requiredDone = $required->where('assolit', true)->count();

        return [
            'assolits' => $counted->where('assolit', true)->count(),
            'total' => $counted->count(),
            'obligatoris' => $requiredDone,
            'obligatoris_total' => $required->count(),
            'percentatge' => $required->isEmpty() ? 0 : (int) floor(100 * $requiredDone / $required->count()),
            'punts' => round((float) $counted->sum('punts'), 2),
            'punts_maxims' => round((float) $counted->sum('punts_maxims'), 2),
            'errors' => $counted->filter(fn (array $r) => $r['darrera'] !== null && ! in_array($r['darrera']->estat, [CheckStatus::Pass, CheckStatus::Fail], true))->count(),
            'darrer_assoliment' => $counted->where('assolit', true)->map(fn (array $r) => $r['state']?->data_primer_assoliment)->filter()->max(),
        ];
    }

    /** @param  Collection<int, CheckRun>  $all  ordenat de la més antiga a la més recent */
    private static function laterSkipped(Collection $all, ?CheckRun $last): ?CheckRun
    {
        $skipped = $all->last();

        return $skipped?->estat === CheckStatus::Skipped && $skipped->isNot($last) ? $skipped : null;
    }

    /**
     * Afegeix la darrera correcció del professor als canvis d'estat, al seu lloc per data. estat és
     * Pass (assolit), Fail (no assolit) o null (ha tornat a l'automàtic).
     *
     * @param  list<array<string, mixed>>  $changes  de la més recent a la més antiga
     * @return list<array<string, mixed>>
     */
    public static function withCorrection(array $changes, ?CheckStudent $state): array
    {
        if ($state?->data_correccio === null) {
            return $changes;
        }

        $changes[] = [
            'estat' => match ($state->estat_professor) {
                true => CheckStatus::Pass,
                false => CheckStatus::Fail,
                null => null,
            },
            'data' => $state->data_correccio,
            'missatge' => $state->comentari_professor,
            'versio' => null,
            'execucions' => 0,
            'professor' => $state->teacherUpdatedBy?->nom ?: 'professor',
        ];

        return collect($changes)->sortByDesc(fn (array $c) => $c['data']?->getTimestamp() ?? 0)->values()->all();
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
