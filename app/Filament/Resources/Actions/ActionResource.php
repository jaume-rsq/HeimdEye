<?php

namespace App\Filament\Resources\Actions;

use App\Filament\Resources\Actions\Pages\CreateAction;
use App\Filament\Resources\Actions\Pages\EditAction;
use App\Filament\Resources\Actions\Pages\ListActions;
use App\Filament\Resources\Actions\Pages\ManageActionRuns;
use App\Filament\Resources\Actions\Pages\ManageActionVersions;
use App\Filament\Resources\Actions\Schemas\ActionForm;
use App\Filament\Resources\Actions\Tables\ActionsTable;
use App\Models\Action;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ActionResource extends Resource
{
    protected static ?string $model = Action::class;

    protected static ?string $slug = 'accions';

    protected static ?string $modelLabel = 'acció';

    protected static ?string $pluralModelLabel = 'accions';

    protected static ?string $recordTitleAttribute = 'nom';

    protected static string|UnitEnum|null $navigationGroup = 'Accions';

    protected static ?string $navigationLabel = 'Llista d\'accions';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    public static function form(Schema $schema): Schema
    {
        return ActionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ActionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActions::route('/'),
            'create' => CreateAction::route('/nova'),
            'edit' => EditAction::route('/{record}/editar'),
            'versions' => ManageActionVersions::route('/{record}/versions'),
            'runs' => ManageActionRuns::route('/{record}/historial'),
        ];
    }
}
