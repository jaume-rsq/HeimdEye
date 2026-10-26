<?php

namespace App\Filament\Resources\ModuleAdmin\Pages;

use App\Filament\Resources\ModuleAdmin\ModuleAdminResource;
use App\Models\AcademicYear;
use Filament\Resources\Pages\CreateRecord;

class CreateModule extends CreateRecord
{
    protected static string $resource = ModuleAdminResource::class;

    protected function afterFill(): void
    {
        $this->data['curs_id'] ??= AcademicYear::where('es_actual', true)->value('id');
    }
}
