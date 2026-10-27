<?php

namespace App\Filament\Resources\Secrets\Pages;

use App\Filament\Resources\Secrets\SecretResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSecrets extends ManageRecords
{
    protected static string $resource = SecretResource::class;

    public function getTitle(): string
    {
        return 'Secrets comuns';
    }

    public function getSubheading(): ?string
    {
        return 'Globals i de cada curs: valors, usuaris i contrasenyes, claus SSH. Els d\'un mòdul els tapen i es gestionen a «Equips i variables».';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nou secret')->mutateDataUsing(fn (array $data) => SecretResource::normalize($data))];
    }
}
