<?php

namespace App\Support;

use App\Models\GlobalVar;
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
     *     rows: list<array{student: Student, vars: array<string, array{valor: string, origen: string, tapa: bool}>, hosts: array<string, array{nom: string, adreca: string, problemes: list<string>}>, problemes: int}>,
     *     keys: list<string>,
     * }
     */
    public function build(Module $module, ?Collection $students = null): array
    {
        $types = $module->hostTypes()->with('credential')->orderBy('posicio')->get();
        $students ??= $module->students()->where('es_actiu', true)->with('user')->orderBy('num_taula')->get();

        $globals = GlobalVar::query()->pluck('valor', 'clau')->all();
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
                'problemes' => array_sum(array_map(fn (array $h) => count($h['problemes']), $hosts)),
            ];
        }

        return [
            'types' => $types->reject->isGlobal()->values(),
            'globals' => $globalHosts,
            'rows' => $rows,
            'keys' => $this->sortKeys(array_values(array_unique($keys))),
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
            // Sobre el resultat: una variable derivada que no s'ha pogut calcular hi deixa el seu marcador.
            foreach ($this->resolver->missing($rendered[$field], $vars) as $name) {
                $problems[] = "Falta la variable {$name}";
            }
        }

        return ['nom' => $rendered['nom'], 'adreca' => $rendered['adreca'], 'problemes' => array_values(array_unique($problems))];
    }

    /**
     * Primer els camps de l'alumne, després la resta per ordre alfabètic.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function sortKeys(array $keys): array
    {
        $first = ['usuari', 'nom_complet', 'num_taula', 'x', 'email'];
        $rest = array_diff($keys, $first);
        sort($rest);

        return [...array_values(array_intersect($first, $keys)), ...$rest];
    }
}
