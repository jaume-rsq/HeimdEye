<?php

namespace App\Filament\Resources\Checks\Pages;

use App\Filament\Concerns\KeepsScopeFiltersCoherent;
use App\Filament\Resources\Checks\CheckResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChecks extends ListRecords
{
    use KeepsScopeFiltersCoherent;

    protected static string $resource = CheckResource::class;

    protected static ?string $breadcrumb = 'Llista';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nou check'),
        ];
    }
}
