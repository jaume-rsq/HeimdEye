<?php

namespace App\Filament\Resources\HostTypes\Pages;

use App\Filament\Resources\HostTypes\HostTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageHostTypes extends ManageRecords
{
    protected static string $resource = HostTypeResource::class;

    public function getTitle(): string
    {
        return 'Equips comuns';
    }

    public function getSubheading(): ?string
    {
        return 'Tipus d\'equip globals o d\'un curs, que veuen tots els seus mòduls. Un del mòdul amb el mateix nom els tapa; els d\'un mòdul es gestionen a «Equips i variables».';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nou tipus d\'equip')->mutateDataUsing(fn (array $data) => HostTypeResource::normalize($data))];
    }
}
