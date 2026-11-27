<?php

namespace App\Enums;

/**
 * Només els checks obligatoris compten per al percentatge de compleció. Els d'incidència diuen si
 * l'alumne ja ha arreglat una avaria: no puntuen, no surten a cap recompte, no s'executen amb la
 * ronda nocturna i l'alumne només els veu si ha rebut l'avaria.
 */
enum CheckKind: string
{
    case Required = 'obligatori';
    case Optional = 'opcional';
    case Incident = 'incidencia';

    public function label(): string
    {
        return match ($this) {
            self::Required => 'obligatori',
            self::Optional => 'opcional',
            self::Incident => 'incidència',
        };
    }
}
