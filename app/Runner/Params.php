<?php

namespace App\Runner;

use InvalidArgumentException;

/**
 * Paràmetres declarats a la versió: [{name, label, default}]. El codi els rep com a
 * HE_PARAM_<NOM> (local i ssh) o com a variable Ansible <NOM>. Els noms van en majúscules.
 */
final class Params
{
    public const NAME_RULE = '/^[A-Z_][A-Z0-9_]*$/';

    /** Nom d'un paràmetre tal com es desa: en majúscules i sense espais als extrems. */
    public static function name(?string $name): string
    {
        return strtoupper(trim((string) $name));
    }

    /**
     * Valors a fer servir: els donats o, si no n'hi ha, el valor per defecte.
     * Un check s'executa sol (cada nit), així que tots els seus paràmetres necessiten valor per defecte.
     *
     * @param  list<array<string, mixed>>|null  $declared
     * @param  array<string, string>  $given
     * @return array<string, string>
     *
     * @throws InvalidArgumentException si hi ha un paràmetre desconegut o sense valor
     */
    public static function values(?array $declared, array $given = []): array
    {
        $declared = collect($declared ?? [])->keyBy('name');
        if ($unknown = array_diff(array_keys($given), $declared->keys()->all())) {
            throw new InvalidArgumentException('Paràmetre desconegut: '.implode(', ', $unknown));
        }

        $values = [];
        foreach ($declared as $name => $param) {
            $value = $given[$name] ?? $param['default'] ?? null;
            if ($value === null) {
                throw new InvalidArgumentException("Falta el valor del paràmetre {$name}");
            }
            $values[$name] = (string) $value;
        }

        return $values;
    }
}
