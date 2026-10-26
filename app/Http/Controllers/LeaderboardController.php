<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Module;
use App\Support\Leaderboard;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/** Leaderboard públic: sense login i sense noms (àlies o "Taula NN"). Només mòduls del curs actual. */
class LeaderboardController
{
    public function __invoke(Request $request, Leaderboard $leaderboard): View
    {
        $modules = self::modules();
        $module = $modules->firstWhere('id', (int) $request->query('modul')) ?? $modules->first();

        return view('leaderboard.public', [
            'modules' => $modules,
            'module' => $module,
            'board' => $module ? $leaderboard->build($module) : null,
        ]);
    }

    /** @return Collection<int, Module> */
    public static function modules(): Collection
    {
        $year = AcademicYear::where('es_actual', true)->first();

        return $year ? Module::where('curs_id', $year->id)->orderBy('codi')->get() : collect();
    }
}
