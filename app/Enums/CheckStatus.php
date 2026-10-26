<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Resultat automàtic d'una execució. Només pass compta com a assolit.
 * fail vol dir que l'alumne encara no ho té; error, unavailable i timeout, que no s'ha pogut comprovar.
 * omes vol dir que ara el check no s'aplica (el codi ho diu amb HEIMDEYE_OMES:): queda a l'historial,
 * però no canvia l'estat de l'alumne ni compta com a darrera execució.
 */
enum CheckStatus: string implements HasColor, HasLabel
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Error = 'error';
    case Unavailable = 'unavailable';
    case Timeout = 'timeout';
    case Skipped = 'omes';

    /** Tot el que no és pass: l'alumne no el té assolit. */
    public function isFailure(): bool
    {
        return $this !== self::Pass;
    }

    /** No s'ha pogut comprovar (problema del check o de l'equip, no de l'alumne). */
    public function isProblem(): bool
    {
        return in_array($this, [self::Error, self::Unavailable, self::Timeout], true);
    }

    /** @return list<string> */
    public static function problemValues(): array
    {
        return array_values(array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->isProblem())));
    }

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pass => 'success',
            self::Fail => 'danger',
            self::Error, self::Unavailable, self::Timeout => 'warning',
            self::Skipped => 'gray',
        };
    }
}
