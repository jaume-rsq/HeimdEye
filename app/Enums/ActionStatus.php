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

    /** Tot el que no és ok ni changed compta com a fallada. */
    public function isFailure(): bool
    {
        return ! in_array($this, [self::Ok, self::Changed], true);
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
            self::Unavailable, self::Timeout => 'warning',
        };
    }
}
