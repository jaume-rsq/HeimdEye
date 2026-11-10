<?php

namespace App\Support;

use App\Models\Action;
use App\Models\Check;
use App\Models\IncidentOption;

/**
 * Què de les incidències no ha de veure l'alumne. Els checks de resolució i les accions d'avaria i de
 * reparació diuen quina és l'avaria (p. ex. «nginx en marxa»): a l'alumne (el seu seguiment i el
 * registre de l'agent) se li mostra un nom neutre.
 */
class IncidentMask
{
    public const LABEL = 'Incidència';

    public static function isResolutionCheck(Check|int $check): bool
    {
        return IncidentOption::where('check_id', $check instanceof Check ? $check->id : $check)->exists();
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
