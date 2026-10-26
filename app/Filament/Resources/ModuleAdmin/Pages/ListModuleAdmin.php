<?php

namespace App\Filament\Resources\ModuleAdmin\Pages;

use App\Filament\Resources\ModuleAdmin\ModuleAdminResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListModuleAdmin extends ListRecords
{
    protected static string $resource = ModuleAdminResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nou mòdul')];
    }
}
