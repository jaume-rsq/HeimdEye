<?php

namespace App\Support;

use App\Models\HostType;
use App\Models\Module;
use App\Models\Student;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Inventari calculat d'un mòdul: per a cada alumne actiu, les variables amb el seu origen
 * i el nom i l'adreça de cada equip, amb els problemes de plantilla detectats.
 */
class InventoryGrid
{
    public function __construct(private VariableResolver $resolver) {}

    /**
     * @return array{
     *     types: Collection<int, HostType>,
     *     globals: list<array{tipus: HostType, nom: string, adreca: string, problemes: list<string>}>,
     *     rows: list<array{student: Student, vars: array<string, array{valor: string, origen: string, tapa: bool, error: ?string}>, hosts: array<string, array{nom: string, adreca: string, problemes: list<string>}>, problemes: int}>,
     *     groups: array<string, list<string>>,
     * }
     */
    public function build(Module $module, ?Collection $students = null): array
    {
        $types = $module->hostTypes()->with('credential')->orderBy('posicio')->get();
        $students ??= $module->students()->where('es_actiu', true)->with('user')->orderBy('num_taula')->get();

        $globals = $this->resolver->common($module);
        $globalHosts = $types->filter->isGlobal()->map(fn (HostType $t) => ['tipus' => $t, ...$this->host($t, $globals)])->values()->all();

        $rows = [];
        $keys = [];
        foreach ($students as $student) {
            $vars = $this->resolver->detailed($student, $module);
            $values = array_map(fn (array $v) => $v['valor'], $vars);
            $hosts = [];
            foreach ($types->reject->isGlobal() as $type) {
                $hosts[$type->nom] = $this->host($type, $values);
            }
            $keys = array_merge($keys, array_keys($vars));
            $rows[] = [
                'student' => $student,
                'vars' => $vars,
                'hosts' => $hosts,
                'problemes' => array_sum(array_map(fn (array $h) => count($h['problemes']), $hosts))
                    + count(array_filter($vars, fn (array $v) => $v['error'] !== null)),
            ];
        }

        return [
            'types' => $types->reject->isGlobal()->values(),
            'globals' => $globalHosts,
            'rows' => $rows,
            'groups' => self::groups(array_values(array_unique($keys))),
        ];
    }

    /**
     * Nom i adreça d'un equip; els problemes són variables que falten o filtres que no es poden aplicar.
     *
     * @param  array<string, string>  $vars
     * @return array{nom: string, adreca: string, problemes: list<string>}
     */
    public function host(HostType $type, array $vars): array
    {
        $problems = [];
        $templates = ['nom' => $type->nom_host, 'adreca' => $type->adreca];
        foreach ($type->variables_connexio ?? [] as $key => $value) {
            if (is_string($value)) {
                $templates[$key] = $value;
            }
        }

        $rendered = [];
        foreach ($templates as $field => $template) {
            try {
                $rendered[$field] = $this->resolver->render($template, $vars);
            } catch (InvalidArgumentException $e) {
                $rendered[$field] = $template;
                $problems[] = $e->getMessage();
            }
            // Sobre el resultat: una variable que no s'ha pogut calcular hi deixa el seu marcador.
            $missing = $this->resolver->missing($rendered[$field], $vars);
            foreach ($missing as $name) {
                $problems[] = "Falta la variable {$name}";
            }
            if ($missing === [] && str_contains($rendered[$field], '{{')) {
                $problems[] = "No s'ha pogut calcular: {$rendered[$field]}";
            }
        }

        return ['nom' => $rendered['nom'], 'adreca' => $rendered['adreca'], 'problemes' => array_values(array_unique($problems))];
    }

    /** Grups de la nomenclatura de variables, per prefix. */
    public const GROUPS = [
        'alumne' => 'Alumne',
        'xarxa_' => 'Xarxes',
        'ip_' => 'Adreces',
        'nom_' => 'Noms',
        'num_' => 'Números',
        'altres' => 'Altres',
    ];

    /** Camps fixos de l'alumne, que sempre surten primer. */
    private const FIELDS = ['usuari', 'nom_complet', 'num_taula', 'x', 'email'];

    /**
     * Agrupa els noms de variable per prefix (xarxa_, ip_, nom_, num_), ordenats dins de cada grup.
     *
     * @param  list<string>  $keys
     * @return array<string, list<string>> etiqueta del grup => noms
     */
    public static function groups(array $keys): array
    {
        $groups = array_fill_keys(array_keys(self::GROUPS), []);
        foreach ($keys as $key) {
            $group = 'altres';
            if (in_array($key, self::FIELDS, true)) {
                $group = 'alumne';
            } else {
                foreach (array_keys(self::GROUPS) as $prefix) {
                    if (str_ends_with($prefix, '_') && str_starts_with($key, $prefix)) {
                        $group = $prefix;
                        break;
                    }
                }
            }
            $groups[$group][] = $key;
        }

        $result = [];
        foreach ($groups as $group => $names) {
            if ($names === []) {
                continue;
            }
            if ($group === 'alumne') {
                $names = array_values(array_intersect(self::FIELDS, $names));
            } else {
                sort($names);
            }
            $result[self::GROUPS[$group]] = $names;
        }

        return $result;
    }
}
