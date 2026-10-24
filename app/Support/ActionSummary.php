<?php

namespace App\Support;

use App\Enums\ActionOverview;
use App\Enums\ActionStatus;
use App\Models\Action;
use Illuminate\Support\Facades\DB;

/**
 * Recompte per estat de la darrera execució real (no simulada) de cada alumne actiu
 * matriculat al mòdul de l'acció. Les simulacions només surten a l'historial.
 */
final class ActionSummary
{
    /** @param  array<string, int>  $counts  estat => alumnes */
    public function __construct(
        public readonly int $students,
        public readonly array $counts,
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

        $counts = DB::table('execucions_accio as r')
            ->join('accions', 'accions.id', '=', 'r.accio_id')
            ->join('alumnes', fn ($join) => $join->on('alumnes.id', '=', 'r.alumne_id')->where('alumnes.es_actiu', true))
            ->join('modul_alumne', fn ($join) => $join->on('modul_alumne.alumne_id', '=', 'r.alumne_id')->on('modul_alumne.modul_id', '=', 'accions.modul_id'))
            ->whereIn('r.id', $latest)
            ->groupBy('r.accio_id', 'r.estat')
            ->selectRaw('r.accio_id, r.estat, count(*) as n')
            ->get()
            ->groupBy('accio_id');

        return $actions->map(fn ($n, $id) => new self(
            (int) $n,
            ($counts[$id] ?? collect())->mapWithKeys(fn ($row) => [$row->estat => (int) $row->n])->all(),
        ))->all();
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
            $this->pending() > 0 => ActionOverview::Partial,
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

        return $parts->isEmpty() ? 'Cap alumne' : $parts->implode(' · ');
    }
}
