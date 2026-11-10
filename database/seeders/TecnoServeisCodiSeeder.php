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
 * Posa al dia el codi dels checks i accions dels tiquets 1 i 2 de TecnoServeis sense esborrar res:
 * a cada check o acció (pel nom) amb un codi diferent del que toca, hi afegeix una versió nova.
 * Les execucions, els resultats i les incidències llançades es queden com estaven.
 *
 * El codi és el de TecnoServeisCode: només les funcions que fa servir cada check, en lloc de tot
 * el preàmbul. S'executa amb: php artisan db:seed --class=TecnoServeisCodiSeeder --force
 * (es pot tornar a executar: si ja està al dia, no fa res).
 */
class TecnoServeisCodiSeeder extends Seeder
{
    public const COMMENT = 'Codi simplificat: només les funcions que fa servir';

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
            env('HEIMDEYE_T1_TASCA', 'Tiquet 1') => Tiquet1TecnoServeisSeeder::codes(),
            env('HEIMDEYE_INCIDENCIA_TASCA', 'Tiquet 2') => $t2['checks'],
        ];

        foreach ($tasks as $title => $codes) {
            $task = $block->tasks()->where('titol', $title)->first();
            if ($task === null) {
                $this->command?->warn("No hi ha la tasca «{$title}» al bloc {$number}: me la salto.");

                continue;
            }
            $this->update($task->checks()->with('currentVersion')->get(), $codes, "checks de «{$title}»");
        }

        $this->update(Action::where('bloc_id', $block->id)->with('currentVersion')->get(), $t2['accions'], "accions del bloc {$number}");
    }

    /**
     * @param  iterable<Model>  $records  checks o accions (amb currentVersion)
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
                ...$current->only(['executor', 'parametres', 'temps_max_seg', ...($record instanceof Action ? ['checks_verificacio'] : [])]),
                'versio' => $current->versio + 1,
                'codi' => $new,
                'comentari' => self::COMMENT,
            ]);
            $updated++;
        }

        $this->command?->info("{$what}: {$updated} versions noves.");
    }
}
