<?php

namespace App\Support;

use App\Enums\ModuleKind;
use App\Models\AcademicYear;
use App\Models\Block;
use App\Models\Check;
use App\Models\Cycle;
use App\Models\Module;
use App\Models\Task;

/**
 * Biblioteca de checks i accions: un curs «Biblioteca» (fora dels selectors de curs i del curs
 * actual) amb un sol mòdul, el 0000. No té alumnes: res no hi compta per a cap nota. Els checks i
 * les accions s'hi proven sobre els alumnes i els equips d'un mòdul real i s'hi copien amb «Copiar a…».
 */
final class Library
{
    public const CODE = '0000';

    public const NAME = 'Biblioteca de checks i accions';

    public static function module(): ?Module
    {
        return Module::query()->where('codi', self::CODE)
            ->whereHas('academicYear', fn ($q) => $q->where('es_biblioteca', true))
            ->first();
    }

    /** Mòduls reals on es pot provar o copiar: els del curs actual primer. @return array<int, string> */
    public static function targetModules(): array
    {
        $current = AcademicYear::where('es_actual', true)->value('id');

        return Module::regular()->with('academicYear')->get()
            ->sortBy(fn (Module $m) => [$m->curs_id === $current ? 0 : 1, $m->codi, $m->nom])
            ->mapWithKeys(fn (Module $m) => [$m->id => trim("{$m->codi} {$m->nom}")." ({$m->academicYear->nom})"])
            ->all();
    }

    /**
     * El check (de la biblioteca) com si fos d'un mòdul real, per provar-lo: tasca i bloc iguals,
     * però el bloc apunta al mòdul de context, que dona alumnes, variables, equips i secrets.
     */
    public static function inContext(Check $check, Module $context): Check
    {
        $task = $check->task?->replicate() ?? new Task;
        $block = $check->task?->block?->replicate() ?? new Block;
        $block->forceFill(['modul_id' => $context->id])->setRelation('module', $context);
        $task->setRelation('block', $block);

        return (clone $check)->setRelation('task', $task);
    }

    /** Crea el curs i el mòdul si no hi són (cal algun cicle, perquè un mòdul en té). */
    public static function ensure(): ?Module
    {
        $cycle = Cycle::orderBy('id')->first();
        if ($cycle === null) {
            return null;
        }

        $year = AcademicYear::firstOrCreate(
            ['nom' => 'Biblioteca'],
            ['data_inici' => '2000-01-01', 'data_fi' => '2099-12-31', 'es_actual' => false, 'es_biblioteca' => true],
        );
        if (! $year->es_biblioteca) {
            $year->update(['es_biblioteca' => true, 'es_actual' => false]);
        }

        $module = Module::firstOrCreate(
            ['curs_id' => $year->id, 'codi' => self::CODE],
            ['cicle_id' => $cycle->id, 'nom' => self::NAME, 'tipus' => ModuleKind::Free, 'descripcio' => 'Checks i accions genèrics: es proven sobre els alumnes d\'un mòdul real i es copien a una tasca amb «Copiar a…».'],
        );

        // Un bloc i una tasca per començar; se n'hi poden afegir més per ordenar-ho (Xarxa, Serveis...).
        if (! $module->blocks()->exists()) {
            $module->blocks()->create(['numero' => 1, 'titol' => 'General', 'posicio' => 1])
                ->tasks()->create(['titol' => 'General', 'enunciat' => 'Checks genèrics. Prova\'ls amb el mòdul de context que vulguis i copia\'ls a una tasca real amb «Copiar a…».', 'posicio' => 1]);
        }

        return $module;
    }
}
