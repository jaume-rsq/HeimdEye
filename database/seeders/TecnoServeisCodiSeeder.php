<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Action;
use App\Models\Block;
use App\Models\Module;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Posa al dia els checks i les accions dels tiquets 1 i 2 de TecnoServeis sense esborrar res: a cada
 * check o acció (pel nom) que no és com toca, hi afegeix una versió nova; actualitza les descripcions,
 * i crea les accions del tiquet 2 que encara no hi són.
 * Les execucions, els resultats i les incidències llançades es queden com estaven.
 *
 * El codi és el de TecnoServeisCode: només les funcions que fa servir cada check, en lloc de tot
 * el preàmbul. S'executa amb: php artisan db:seed --class=TecnoServeisCodiSeeder --force
 * (es pot tornar a executar: si ja està al dia, no fa res).
 */
class TecnoServeisCodiSeeder extends Seeder
{
    public const COMMENT = 'Posat al dia per TecnoServeisCodiSeeder';

    public function run(): void
    {
        $code = env('HEIMDEYE_INCIDENCIA_MODUL', '0378');
        $module = Module::where('codi', $code)
            ->orderByDesc(AcademicYear::select('es_actual')->whereColumn('cursos.id', 'moduls.curs_id')->limit(1))
            ->orderByDesc('id')
            ->first() ?? throw new RuntimeException("No existeix el mòdul {$code}");
        $number = (int) env('HEIMDEYE_INCIDENCIA_BLOC', 3);
        $block = Block::where('modul_id', $module->id)->where('numero', $number)->first()
            ?? throw new RuntimeException("El mòdul {$code} no té el bloc {$number}");

        $t2 = Tiquet2TecnoServeisSeeder::codes();
        $tasks = [
            env('HEIMDEYE_T1_TASCA', 'Tiquet 1') => [Tiquet1TecnoServeisSeeder::codes(), Tiquet1TecnoServeisSeeder::descriptions()],
            env('HEIMDEYE_INCIDENCIA_TASCA', 'Tiquet 2') => [$t2['checks'], Tiquet2TecnoServeisSeeder::descriptions()],
        ];

        foreach ($tasks as $title => [$codes, $descriptions]) {
            $task = $block->tasks()->where('titol', $title)->first();
            if ($task === null) {
                $this->command?->warn("No hi ha la tasca «{$title}» al bloc {$number}: me la salto.");

                continue;
            }
            $checks = $task->checks()->with('currentVersion')->get();
            $this->update($checks, $codes, "checks de «{$title}»");
            foreach ($checks as $check) {
                // La descripció (el que llegeix l'alumne) no té versions: s'actualitza i prou.
                if (isset($descriptions[$check->nom]) && $check->descripcio !== $descriptions[$check->nom]) {
                    $check->update(['descripcio' => $descriptions[$check->nom]]);
                }
            }
        }

        $t2task = $block->tasks()->where('titol', env('HEIMDEYE_INCIDENCIA_TASCA', 'Tiquet 2'))->first();
        if ($t2task !== null) {
            $this->syncActions($module, $block, $t2task->checks()->pluck('id', 'nom')->all());
        }
    }

    /**
     * Les accions del tiquet 2: crea les que falten, i a les que hi són els hi afegeix una versió nova
     * si el codi, els paràmetres, el temps o els checks de verificació han canviat.
     *
     * @param  array<string, int>  $checkIds  checks de la tasca «Tiquet 2», pel nom
     */
    private function syncActions(Module $module, Block $block, array $checkIds): void
    {
        $created = $updated = 0;
        foreach (Tiquet2TecnoServeisSeeder::actionSpecs() as $spec) {
            $verify = array_values(array_filter(array_map(fn (string $n) => $checkIds[$n] ?? null, $spec['verifica'])));
            $action = Action::where('bloc_id', $block->id)->where('nom', $spec['nom'])->with('currentVersion')->first();
            if ($action === null) {
                Tiquet2TecnoServeisSeeder::createAction($module, $block, $spec, $verify);
                $created++;

                continue;
            }
            $action->update(['descripcio' => $spec['descripcio'], 'posicio' => $spec['posicio']]);

            $current = $action->currentVersion;
            $wanted = ['codi' => $spec['codi'], 'parametres' => $spec['parametres'], 'temps_max_seg' => $spec['temps_max_seg'], 'checks_verificacio' => $verify ?: null];
            if ($current !== null && $current->only(array_keys($wanted)) == $wanted) {
                continue;
            }
            $action->versions()->create([
                ...$wanted,
                'executor' => $current?->executor,
                'versio' => ($current?->versio ?? 0) + 1,
                'comentari' => self::COMMENT,
            ]);
            $updated++;
        }

        $this->command?->info("accions del bloc {$block->numero}: {$created} noves i {$updated} versions noves.");
    }

    /**
     * @param  iterable<Model>  $records  checks (amb currentVersion)
     * @param  array<string, string>  $codes
     */
    private function update(iterable $records, array $codes, string $what): void
    {
        $updated = 0;
        foreach ($records as $record) {
            $current = $record->currentVersion;
            $new = $codes[$record->nom] ?? null;
            if ($new === null || $current === null || $current->codi === $new) {
                continue;
            }
            $record->versions()->create([
                ...$current->only(['executor', 'parametres', 'temps_max_seg']),
                'versio' => $current->versio + 1,
                'codi' => $new,
                'comentari' => self::COMMENT,
            ]);
            $updated++;
        }

        $this->command?->info("{$what}: {$updated} versions noves.");
    }
}
