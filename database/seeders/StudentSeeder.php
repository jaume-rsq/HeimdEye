<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\AcademicYear;
use App\Models\Cycle;
use App\Models\Module;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Alumnes de SMIX2 matriculats al mòdul combinat.
 *
 * Les dades reals van a database/seeders/data/students.csv, que no es versiona
 * (el repositori és públic). Si no hi és, es carrega students.example.csv.
 *
 * Columnes: xarxa,taula,alumne,email,alum (el format de la llista de l'aula).
 * Accepta comes o tabuladors, així es pot enganxar la taula directament.
 */
class StudentSeeder extends Seeder
{
    public function run(): void
    {
        $year = AcademicYear::where('nom', '2026/27')->firstOrFail();
        $cycle = Cycle::where('codi', 'SMIX')->firstOrFail();
        $module = Module::where('curs_id', $year->id)
            ->where('cicle_id', $cycle->id)
            ->where('codi', '9991')
            ->firstOrFail();

        foreach ($this->rows() as $row) {
            $tableNo = (int) preg_replace('/\D/', '', $row['taula']);
            $username = strstr($row['email'], '@', true);

            $user = User::updateOrCreate(
                ['email' => $row['email']],
                ['nom' => $row['alumne'], 'rol' => Role::Student],
            );

            $student = Student::updateOrCreate(
                ['curs_id' => $year->id, 'usuari' => $username],
                [
                    'usuari_id' => $user->id,
                    'cicle_id' => $cycle->id,
                    'nom_complet' => $row['alumne'],
                    'num_taula' => $tableNo,
                    'x' => 20 + $tableNo,
                    'variables' => [
                        'aula_net' => $row['xarxa'],
                        'alum' => $row['alum'],
                    ],
                ],
            );

            $student->modules()->syncWithoutDetaching([$module->id]);
        }
    }

    /** @return list<array<string, string>> */
    private function rows(): array
    {
        $path = config('heimdeye.students_csv');
        if (! is_file($path)) {
            $path = database_path('seeders/data/students.example.csv');
        }

        $handle = fopen($path, 'r') ?: throw new RuntimeException("No es pot llegir $path");
        $separator = str_contains(fgets($handle), "\t") ? "\t" : ',';
        rewind($handle);
        $header = array_map(fn ($h) => strtolower(trim($h)), fgetcsv($handle, separator: $separator, escape: ''));
        $rows = [];
        while (($line = fgetcsv($handle, separator: $separator, escape: '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $rows[] = array_combine($header, array_map('trim', $line));
        }
        fclose($handle);

        return $rows;
    }
}
