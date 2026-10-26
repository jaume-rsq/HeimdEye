<?php

namespace App\Filament\Resources\Modules\Pages;

use App\Filament\Resources\Modules\ModuleResource;
use App\Models\Module;
use Filament\Resources\Pages\ListRecords;

class ListModules extends ListRecords
{
    protected static string $resource = ModuleResource::class;

    /** Si el curs actual només té un mòdul, el menú porta directament als seus equips i variables. */
    public function mount(): void
    {
        $modules = Module::whereHas('academicYear', fn ($query) => $query->where('es_actual', true))->limit(2)->pluck('id');
        if ($modules->count() === 1) {
            $this->redirect(ModuleResource::getUrl('edit', ['record' => $modules->first()]));

            return;
        }

        parent::mount();
    }
}
