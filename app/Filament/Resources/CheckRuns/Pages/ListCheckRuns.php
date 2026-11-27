<?php

namespace App\Filament\Resources\CheckRuns\Pages;

use App\Filament\Concerns\KeepsScopeFiltersCoherent;
use App\Filament\Resources\CheckRuns\CheckRunResource;
use Filament\Resources\Pages\ListRecords;

class ListCheckRuns extends ListRecords
{
    use KeepsScopeFiltersCoherent;

    protected static string $resource = CheckRunResource::class;

    protected static ?string $title = 'Històric de checks';

    protected static ?string $breadcrumb = 'Totes les execucions';
}
