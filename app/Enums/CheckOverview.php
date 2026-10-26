<?php

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

/**
 * Estat global d'un check segons la darrera execució de cada alumne actiu.
 */
enum CheckOverview: string
{
    case Ok = 'assolit';
    case Partial = 'parcial';
    case NonePassed = 'cap';
    case Problems = 'problemes';
    case Never = 'mai';

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Ok => Heroicon::CheckCircle,
            self::Partial => Heroicon::EllipsisHorizontalCircle,
            self::NonePassed => Heroicon::XCircle,
            self::Problems => Heroicon::ExclamationTriangle,
            self::Never => Heroicon::NoSymbol,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Partial => 'info',
            self::NonePassed => 'danger',
            self::Problems => 'warning',
            self::Never => 'gray',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Assolit per tots els alumnes amb la versió vigent',
            self::Partial => 'Encara no assolit per tots els alumnes, o comprovat amb una versió anterior',
            self::NonePassed => 'Cap alumne l\'ha assolit',
            self::Problems => 'No s\'ha pogut comprovar per a algun alumne (error, unavailable o timeout)',
            self::Never => 'No s\'ha executat mai',
        };
    }
}
