<?php

namespace App\Filament\Resources\Incidents\Pages;

use App\Filament\Resources\Incidents\IncidentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIncidents extends ListRecords
{
    protected static string $resource = IncidentResource::class;

    protected static ?string $breadcrumb = 'Llista';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nova incidència'),
        ];
    }
}
