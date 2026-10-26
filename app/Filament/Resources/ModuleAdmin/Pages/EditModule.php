<?php

namespace App\Filament\Resources\ModuleAdmin\Pages;

use App\Filament\Resources\ModuleAdmin\ModuleAdminResource;
use Filament\Resources\Pages\EditRecord;

class EditModule extends EditRecord
{
    protected static string $resource = ModuleAdminResource::class;

    protected function getHeaderActions(): array
    {
        return [ModuleAdminResource::deleteAction()];
    }
}
