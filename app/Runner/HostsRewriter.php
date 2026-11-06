<?php

namespace App\Runner;

use App\Enums\Executor;

/**
 * Canvia els equips on va el codi sense tocar-ne la resta: la capçalera "# hosts:" (ssh i agent)
 * o el "hosts:" de cada play (Ansible). Serveix per provar un check de la biblioteca sobre uns
 * altres equips i per copiar-lo a una tasca real. En local no hi ha equips: el codi no canvia.
 */
final class HostsRewriter
{
    /** @param  list<string>  $hosts  grups (o rols d'agent) on ha d'anar el codi */
    public static function rewrite(Executor $executor, string $code, array $hosts): string
    {
        $hosts = array_values(array_filter(array_map('trim', $hosts)));
        if ($hosts === []) {
            return $code;
        }
        $value = implode(':', $hosts);

        return match ($executor) {
            Executor::Ssh, Executor::Agent => self::header($code, $value),
            // Només el "hosts:" dels plays (a l'inici de la línia o com a primer camp d'un element
            // de la llista de plays), no una clau que es digui igual dins d'una tasca.
            Executor::Ansible => preg_replace('/^(-[ \t]+|[ \t]{2})hosts:[ \t]*\S.*$/m', '$1hosts: '.$value, $code),
            default => $code,
        };
    }

    private static function header(string $code, string $value): string
    {
        $replaced = preg_replace('/^([ \t]*#[ \t]*hosts[ \t]*:).*$/mi', '$1 '.$value, $code, 1, $count);
        if ($count > 0 && CodeHeaders::parse($replaced)->hosts === explode(':', $value)) {
            return $replaced;
        }

        // Sense capçalera (o no era a les primeres línies): se n'hi afegeix una al principi, darrere
        // del shebang si n'hi ha.
        if (str_starts_with($code, '#!')) {
            [$shebang, $rest] = array_pad(explode("\n", $code, 2), 2, '');

            return "{$shebang}\n# hosts: {$value}\n{$rest}";
        }

        return "# hosts: {$value}\n{$code}";
    }
}
