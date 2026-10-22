<?php

namespace App\Support;

use App\Models\GlobalVar;
use App\Models\Module;
use App\Models\Student;
use InvalidArgumentException;

/**
 * Calcula les variables que rep un check per a un alumne.
 *
 * Prioritat (de menys a més): variables globals, variables derivades del mòdul,
 * camps fixos de l'alumne i variables lliures de l'alumne.
 */
class VariableResolver
{
    /** {{ var }} o {{ var | filtre(n) }} */
    private const MARKER = '/\{\{\s*([A-Za-z0-9_]+)\s*(?:\|\s*([a-z_]+)\(\s*(-?\d+)\s*\))?\s*\}\}/';

    /** @return array<string, string> */
    public function resolve(Student $student, Module $module): array
    {
        $own = array_merge(
            array_filter([
                'usuari' => $student->usuari,
                'nom_complet' => $student->nom_complet,
                'num_taula' => $student->num_taula === null ? null : sprintf('%02d', $student->num_taula),
                'x' => $student->x,
                'email' => $student->user?->email,
            ], fn ($value) => $value !== null),
            $student->variables ?? [],
        );

        $base = array_merge(GlobalVar::query()->pluck('valor', 'clau')->all(), $own);

        $derived = array_map(
            fn (string $template) => $this->render($template, $base),
            $module->variables_derivades ?? [],
        );

        return array_map('strval', array_merge($base, $derived, $own));
    }

    /**
     * Variables d'entorn per a l'script del check: ns1 → HE_NS1.
     *
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    public function toEnv(array $vars): array
    {
        $env = [];
        foreach ($vars as $key => $value) {
            $env['HE_'.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $key))] = $value;
        }

        return $env;
    }

    /**
     * Substitueix {{ var }} pel seu valor. Filtres:
     * - {{ xarxa | host(n) }}: n-èsima adreça útil d'una xarxa CIDR (amb n negativa, des del final);
     * - {{ ip | octet(n) }}: n-èsim octet (1-4) d'una adreça o xarxa.
     * Els marcadors desconeguts es queden tal qual, perquè missing() els pugui detectar.
     *
     * @param  array<string, mixed>  $vars
     */
    public function render(string $template, array $vars): string
    {
        return preg_replace_callback(self::MARKER, function (array $m) use ($vars) {
            if (! array_key_exists($m[1], $vars)) {
                return $m[0];
            }

            return match ($m[2] ?? '') {
                '' => (string) $vars[$m[1]],
                'host' => $this->host((string) $vars[$m[1]], (int) $m[3]),
                'octet' => $this->octet((string) $vars[$m[1]], (int) $m[3]),
                default => $m[0],
            };
        }, $template);
    }

    /** n-èsima adreça útil d'una xarxa IPv4: host('192.168.20.32/28', 3) = 192.168.20.35, amb -1 = .46. */
    public function host(string $cidr, int $n): string
    {
        [$ip, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        $long = ip2long($ip);
        if ($long === false || ! is_numeric($prefix) || $prefix < 0 || $prefix > 30 || $n === 0) {
            throw new InvalidArgumentException("host($n) no és vàlid per a '$cidr'");
        }

        $size = 2 ** (32 - (int) $prefix);
        $network = $long & ~($size - 1) & 0xFFFFFFFF;
        $offset = $n > 0 ? $n : $size - 1 + $n;
        if ($offset < 1 || $offset > $size - 2) {
            throw new InvalidArgumentException("host($n) queda fora de '$cidr'");
        }

        return long2ip($network + $offset);
    }

    /** n-èsim octet d'una adreça IPv4: octet('192.168.20.32/28', 4) = 32. */
    public function octet(string $ip, int $n): string
    {
        $parts = explode('.', strtok($ip, '/'));
        if (count($parts) !== 4 || $n < 1 || $n > 4) {
            throw new InvalidArgumentException("octet($n) no és vàlid per a '$ip'");
        }

        return $parts[$n - 1];
    }

    /**
     * Noms de variables que una plantilla fa servir i que no existeixen.
     *
     * @param  array<string, mixed>  $vars
     * @return list<string>
     */
    public function missing(string $template, array $vars): array
    {
        preg_match_all(self::MARKER, $template, $m);

        return array_values(array_unique(array_diff($m[1], array_keys($vars))));
    }
}
