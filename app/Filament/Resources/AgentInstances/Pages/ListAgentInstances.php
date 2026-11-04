<?php

namespace App\Filament\Resources\AgentInstances\Pages;

use App\Filament\Resources\AgentInstances\AgentInstanceResource;
use Filament\Resources\Pages\ListRecords;

class ListAgentInstances extends ListRecords
{
    protected static string $resource = AgentInstanceResource::class;

    protected static ?string $title = 'Instàncies';
}
