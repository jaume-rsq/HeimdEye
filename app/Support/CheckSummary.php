<?php

namespace App\Support;

use App\Enums\CheckOverview;
use App\Enums\CheckStatus;
use App\Filament\Pages\StudentTracking;
use App\Models\Check;
use Illuminate\Support\Facades\DB;

/**
 * Recompte per estat de la darrera execució de cada alumne actiu matriculat al mòdul del check,
 * com ActionSummary per a les accions. Una execució amb una versió anterior a la vigent compta
 * a part (outdated): encara no s'ha comprovat amb el codi corregit. Les execucions omeses no compten
 * com a darrera execució.
 */
final class CheckSummary
{
    /** @param  array<string, int>  $counts  estat => alumnes */
    public function __construct(
        public readonly int $students,
        public readonly array $counts,
        public readonly int $outdated = 0,
    ) {}

    public static function for(Check $check): self
    {
        return self::forMany([$check->id])[$check->id];
    }

    /**
     * @param  list<int>|null  $checkIds  null = tots els checks
     * @return array<int, self> per id de check
     */
    public static function forMany(?array $checkIds = null): array
    {
        $checks = DB::table('checks')
            ->when($checkIds !== null, fn ($q) => $q->whereIn('checks.id', $checkIds))
            ->join('tasques', 'tasques.id', '=', 'checks.tasca_id')
            ->join('blocs', 'blocs.id', '=', 'tasques.bloc_id')
            ->leftJoin('modul_alumne', 'modul_alumne.modul_id', '=', 'blocs.modul_id')
            ->leftJoin('alumnes', fn ($join) => $join->on('alumnes.id', '=', 'modul_alumne.alumne_id')->where('alumnes.es_actiu', true))
            ->groupBy('checks.id')
            ->selectRaw('checks.id, count(alumnes.id) as n')
            ->pluck('n', 'checks.id');

        $latest = DB::table('execucions_check')
            ->when($checkIds !== null, fn ($q) => $q->whereIn('check_id', $checkIds))
            ->where('estat', '!=', CheckStatus::Skipped->value)
            ->groupBy('check_id', 'alumne_id')
            ->selectRaw('max(id)');

        $runs = fn () => DB::table('execucions_check as r')
            ->join('checks', 'checks.id', '=', 'r.check_id')
            ->join('tasques', 'tasques.id', '=', 'checks.tasca_id')
            ->join('blocs', 'blocs.id', '=', 'tasques.bloc_id')
            ->join('alumnes', fn ($join) => $join->on('alumnes.id', '=', 'r.alumne_id')->where('alumnes.es_actiu', true))
            ->join('modul_alumne', fn ($join) => $join->on('modul_alumne.alumne_id', '=', 'r.alumne_id')->on('modul_alumne.modul_id', '=', 'blocs.modul_id'))
            ->whereIn('r.id', $latest);

        $counts = $runs()
            ->groupBy('r.check_id', 'r.estat')
            ->selectRaw('r.check_id, r.estat, count(*) as n')
            ->get()
            ->groupBy('check_id');

        $current = DB::table('versions_check')->groupBy('check_id')->selectRaw('max(id)');
        $outdated = $runs()
            ->where(fn ($q) => $q->whereNull('r.versio_check_id')->orWhereNotIn('r.versio_check_id', $current))
            ->groupBy('r.check_id')
            ->selectRaw('r.check_id, count(*) as n')
            ->pluck('n', 'check_id');

        $counts = $counts->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [$row->estat => (int) $row->n])->all());
        $outdated = $outdated->map(fn ($n) => (int) $n);

        // La correcció del professor mana: l'alumne compta com a assolit o no assolit, i no com a
        // pendent de tornar a comprovar, sigui quina sigui la darrera execució.
        $corrections = DB::table('check_alumne as ca')
            ->when($checkIds !== null, fn ($q) => $q->whereIn('ca.check_id', $checkIds))
            ->whereNotNull('ca.estat_professor')
            ->join('checks', 'checks.id', '=', 'ca.check_id')
            ->join('tasques', 'tasques.id', '=', 'checks.tasca_id')
            ->join('blocs', 'blocs.id', '=', 'tasques.bloc_id')
            ->join('alumnes', fn ($join) => $join->on('alumnes.id', '=', 'ca.alumne_id')->where('alumnes.es_actiu', true))
            ->join('modul_alumne', fn ($join) => $join->on('modul_alumne.alumne_id', '=', 'ca.alumne_id')->on('modul_alumne.modul_id', '=', 'blocs.modul_id'))
            ->leftJoinSub(DB::table('execucions_check')
                ->where('estat', '!=', CheckStatus::Skipped->value)
                ->groupBy('check_id', 'alumne_id')
                ->selectRaw('check_id, alumne_id, max(id) as id'), 'darrera', fn ($join) => $join
                ->on('darrera.check_id', '=', 'ca.check_id')->on('darrera.alumne_id', '=', 'ca.alumne_id'))
            ->leftJoin('execucions_check as r', 'r.id', '=', 'darrera.id')
            ->select('ca.check_id', 'ca.estat_professor', 'r.estat', 'r.versio_check_id')
            ->get();
        $currentIds = DB::table('versions_check')->groupBy('check_id')->selectRaw('max(id) as id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($corrections as $row) {
            $id = $row->check_id;
            $byCheck = $counts[$id] ?? [];
            if ($row->estat !== null) {
                $byCheck[$row->estat]--;
                if ($row->versio_check_id === null || ! in_array((int) $row->versio_check_id, $currentIds, true)) {
                    $outdated[$id] = ($outdated[$id] ?? 0) - 1;
                }
            }
            $status = ($row->estat_professor ? CheckStatus::Pass : CheckStatus::Fail)->value;
            $byCheck[$status] = ($byCheck[$status] ?? 0) + 1;
            $counts[$id] = array_filter($byCheck);
        }

        return $checks->map(fn ($n, $id) => new self(
            (int) $n,
            $counts[$id] ?? [],
            (int) ($outdated[$id] ?? 0),
        ))->all();
    }

    /**
     * Darrera execució de cada alumne actiu del mòdul, per a la graella del check.
     *
     * @return list<array{id: int, taula: string, nom: string, estat: ?CheckStatus, versio: ?int, vigent: bool, data: ?string, correccio: ?bool}>
     */
    public static function students(Check $check): array
    {
        $current = $check->currentVersion?->id;
        $latest = DB::table('execucions_check')
            ->where('check_id', $check->id)
            ->where('estat', '!=', CheckStatus::Skipped->value)
            ->groupBy('alumne_id')
            ->selectRaw('max(id)');
        $runs = $check->runs()->whereIn('id', $latest)->with('checkVersion')->get()->keyBy('alumne_id');
        $corrections = $check->states()->whereNotNull('estat_professor')->pluck('estat_professor', 'alumne_id');
        // Qui només té execucions omeses (p. ex. ara respon l'altre router): es veu que s'ha provat.
        $skipped = $check->runs()->where('estat', CheckStatus::Skipped->value)->whereNotIn('alumne_id', $runs->keys())
            ->whereIn('id', DB::table('execucions_check')->where('check_id', $check->id)->groupBy('alumne_id')->selectRaw('max(id)'))
            ->with('checkVersion')->get()->keyBy('alumne_id');
        $runs = $runs->union($skipped);

        return $check->module->students()->where('es_actiu', true)->orderBy('num_taula')->get()
            ->map(function ($student) use ($runs, $current, $check, $corrections) {
                $run = $runs->get($student->id);

                return [
                    'id' => $student->id,
                    'taula' => sprintf('%02d', $student->num_taula),
                    'nom' => $student->nom_complet,
                    'estat' => $run?->estat,
                    'versio' => $run?->checkVersion?->versio,
                    'vigent' => $run !== null && $run->versio_check_id === $current,
                    'data' => $run?->data_execucio?->format('d/m H:i'),
                    // Correcció del professor (true assolit, false no assolit): mana sobre l'execució.
                    'correccio' => $corrections->has($student->id) ? (bool) $corrections->get($student->id) : null,
                    'url' => StudentTracking::getUrl(['alumne' => $student->id, 'tasca' => $check->tasca_id]),
                ];
            })->all();
    }

    /**
     * Alumnes actius la darrera execució dels quals és d'una versió anterior a la vigent, sigui
     * quin sigui el resultat: un check només llegeix, i la versió nova pot canviar-lo.
     *
     * @return list<int>
     */
    public static function outdatedStudentIds(Check $check): array
    {
        return collect(self::students($check))
            ->filter(fn (array $s) => $s['estat'] !== null && ! $s['vigent'])
            ->pluck('id')
            ->values()
            ->all();
    }

    /** Alumnes actius per als quals encara no s'ha executat mai. */
    public function pending(): int
    {
        return max(0, $this->students - array_sum($this->counts));
    }

    public function problems(): int
    {
        return array_sum(array_intersect_key($this->counts, array_flip(CheckStatus::problemValues())));
    }

    public function passed(): int
    {
        return $this->counts[CheckStatus::Pass->value] ?? 0;
    }

    public function overview(): CheckOverview
    {
        return match (true) {
            $this->counts === [] || $this->students === 0 => CheckOverview::Never,
            $this->problems() > 0 => CheckOverview::Problems,
            $this->passed() >= $this->students && $this->outdated === 0 => CheckOverview::Ok,
            $this->passed() === 0 => CheckOverview::NonePassed,
            default => CheckOverview::Partial,
        };
    }

    /** Tots els alumnes actius (o, amb $all = false, almenys un) tenen aquest estat a la darrera execució. */
    public function has(CheckStatus $status, bool $all): bool
    {
        $n = $this->counts[$status->value] ?? 0;

        return $all ? $this->students > 0 && $n >= $this->students : $n > 0;
    }

    /** Text curt per al tooltip, p. ex. "14 pass · 2 fail · 1 sense executar". */
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
