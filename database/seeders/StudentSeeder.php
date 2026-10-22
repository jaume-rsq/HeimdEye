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
 * Les dades reals van a database/seeders/data/students.csv, que no es versiona.
 * Si no hi és, es carrega students.example.csv amb dades fictícies.
 */
class StudentSeeder extends Seeder
{
    public function run(): void
    {
        $year = AcademicYear::where('name', '2026/27')->firstOrFail();
        $cycle = Cycle::where('code', 'SMIX')->firstOrFail();
        $module = Module::where('academic_year_id', $year->id)
            ->where('cycle_id', $cycle->id)
            ->where('name', 'SMIX2 Infraestructura')
            ->firstOrFail();

        foreach ($this->rows() as $row) {
            $user = User::updateOrCreate(
                ['email' => $row['email']],
                ['name' => $row['full_name'], 'role' => Role::Student],
            );

            $student = Student::updateOrCreate(
                ['academic_year_id' => $year->id, 'username' => $row['username']],
                [
                    'user_id' => $user->id,
                    'cycle_id' => $cycle->id,
                    'full_name' => $row['full_name'],
                    'table_no' => (int) $row['table_no'],
                    'x' => (int) $row['x'],
                    'vars' => [
                        'router_ip' => $row['router_ip'],
                        'aula_net' => $row['aula_net'],
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
        $header = fgetcsv($handle, escape: '');
        $rows = [];
        while (($line = fgetcsv($handle, escape: '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $rows[] = array_combine($header, $line);
        }
        fclose($handle);

        return $rows;
    }
}
