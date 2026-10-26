<?php

namespace App\Filament\Resources\CheckRuns;

use App\Filament\Resources\CheckRuns\Pages\ListCheckRuns;
use App\Filament\Resources\CheckRuns\Tables\CheckRunsTable;
use App\Models\CheckRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Historial de totes les execucions de checks (només lectura). */
class CheckRunResource extends Resource
{
    protected static ?string $model = CheckRun::class;

    protected static ?string $slug = 'historic-checks';

    protected static ?string $modelLabel = 'execució';

    protected static ?string $pluralModelLabel = 'execucions';

    protected static string|UnitEnum|null $navigationGroup = 'Checks';

    protected static ?string $navigationLabel = 'Històric de checks';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    public static function getBreadcrumb(): string
    {
        return 'Històric de checks';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return CheckRunsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCheckRuns::route('/'),
        ];
    }
}
