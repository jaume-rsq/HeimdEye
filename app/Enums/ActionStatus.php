<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Resultat d'una acció per a un alumne, a partir del resum d'Ansible.
 */
enum ActionStatus: string implements HasColor, HasLabel
{
    case Ok = 'ok';
    case Changed = 'changed';
    case Failed = 'failed';
    case Unavailable = 'unavailable';
    case Error = 'error';
    case Timeout = 'timeout';
    // Feina d'agent: esperant que l'agent la reculli, o caducada sense recollir a temps.
    case Pendent = 'pendent';
    case Caducada = 'caducada';

    /** Tot el que no és ok ni changed compta com a fallada, tret del que encara està pendent. */
    public function isFailure(): bool
    {
        return ! in_array($this, [self::Ok, self::Changed, self::Pendent], true);
    }

    /** Feina d'agent encara sense resultat final. */
    public function isPending(): bool
    {
        return $this === self::Pendent;
    }

    /** @return list<string> */
    public static function failureValues(): array
    {
        return array_values(array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->isFailure())));
    }

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Changed => 'info',
            self::Failed, self::Error => 'danger',
            self::Unavailable, self::Timeout, self::Caducada => 'warning',
            self::Pendent => 'gray',
        };
    }
}
