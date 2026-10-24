<?php

namespace App\Filament\Resources\Actions\Pages;

use App\Filament\Resources\Actions\ActionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListActions extends ListRecords
{
    protected static string $resource = ActionResource::class;

    protected static ?string $breadcrumb = 'Llista';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nova acció'),
        ];
    }
}
