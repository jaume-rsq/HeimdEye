<?php

namespace App\Filament\Resources\Modules\Pages;

use App\Filament\Resources\Modules\Concerns\PreviewsInventory;
use App\Filament\Resources\Modules\ModuleResource;
use App\Models\Module;
use Filament\Resources\Pages\ViewRecord;

/**
 * Inventari d'un mòdul només per consultar-lo (visitants de la demo i qui no el pot editar).
 *
 * @property Module $record
 */
class ViewModuleInventory extends ViewRecord
{
    use PreviewsInventory;

    protected static string $resource = ModuleResource::class;

    public function getTitle(): string
    {
        return 'Equips i variables · '.trim("{$this->record->codi} {$this->record->nom}");
    }
}
