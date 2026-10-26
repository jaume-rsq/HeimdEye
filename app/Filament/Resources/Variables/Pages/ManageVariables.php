<?php

namespace App\Filament\Resources\Variables\Pages;

use App\Filament\Resources\Variables\VariableResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageVariables extends ManageRecords
{
    protected static string $resource = VariableResource::class;

    public function getTitle(): string
    {
        return 'Variables comunes';
    }

    public function getSubheading(): ?string
    {
        return 'Globals i de cada curs. Les d\'un mòdul les tapen; les d\'un mòdul es gestionen a «Equips i variables».';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova variable')
                ->mutateDataUsing(fn (array $data) => VariableResource::normalize($data)),
        ];
    }
}
