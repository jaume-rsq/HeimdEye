<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Com va la incidència d'un alumne: s'injecta (pendent), es comprova que ha trencat alguna cosa
 * (verificant), l'alumne la investiga (oberta) i acaba resolta, o el professor la tanca o la repara.
 */
enum IncidentStatus: string implements HasColor, HasLabel
{
    case Pendent = 'pendent';         // l'acció s'està executant o espera l'agent
    case Verificant = 'verificant';   // injectada; esperant el primer check (ha de fallar)
    case Oberta = 'oberta';           // l'alumne l'ha de resoldre
    case Resolta = 'resolta';         // el check ha passat després de la injecció
    case Error = 'error';             // l'acció ha fallat: no s'ha injectat res segur
    case NoEfectiva = 'no_efectiva';  // el check ja passava just després d'injectar-la
    case Caducada = 'caducada';       // l'agent no ha recollit l'acció a temps
    case Tancada = 'tancada';         // tancada pel professor sense resoldre
    case Reparada = 'reparada';       // el professor hi ha llançat l'acció de reparació

    /** Encara en marxa: un alumne no en pot rebre una altra de la mateixa incidència. */
    public function isActive(): bool
    {
        return in_array($this, [self::Pendent, self::Verificant, self::Oberta], true);
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return array_values(array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->isActive())));
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Pendent => 'pendent',
            self::Verificant => 'verificant',
            self::Oberta => 'oberta',
            self::Resolta => 'resolta',
            self::Error => 'error',
            self::NoEfectiva => 'no efectiva',
            self::Caducada => 'caducada',
            self::Tancada => 'tancada',
            self::Reparada => 'reparada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Resolta => 'success',
            self::Oberta => 'danger',
            self::Pendent, self::Verificant => 'gray',
            self::Error, self::NoEfectiva, self::Caducada => 'warning',
            self::Tancada, self::Reparada => 'info',
        };
    }
}
