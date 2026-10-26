<?php

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

/**
 * Estat global d'una acció segons la darrera execució real (no simulada) de cada alumne actiu.
 */
enum ActionOverview: string
{
    case Ok = 'ok';
    case Partial = 'parcial';
    case SomeFailed = 'alguna_fallada';
    case AllFailed = 'totes_fallades';
    case Never = 'mai';

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Ok => Heroicon::CheckCircle,
            self::Partial => Heroicon::EllipsisHorizontalCircle,
            self::SomeFailed => Heroicon::ExclamationTriangle,
            self::AllFailed => Heroicon::XCircle,
            self::Never => Heroicon::NoSymbol,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Partial => 'info',
            self::SomeFailed => 'warning',
            self::AllFailed => 'danger',
            self::Never => 'gray',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Executada per a tots els alumnes',
            self::Partial => 'Encara no executada per a tots els alumnes amb la versió vigent',
            self::SomeFailed => 'Ha fallat per a algun alumne',
            self::AllFailed => 'Ha fallat per a tots els alumnes',
            self::Never => 'No s\'ha executat mai',
        };
    }
}
