<?php

namespace App\Filament\Resources\Actions\Pages;

use App\Filament\Resources\Actions\ActionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListActions extends ListRecords
{
    protected static string $resource = ActionResource::class;

    protected static ?string $breadcrumb = 'Llista';

    /** La taula té moltes columnes: que ocupi tota l'amplada de la finestra. */
    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nova acció'),
        ];
    }
}
