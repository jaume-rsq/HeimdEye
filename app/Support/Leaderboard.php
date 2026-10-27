<?php

namespace App\Support;

use App\Enums\CheckKind;
use App\Models\Block;
use App\Models\Check;
use App\Models\CheckStudent;
use App\Models\Module;
use App\Models\Student;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Classificació d'un mòdul. Compten els checks que ja han començat (no els desactivats a mà ni
 * els que encara no han arribat a la data d'inici). Cada check assolit dona els seus punts, amb
 * el bonus si es va assolir abans de la data límit; l'estat del professor mana (assolit sense
 * data: punts sense bonus; no assolit: 0). En cas d'empat, va davant qui hi va arribar abans.
 */
class Leaderboard
{
    /**
     * @return array{
     *     blocks: list<array{block: Block, checks: int}>,
     *     rows: list<array{posicio: int, student: Student, nom_public: string, punts: float, obligatoris: int, obligatoris_total: int, percentatge: int, blocs: array<int, array{assolits: int, total: int, percentatge: int}>, darrer_assoliment: ?CarbonInterface}>,
     *     punts_maxims: float,
     * }
     */
    public function build(Module $module, ?CarbonInterface $day = null): array
    {
        $day ??= Carbon::today();
        $checks = Check::query()
            ->whereIn('tasca_id', $module->tasks()->select('tasques.id'))
            ->with('task')
            ->get()
            ->filter(fn (Check $c) => self::counts($c, $day))
            ->keyBy('id');
        $blocks = $module->blocks()->orderBy('numero')->get()
            ->map(fn (Block $b) => ['block' => $b, 'checks' => $checks->where('task.bloc_id', $b->id)->count()])
            ->filter(fn (array $b) => $b['checks'] > 0)
            ->values();
        $required = $checks->where('tipus', CheckKind::Required);

        $students = $module->students()->where('es_actiu', true)->orderBy('num_taula')->get();
        $states = CheckStudent::whereIn('check_id', $checks->keys())
            ->whereIn('alumne_id', $students->modelKeys())
            ->get()
            ->groupBy('alumne_id');

        $rows = $students->map(function (Student $student) use ($checks, $blocks, $required, $states) {
            /** @var Collection<int, CheckStudent> $passed */
            $passed = ($states[$student->id] ?? collect())->filter->isPassing()->keyBy('check_id');
            $points = $passed->sum(fn (CheckStudent $s) => self::points($checks[$s->check_id], $s));
            $requiredDone = $required->filter(fn (Check $c) => $passed->has($c->id))->count();

            return [
                'student' => $student,
                'nom_public' => filled($student->alies) ? $student->alies : sprintf('Taula %02d', $student->num_taula),
                'punts' => round((float) $points, 2),
                'obligatoris' => $requiredDone,
                'obligatoris_total' => $required->count(),
                'percentatge' => $required->isEmpty() ? 0 : (int) floor(100 * $requiredDone / $required->count()),
                'blocs' => $blocks->mapWithKeys(function (array $b) use ($checks, $passed) {
                    $done = $checks->where('task.bloc_id', $b['block']->id)->filter(fn (Check $c) => $passed->has($c->id))->count();

                    return [$b['block']->id => ['assolits' => $done, 'total' => $b['checks'], 'percentatge' => (int) floor(100 * $done / $b['checks'])]];
                })->all(),
                'darrer_assoliment' => $passed->pluck('data_primer_assoliment')->filter()->max(),
            ];
        })
            ->sort(fn (array $a, array $b) => [$b['punts'], $a['darrer_assoliment']?->getTimestamp() ?? PHP_INT_MAX, $a['student']->num_taula]
                <=> [$a['punts'], $b['darrer_assoliment']?->getTimestamp() ?? PHP_INT_MAX, $b['student']->num_taula])
            ->values()
            ->map(fn (array $row, int $i) => ['posicio' => $i + 1, ...$row])
            ->all();

        return [
            'blocks' => $blocks->all(),
            'rows' => $rows,
            'punts_maxims' => (float) $checks->sum(fn (Check $c) => self::maxPoints($c)),
        ];
    }

    /** Punts que dona ara un check a un alumne. Sense data d'assoliment (l'ha marcat el professor): sense bonus. */
    public static function points(Check $check, ?CheckStudent $state): float
    {
        if ($state === null || ! $state->isPassing()) {
            return 0;
        }

        return $state->data_primer_assoliment ? $check->pointsFor($state->data_primer_assoliment) : (float) $check->punts;
    }

    /** Punts màxims d'un check: amb el bonus, si té data límit. */
    public static function maxPoints(Check $check): float
    {
        return $check->data_limit ? $check->pointsFor($check->data_limit) : (float) $check->punts;
    }

    /** Un check compta si està activat a mà, o si no està desactivat i ja ha començat. */
    public static function counts(Check $check, ?CarbonInterface $day = null): bool
    {
        if ($check->es_actiu !== null) {
            return $check->es_actiu;
        }

        return $check->actiu_des === null || $check->actiu_des->toDateString() <= ($day ?? Carbon::today())->toDateString();
    }
}
