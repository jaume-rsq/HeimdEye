<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Cycle;
use App\Models\Module;
use App\Support\StudentImporter;
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

        // El seeder no desactiva ningú: només afegeix i actualitza.
        app(StudentImporter::class)->apply($module, $this->rows(), deactivateMissing: false);
    }

    /** @return list<array<string, string>> */
    private function rows(): array
    {
        $path = config('heimdeye.students_csv');
        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }
        if (! is_file($path)) {
            $path = database_path('seeders/data/students.example.csv');
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("No es pot llegir $path");
        }

        return app(StudentImporter::class)->parse($text);
    }
}
