<?php

namespace App\Support;

use App\Enums\CheckKind;
use App\Models\Action;
use App\Models\Check;
use App\Models\IncidentOption;
use App\Models\IncidentStudent;
use App\Models\Module;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Què de les incidències no ha de veure l'alumne. Els checks de resolució i les accions d'avaria i de
 * reparació diuen quina és l'avaria (p. ex. «nginx en marxa»): a l'alumne (el seu seguiment i el
 * registre de l'agent) se li mostra un nom neutre.
 */
class IncidentMask
{
    public const LABEL = 'Incidència';

    /** Un check d'incidència, o qualsevol altre que alguna opció fa servir per saber si s'ha resolt. */
    public static function isResolutionCheck(Check|int $check): bool
    {
        $model = $check instanceof Check ? $check : Check::find($check);

        return (bool) $model?->isIncident()
            || IncidentOption::where('check_id', $check instanceof Check ? $check->id : $check)->exists();
    }

    /**
     * Els checks de resolució d'un mòdul (els d'incidència i els que fa servir alguna opció).
     *
     * @return Collection<int, int> id de check => id de tasca
     */
    public static function resolutionChecks(Module $module): Collection
    {
        return Check::query()
            ->whereIn('tasca_id', $module->tasks()->select('tasques.id'))
            ->where(fn (Builder $q) => $q
                ->where('tipus', CheckKind::Incident->value)
                ->orWhereIn('id', IncidentOption::whereNotNull('check_id')->select('check_id')))
            ->pluck('tasca_id', 'id');
    }

    /**
     * La darrera avaria de l'alumne per a cada check de resolució.
     *
     * @param  bool  $visibleOnly  només les que l'alumne ja pot veure
     * @return Collection<int, IncidentStudent> id de check => avaria
     */
    public static function assignments(Student $student, bool $visibleOnly = false): Collection
    {
        return IncidentStudent::where('alumne_id', $student->id)
            ->with(['option', 'incident', 'launch'])->orderBy('id')->get()
            ->filter(fn (IncidentStudent $r) => $r->option?->check_id && (! $visibleOnly || $r->estat->isVisibleToStudent()))
            ->keyBy(fn (IncidentStudent $r) => $r->option->check_id);
    }

    public static function isIncidentAction(Action $action): bool
    {
        return $action->es_incidencia || IncidentOption::where('accio_reparacio_id', $action->id)->exists();
    }

    /** Nom que veu l'alumne a l'agent. */
    public static function checkName(?Check $check): string
    {
        return $check === null ? 'check' : (self::isResolutionCheck($check) ? self::LABEL : $check->nom);
    }

    public static function actionName(?Action $action): string
    {
        return $action === null ? 'acció' : (self::isIncidentAction($action) ? self::LABEL : $action->nom);
    }
}
