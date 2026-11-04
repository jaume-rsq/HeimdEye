<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Group;
use App\Models\Module;
use App\Support\Leaderboard;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Leaderboard públic: sense login i sense noms (àlies o "Taula NN"). Només el curs actual. Es tria
 * el grup-classe i, d'aquest, un mòdul on hi hagi algun alumne del grup matriculat.
 */
class LeaderboardController
{
    public function __invoke(Request $request, Leaderboard $leaderboard): View
    {
        $groups = self::groups();
        $group = $groups->firstWhere('id', (int) $request->query('grup')) ?? $groups->first();
        $modules = self::modules($group?->id);
        $module = $modules->firstWhere('id', (int) $request->query('modul')) ?? $modules->first();

        return view('leaderboard.public', [
            'groups' => $groups,
            'group' => $group,
            'modules' => $modules,
            'module' => $module,
            'board' => $module ? $leaderboard->build($module, groupId: $group?->id) : null,
        ]);
    }

    /** @return Collection<int, Module> mòduls del curs actual (amb un grup, els que hi tenen algun alumne) */
    public static function modules(?int $groupId = null): Collection
    {
        $year = AcademicYear::where('es_actual', true)->first();

        return $year ? Module::where('curs_id', $year->id)->withGroup($groupId)->orderBy('codi')->get() : collect();
    }

    /** @return Collection<int, Group> grups del curs actual amb algun alumne matriculat a un mòdul */
    public static function groups(): Collection
    {
        $year = AcademicYear::where('es_actual', true)->first();

        return $year ? Group::where('curs_id', $year->id)->withStudentsIn(self::modules()->modelKeys())->orderBy('codi')->get() : collect();
    }
}
