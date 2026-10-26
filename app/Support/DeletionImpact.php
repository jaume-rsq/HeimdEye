<?php

namespace App\Support;

use App\Models\Action;
use App\Models\Check;

/**
 * Text de l'avís en esborrar un check o una acció: no hi ha paperera, s'esborra tot el que en
 * depèn (versions, execucions i, d'un check, l'estat i els assoliments dels alumnes).
 */
final class DeletionImpact
{
    public static function check(Check $check): string
    {
        $passed = $check->states()->whereNotNull('data_primer_assoliment')->count();
        $actions = Action::with('currentVersion')->get()
            ->filter(fn (Action $a) => in_array($check->id, array_map('intval', $a->currentVersion?->checks_verificacio ?? []), true))
            ->pluck('nom');

        return implode(' ', array_filter([
            "S'esborraran {$check->versions()->count()} versions, {$check->runs()->count()} execucions de "
                .$check->runs()->distinct()->count('alumne_id').' alumnes i l\'estat del check per a cada alumne'
                .($passed ? ", amb els assoliments de {$passed} alumnes (i els seus punts)" : '').'.',
            $actions->isNotEmpty() ? 'Les accions '.$actions->implode(', ').' el fan servir per verificar i deixaran de fer-ho.' : null,
            'No es pot desfer.',
        ]));
    }

    public static function action(Action $action): string
    {
        return "S'esborraran {$action->versions()->count()} versions i {$action->runs()->count()} execucions de "
            .$action->runs()->distinct()->count('alumne_id').' alumnes. No es pot desfer.';
    }
}
