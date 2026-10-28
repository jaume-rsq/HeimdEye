<?php

namespace App\Support;

use App\Models\Module;
use App\Models\Student;
use App\Models\Variable;
use InvalidArgumentException;

/**
 * Calcula les variables que rep un check o una acció per a un alumne.
 *
 * Prioritat (de menys a més): globals, del curs, del mòdul, camps fixos de l'alumne
 * i variables pròpies de l'alumne. Qualsevol valor pot ser una plantilla que en fa servir
 * d'altres ({{ xarxa_aula | host(3) }}): es calcula quan totes les capes ja s'han ajuntat.
 */
class VariableResolver
{
    /** {{ var }} o {{ var | filtre(n) }} */
    private const MARKER = '/\{\{\s*([A-Za-z0-9_]+)\s*(?:\|\s*([a-z_]+)\(\s*(-?\d+)\s*\))?\s*\}\}/';

    /** Plantilles que en fan servir d'altres: com a molt tants nivells d'imbricació. */
    private const MAX_PASSES = 5;

    /**
     * @return array<string, string>
     *
     * @throws InvalidArgumentException si un filtre no es pot aplicar
     */
    public function resolve(Student $student, Module $module): array
    {
        $vars = $this->detailed($student, $module);
        foreach ($vars as $var) {
            if ($var['error'] !== null) {
                throw new InvalidArgumentException($var['error']);
            }
        }

        return array_map(fn (array $v) => $v['valor'], $vars);
    }

    /**
     * Les variables que no depenen de cap alumne (globals i del curs), per als equips globals.
     *
     * @return array<string, string>
     */
    public function common(Module $module): array
    {
        return array_map(fn (array $v) => $v['valor'], $this->compute(array_slice($this->layers($module), 0, 2)));
    }

    /**
     * Les mateixes variables que resolve(), amb d'on surt cada valor (global, curs, modul,
     * alumne o propia), si amaga el d'una capa inferior i l'error, si n'hi ha.
     *
     * $draft canvia valors desats sense desar-los, per previsualitzar un esborrany:
     * [nivell => [variable => valor]] (nivell global, curs, modul o propia), on un valor null treu
     * la variable d'aquell nivell.
     *
     * @param  array<string, array<string, ?string>>  $draft
     * @return array<string, array{valor: string, origen: string, tapa: bool, error: ?string}>
     */
    public function detailed(Student $student, Module $module, array $draft = []): array
    {
        $layers = $this->studentLayers($student, $module);
        foreach ($draft as $level => $vars) {
            foreach ($vars as $key => $value) {
                if ($value === null) {
                    unset($layers[$level][$key]);
                } else {
                    $layers[$level][$key] = $value;
                }
            }
        }

        return $this->compute($layers);
    }

    /**
     * El valor sense calcular que dona cada capa a cada variable, per ensenyar la cadena de nivells:
     * [variable => [global|curs|modul|alumne|propia => valor]].
     *
     * @return array<string, array<string, string>>
     */
    public function chain(Student $student, Module $module): array
    {
        $chain = [];
        foreach ($this->studentLayers($student, $module) as $origin => $vars) {
            foreach ($vars as $key => $value) {
                $chain[$key][$origin] = (string) $value;
            }
        }

        return $chain;
    }

    /** Les capes d'un alumne, de la més general a la més concreta. */
    private function studentLayers(Student $student, Module $module): array
    {
        $fields = array_filter([
            'usuari' => $student->usuari,
            'nom_complet' => $student->nom_complet,
            'num_taula' => $student->num_taula === null ? null : sprintf('%02d', $student->num_taula),
            'x' => $student->x,
            'email' => $student->user?->email,
        ], fn ($value) => $value !== null);

        return [
            ...$this->layers($module),
            'alumne' => $fields,
            'propia' => $student->variables ?? [],
        ];
    }

    /** @return array{global: array<string, string>, curs: array<string, string>, modul: array<string, string>} */
    private function layers(Module $module): array
    {
        $rows = Variable::query()->visibleFrom($module)->get();

        $layers = ['global' => [], 'curs' => [], 'modul' => []];
        foreach ($rows as $row) {
            $layers[$row->nivell->value][$row->clau] = $row->valor;
        }

        return $layers;
    }

    /**
     * Ajunta les capes i calcula les plantilles contra el resultat.
     *
     * @param  array<string, array<string, mixed>>  $layers
     * @return array<string, array{valor: string, origen: string, tapa: bool, error: ?string}>
     */
    private function compute(array $layers): array
    {
        $result = [];
        foreach ($layers as $origin => $vars) {
            foreach ($vars as $key => $value) {
                $result[$key] = ['valor' => (string) $value, 'origen' => $origin, 'tapa' => isset($result[$key]), 'error' => null];
            }
        }

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $values = array_map(fn (array $v) => $v['valor'], $result);
            $changed = false;
            foreach ($result as $key => $var) {
                if (! str_contains($var['valor'], '{{')) {
                    continue;
                }
                try {
                    $rendered = $this->render($var['valor'], $values);
                } catch (InvalidArgumentException $e) {
                    $result[$key]['error'] = "{$key}: {$e->getMessage()}";

                    continue;
                }
                $result[$key]['error'] = null;
                if ($rendered !== $var['valor']) {
                    $result[$key]['valor'] = $rendered;
                    $changed = true;
                }
            }
            if (! $changed) {
                break;
            }
        }

        return $result;
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
     * Variables que han quedat amb la plantilla sense calcular perquè en falta alguna altra
     * (p. ex. ip_router = {{ xarxa_aula | host(-1) }} per a un alumne sense xarxa_aula).
     *
     * @param  array<string, string>  $vars  el resultat de resolve()
     * @return array<string, list<string>> per variable, les que li falten
     */
    public function unresolved(array $vars): array
    {
        $result = [];
        foreach ($vars as $key => $value) {
            if ($missing = $this->missing($value, $vars)) {
                $result[$key] = $missing;
            }
        }

        return $result;
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
