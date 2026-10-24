<?php

namespace App\Filament\Resources\ActionRuns\Pages;

use App\Filament\Resources\ActionRuns\ActionRunResource;
use Filament\Resources\Pages\ListRecords;

class ListActionRuns extends ListRecords
{
    protected static string $resource = ActionRunResource::class;

    protected static ?string $title = 'Històric d\'accions';

    protected static ?string $breadcrumb = 'Totes les execucions';
}
