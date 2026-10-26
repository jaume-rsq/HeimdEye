<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Module;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Importa la llista de l'aula a un mòdul: columnes xarxa, taula, alumne, email, alum
 * (comes o tabuladors, així es pot enganxar directament del full de càlcul).
 *
 * plan() compara la llista amb el que hi ha sense tocar res; apply() aplica el pla.
 * Els alumnes que ja no hi són es poden marcar inactius, però mai s'esborren.
 */
class StudentImporter
{
    public const COLUMNS = ['xarxa', 'taula', 'alumne', 'email', 'alum'];

    /**
     * @return list<array<string, string>>
     *
     * @throws InvalidArgumentException si falta alguna columna
     */
    public function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim(preg_replace('/^\xEF\xBB\xBF/', '', $text)));
        if ($text === '') {
            return [];
        }

        $lines = explode("\n", $text);
        $separator = str_contains($lines[0], "\t") ? "\t" : ',';
        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines), $separator, escape: ''));
        if ($missing = array_diff(self::COLUMNS, $header)) {
            throw new InvalidArgumentException('Falten columnes: '.implode(', ', $missing).'. Cal la capçalera: '.implode(', ', self::COLUMNS).'.');
        }

        $rows = [];
        foreach ($lines as $number => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map('trim', str_getcsv($line, $separator, escape: ''));
            if (count($cells) !== count($header)) {
                throw new InvalidArgumentException('La línia '.($number + 2).' té '.count($cells).' columnes i la capçalera en té '.count($header).'.');
            }
            $rows[] = array_combine($header, $cells);
        }

        return $rows;
    }

    /**
     * Dades de l'alumne a partir d'una fila: usuari = part del correu abans de l'@, X = 20 + taula.
     *
     * @param  array<string, string>  $row
     * @return array{usuari: string, email: string, nom_complet: string, num_taula: int, x: int, variables: array<string, string>}
     */
    public function attributes(array $row): array
    {
        $table = (int) preg_replace('/\D/', '', $row['taula']);
        if ($table < 1 || ! str_contains($row['email'], '@')) {
            throw new InvalidArgumentException("Fila no vàlida: taula «{$row['taula']}», correu «{$row['email']}».");
        }

        return [
            'usuari' => strtolower(strstr($row['email'], '@', true)),
            'email' => strtolower($row['email']),
            'nom_complet' => $row['alumne'],
            'num_taula' => $table,
            'x' => 20 + $table,
            'variables' => ['xarxa_aula' => $row['xarxa'], 'nom_alum' => $row['alum']],
        ];
    }

    /**
     * Què passaria si s'importés la llista al mòdul.
     *
     * @param  list<array<string, string>>  $rows
     * @return array{nous: list<array<string, mixed>>, canvis: list<array{student: Student, data: array<string, mixed>, diferencies: array<string, array{0: mixed, 1: mixed}>}>, iguals: int, absents: list<Student>}
     */
    public function plan(Module $module, array $rows): array
    {
        $existing = Student::where('curs_id', $module->curs_id)->with(['user', 'modules'])->get()->keyBy('usuari');
        $plan = ['nous' => [], 'canvis' => [], 'iguals' => 0, 'absents' => []];
        $seen = [];

        foreach ($rows as $row) {
            $data = $this->attributes($row);
            if (isset($seen[$data['usuari']])) {
                throw new InvalidArgumentException("L'alumne {$data['usuari']} surt dues vegades.");
            }
            $seen[$data['usuari']] = true;

            $student = $existing->get($data['usuari']);
            if ($student === null) {
                $plan['nous'][] = $data;

                continue;
            }

            $diff = [];
            foreach (['nom_complet', 'num_taula', 'x'] as $field) {
                if ((string) $student->{$field} !== (string) $data[$field]) {
                    $diff[$field] = [$student->{$field}, $data[$field]];
                }
            }
            foreach ($data['variables'] as $key => $value) {
                if (($student->variables[$key] ?? null) !== $value) {
                    $diff[$key] = [$student->variables[$key] ?? null, $value];
                }
            }
            if ($student->user?->email !== $data['email']) {
                $diff['email'] = [$student->user?->email, $data['email']];
            }
            if (! $student->es_actiu) {
                $diff['es_actiu'] = ['no', 'sí'];
            }
            if (! $student->modules->contains('id', $module->id)) {
                $diff['mòdul'] = ['no matriculat', 'matriculat'];
            }

            if ($diff === []) {
                $plan['iguals']++;
            } else {
                $plan['canvis'][] = ['student' => $student, 'data' => $data, 'diferencies' => $diff];
            }
        }

        $plan['absents'] = $module->students()
            ->where('es_actiu', true)
            ->orderBy('num_taula')
            ->get()
            ->reject(fn (Student $s) => isset($seen[$s->usuari]))
            ->values()
            ->all();

        return $plan;
    }

    /**
     * Aplica la llista: crea i actualitza alumnes (i el seu usuari), els matricula al mòdul
     * i, si es demana, marca inactius els que ja no hi són.
     *
     * @param  list<array<string, string>>  $rows
     * @return array{nous: int, canvis: int, desactivats: int}
     */
    public function apply(Module $module, array $rows, bool $deactivateMissing = true): array
    {
        $plan = $this->plan($module, $rows);

        DB::transaction(function () use ($module, $plan, $deactivateMissing) {
            foreach ([...$plan['nous'], ...array_column($plan['canvis'], 'data')] as $data) {
                $user = User::updateOrCreate(
                    ['email' => $data['email']],
                    ['nom' => $data['nom_complet'], 'rol' => Role::Student],
                );
                $student = Student::updateOrCreate(
                    ['curs_id' => $module->curs_id, 'usuari' => $data['usuari']],
                    [
                        'usuari_id' => $user->id,
                        'cicle_id' => $module->cicle_id,
                        'nom_complet' => $data['nom_complet'],
                        'num_taula' => $data['num_taula'],
                        'x' => $data['x'],
                        'es_actiu' => true,
                    ],
                );
                // Les variables de la llista s'afegeixen a les pròpies que ja hi hagués.
                $student->update(['variables' => [...$student->variables ?? [], ...$data['variables']]]);
                $student->modules()->syncWithoutDetaching([$module->id]);
            }

            if ($deactivateMissing) {
                Student::whereKey(array_map(fn (Student $s) => $s->id, $plan['absents']))->update(['es_actiu' => false]);
            }
        });

        return [
            'nous' => count($plan['nous']),
            'canvis' => count($plan['canvis']),
            'desactivats' => $deactivateMissing ? count($plan['absents']) : 0,
        ];
    }
}
