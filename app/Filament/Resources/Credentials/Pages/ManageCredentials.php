<?php

namespace App\Filament\Resources\Credentials\Pages;

use App\Filament\Resources\Credentials\CredentialResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCredentials extends ManageRecords
{
    protected static string $resource = CredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nova credencial')];
    }
}
