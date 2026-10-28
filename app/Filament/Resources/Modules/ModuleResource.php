<?php

namespace App\Filament\Resources\Modules;

use App\Filament\Resources\Modules\Pages\EditModuleInventory;
use App\Filament\Resources\Modules\Pages\ListModules;
use App\Filament\Resources\Modules\Pages\ViewModuleInventory;
use App\Filament\Resources\Modules\Schemas\ModuleInventoryForm;
use App\Filament\Resources\Modules\Tables\ModulesTable;
use App\Models\Module;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Equips, variables i secrets de cada mòdul. L'alta de mòduls anirà al CRUD de taules. */
class ModuleResource extends Resource
{
    protected static ?string $model = Module::class;

    protected static ?string $slug = 'moduls';

    protected static ?string $modelLabel = 'mòdul';

    protected static ?string $pluralModelLabel = 'mòduls';

    protected static ?string $recordTitleAttribute = 'nom';

    protected static string|UnitEnum|null $navigationGroup = 'Inventari';

    protected static ?string $navigationLabel = 'Equips del mòdul';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    public static function form(Schema $schema): Schema
    {
        return ModuleInventoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ModulesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModules::route('/'),
            'edit' => EditModuleInventory::route('/{record}/equips-i-variables'),
            'view' => ViewModuleInventory::route('/{record}'),
        ];
    }
}
