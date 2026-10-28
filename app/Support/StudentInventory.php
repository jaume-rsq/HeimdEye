<?php

namespace App\Support;

use App\Models\ActionRun;
use App\Models\Module;
use App\Models\Student;
use App\Runner\Ansible\InventoryBuilder;
use App\Runner\Masker;
use App\Runner\TempFiles;
use Throwable;

/**
 * Què rep un alumne en un mòdul i d'on surt: variables amb la cadena de nivells, equips amb
 * l'accés, l'inventari Ansible (contrasenyes emmascarades) i l'activitat recent.
 */
class StudentInventory
{
    public function __construct(private VariableResolver $resolver, private InventoryGrid $grid) {}

    /** @return array<string, mixed> */
    public function build(Student $student, Module $module): array
    {
        $student->loadMissing('user');
        $grid = $this->grid->build($module, collect([$student]));
        $row = $grid['rows'][0];
        $types = $module->visibleHostTypes();

        return [
            'vars' => $row['vars'],
            'chain' => $this->resolver->chain($student, $module),
            'access' => $module->access,
            'hosts' => $types->reject->isUnique()->map(fn ($t) => ['tipus' => $t, ...$row['hosts'][$t->nom]])->values(),
            'globals' => $grid['globals'],
            'ansible' => $this->ansible($student, $module, $types->reject->isUnique()),
            'actions' => ActionRun::query()
                ->where('alumne_id', $student->id)
                ->where('es_simulacio', false)
                ->whereHas('action', fn ($q) => $q->where('modul_id', $module->id))
                ->with('action')
                ->latest('id')
                ->limit(10)
                ->get(),
            'checks' => $student->checkStates()
                ->whereHas('check.task.block', fn ($q) => $q->where('modul_id', $module->id))
                ->with('check')
                ->get(),
        ];
    }

    /** L'inventari que rep el runner, amb les contrasenyes emmascarades; els fitxers de clau s'esborren. */
    private function ansible(Student $student, Module $module, $types): string
    {
        $files = new TempFiles;
        try {
            $builder = new InventoryBuilder($this->resolver, $files);
            $json = json_encode($builder->build($module, [$student], $types), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return Masker::mask($json, $builder->mask());
        } catch (Throwable $e) {
            return 'No s\'ha pogut generar: '.$e->getMessage();
        } finally {
            $files->cleanup();
        }
    }
}
