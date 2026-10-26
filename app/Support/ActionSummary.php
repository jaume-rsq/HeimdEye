<?php

namespace App\Support;

use App\Enums\ActionOverview;
use App\Enums\ActionStatus;
use App\Models\Action;
use Illuminate\Support\Facades\DB;

/**
 * Recompte per estat de la darrera execució real (no simulada) de cada alumne actiu
 * matriculat al mòdul de l'acció. Les simulacions només surten a l'historial.
 * Una execució bona amb una versió anterior a la vigent compta a part (outdated): l'alumne
 * està bé, però encara no té la versió vigent.
 */
final class ActionSummary
{
    /** @param  array<string, int>  $counts  estat => alumnes */
    public function __construct(
        public readonly int $students,
        public readonly array $counts,
        public readonly int $outdated = 0,
    ) {}

    public static function for(Action $action): self
    {
        return self::forMany([$action->id])[$action->id];
    }

    /**
     * @param  list<int>|null  $actionIds  null = totes les accions
     * @return array<int, self> per id d'acció
     */
    public static function forMany(?array $actionIds = null): array
    {
        $actions = DB::table('accions')
            ->when($actionIds !== null, fn ($q) => $q->whereIn('accions.id', $actionIds))
            ->leftJoin('modul_alumne', 'modul_alumne.modul_id', '=', 'accions.modul_id')
            ->leftJoin('alumnes', fn ($join) => $join->on('alumnes.id', '=', 'modul_alumne.alumne_id')->where('alumnes.es_actiu', true))
            ->groupBy('accions.id')
            ->selectRaw('accions.id, count(alumnes.id) as n')
            ->pluck('n', 'accions.id');

        $latest = DB::table('execucions_accio')
            ->where('es_simulacio', false)
            ->when($actionIds !== null, fn ($q) => $q->whereIn('accio_id', $actionIds))
            ->groupBy('accio_id', 'alumne_id')
            ->selectRaw('max(id)');

        $runs = fn () => DB::table('execucions_accio as r')
            ->join('accions', 'accions.id', '=', 'r.accio_id')
            ->join('alumnes', fn ($join) => $join->on('alumnes.id', '=', 'r.alumne_id')->where('alumnes.es_actiu', true))
            ->join('modul_alumne', fn ($join) => $join->on('modul_alumne.alumne_id', '=', 'r.alumne_id')->on('modul_alumne.modul_id', '=', 'accions.modul_id'))
            ->whereIn('r.id', $latest);

        $counts = $runs()
            ->groupBy('r.accio_id', 'r.estat')
            ->selectRaw('r.accio_id, r.estat, count(*) as n')
            ->get()
            ->groupBy('accio_id');

        $current = DB::table('versions_accio')->groupBy('accio_id')->selectRaw('max(id)');
        $outdated = $runs()
            ->whereIn('r.estat', [ActionStatus::Ok->value, ActionStatus::Changed->value])
            ->whereNotIn('r.versio_accio_id', $current)
            ->groupBy('r.accio_id')
            ->selectRaw('r.accio_id, count(*) as n')
            ->pluck('n', 'accio_id');

        return $actions->map(fn ($n, $id) => new self(
            (int) $n,
            ($counts[$id] ?? collect())->mapWithKeys(fn ($row) => [$row->estat => (int) $row->n])->all(),
            (int) ($outdated[$id] ?? 0),
        ))->all();
    }

    /**
     * Darrera execució real de cada alumne actiu del mòdul, per a la graella de l'acció.
     *
     * @return list<array{id: int, taula: string, nom: string, estat: ?ActionStatus, versio: ?int, vigent: bool, data: ?string}>
     */
    public static function students(Action $action): array
    {
        $current = $action->currentVersion?->id;
        $latest = DB::table('execucions_accio')
            ->where('accio_id', $action->id)
            ->where('es_simulacio', false)
            ->groupBy('alumne_id')
            ->selectRaw('max(id)');
        $runs = $action->runs()->whereIn('id', $latest)->with('version')->get()->keyBy('alumne_id');

        return $action->module->students()->where('es_actiu', true)->orderBy('num_taula')->get()
            ->map(function ($student) use ($runs, $current) {
                $run = $runs->get($student->id);

                return [
                    'id' => $student->id,
                    'taula' => sprintf('%02d', $student->num_taula),
                    'nom' => $student->nom_complet,
                    'estat' => $run?->estat,
                    'versio' => $run?->version?->versio,
                    'vigent' => $run !== null && $run->versio_accio_id === $current,
                    'data' => $run?->data_inici?->format('d/m H:i'),
                ];
            })->all();
    }

    /**
     * Alumnes actius amb una execució bona feta amb una versió anterior a la vigent.
     *
     * @return list<int>
     */
    public static function outdatedStudentIds(Action $action): array
    {
        return collect(self::students($action))
            ->filter(fn (array $s) => $s['estat'] !== null && ! $s['estat']->isFailure() && ! $s['vigent'])
            ->pluck('id')
            ->values()
            ->all();
    }

    public function failures(): int
    {
        return array_sum(array_intersect_key($this->counts, array_flip(ActionStatus::failureValues())));
    }

    /** Alumnes actius que encara no l'han executada mai. */
    public function pending(): int
    {
        return max(0, $this->students - array_sum($this->counts));
    }

    public function overview(): ActionOverview
    {
        return match (true) {
            $this->counts === [] || $this->students === 0 => ActionOverview::Never,
            $this->failures() >= $this->students => ActionOverview::AllFailed,
            $this->failures() > 0 => ActionOverview::SomeFailed,
            $this->pending() > 0 || $this->outdated > 0 => ActionOverview::Partial,
            default => ActionOverview::Ok,
        };
    }

    /** Tots els alumnes actius (o, amb $all = false, almenys un) tenen aquest estat a la darrera execució. */
    public function has(ActionStatus $status, bool $all): bool
    {
        $n = $this->counts[$status->value] ?? 0;

        return $all ? $this->students > 0 && $n >= $this->students : $n > 0;
    }

    /** Text curt per al tooltip, p. ex. "14 ok · 2 failed · 1 sense executar". */
    public function describe(): string
    {
        $parts = collect($this->counts)->sortKeys()->map(fn ($n, $status) => "{$n} {$status}")->values();
        if ($this->pending() > 0) {
            $parts->push("{$this->pending()} sense executar");
        }
        if ($this->outdated > 0) {
            $parts->push("{$this->outdated} amb una versió anterior");
        }

        return $parts->isEmpty() ? 'Cap alumne' : $parts->implode(' · ');
    }
}
