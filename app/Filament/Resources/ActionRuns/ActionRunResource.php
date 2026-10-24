<?php

namespace App\Filament\Resources\ActionRuns;

use App\Filament\Resources\ActionRuns\Pages\ListActionRuns;
use App\Filament\Resources\ActionRuns\Tables\ActionRunsTable;
use App\Models\ActionRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Historial de totes les execucions d'accions (només lectura). */
class ActionRunResource extends Resource
{
    protected static ?string $model = ActionRun::class;

    protected static ?string $slug = 'historic-accions';

    protected static ?string $modelLabel = 'execució';

    protected static ?string $pluralModelLabel = 'execucions';

    protected static string|UnitEnum|null $navigationGroup = 'Accions';

    protected static ?string $navigationLabel = 'Històric d\'accions';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    public static function getBreadcrumb(): string
    {
        return 'Històric d\'accions';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return ActionRunsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActionRuns::route('/'),
        ];
    }
}
