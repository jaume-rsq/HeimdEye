<?php

namespace App\Filament\Resources\Checks;

use App\Filament\Resources\Checks\Pages\CreateCheck;
use App\Filament\Resources\Checks\Pages\EditCheck;
use App\Filament\Resources\Checks\Pages\ListChecks;
use App\Filament\Resources\Checks\Pages\ManageCheckRuns;
use App\Filament\Resources\Checks\Pages\ManageCheckVersions;
use App\Filament\Resources\Checks\Schemas\CheckForm;
use App\Filament\Resources\Checks\Tables\ChecksTable;
use App\Models\Check;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CheckResource extends Resource
{
    protected static ?string $model = Check::class;

    protected static ?string $slug = 'checks';

    protected static ?string $modelLabel = 'check';

    protected static ?string $pluralModelLabel = 'checks';

    protected static ?string $recordTitleAttribute = 'nom';

    protected static string|UnitEnum|null $navigationGroup = 'Checks';

    protected static ?string $navigationLabel = 'Llista de checks';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    public static function form(Schema $schema): Schema
    {
        return CheckForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChecksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChecks::route('/'),
            'create' => CreateCheck::route('/nou'),
            'edit' => EditCheck::route('/{record}/editar'),
            'versions' => ManageCheckVersions::route('/{record}/versions'),
            'runs' => ManageCheckRuns::route('/{record}/historial'),
        ];
    }
}
