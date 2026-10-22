<?php

namespace App\Support;

use App\Models\GlobalVar;
use App\Models\Module;
use App\Models\Student;

/**
 * Calcula les variables que rep un check per a un alumne.
 *
 * Prioritat (de menys a més): variables globals, variables derivades del mòdul,
 * camps fixos de l'alumne i variables lliures de l'alumne.
 */
class VariableResolver
{
    /** @return array<string, string> */
    public function resolve(Student $student, Module $module): array
    {
        $own = array_merge(
            array_filter([
                'username' => $student->username,
                'full_name' => $student->full_name,
                'table_no' => $student->table_no,
                'x' => $student->x,
            ], fn ($value) => $value !== null),
            $student->vars ?? [],
        );

        $base = array_merge(GlobalVar::query()->pluck('value', 'key')->all(), $own);

        $derived = array_map(
            fn (string $template) => $this->render($template, $base),
            $module->derived_vars ?? [],
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
     * Substitueix {{ var }} pel seu valor. Els marcadors desconeguts es queden tal qual,
     * perquè missing() els pugui detectar.
     *
     * @param  array<string, mixed>  $vars
     */
    public function render(string $template, array $vars): string
    {
        return preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
            fn (array $m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0],
            $template,
        );
    }

    /**
     * Noms de variables que una plantilla fa servir i que no existeixen.
     *
     * @param  array<string, mixed>  $vars
     * @return list<string>
     */
    public function missing(string $template, array $vars): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $template, $m);

        return array_values(array_unique(array_diff($m[1], array_keys($vars))));
    }
}
