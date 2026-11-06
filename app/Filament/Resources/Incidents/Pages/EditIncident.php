<?php

namespace App\Filament\Resources\Incidents\Pages;

use App\Filament\Actions\LaunchIncident;
use App\Filament\Resources\Incidents\IncidentResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditIncident extends EditRecord
{
    protected static string $resource = IncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('seguiment')
                ->label('Seguiment')
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->url(fn () => IncidentResource::getUrl('seguiment', ['record' => $this->getRecord()])),
            LaunchIncident::make(),
            DeleteAction::make()
                ->modalDescription('S\'esborra la definició, les opcions i tot el seguiment dels alumnes. Les execucions de les accions es queden a l\'historial de cada acció.'),
        ];
    }
}
