<?php

namespace App\Filament\Resources\Cycles\Pages;

use App\Filament\Resources\Cycles\CycleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCycles extends ManageRecords
{
    protected static string $resource = CycleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nou cicle')];
    }
}
