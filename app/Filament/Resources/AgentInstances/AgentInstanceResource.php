<?php

namespace App\Filament\Resources\AgentInstances;

use App\Filament\Resources\AgentInstances\Pages\ListAgentInstances;
use App\Filament\Resources\AgentInstances\Tables\AgentInstancesTable;
use App\Models\AgentInstance;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Instàncies efímeres dels alumnes (AWS Learner Lab...) amb l'agent instal·lat. Llista del curs
 * actual, amb l'alumne propietari; des d'aquí es pot obrir la consola o llançar-hi un check d'agent.
 * Només lectura: les instàncies s'autoregistren i es donen de baixa soles.
 */
class AgentInstanceResource extends Resource
{
    protected static ?string $model = AgentInstance::class;

    protected static ?string $slug = 'instancies';

    protected static ?string $modelLabel = 'instància';

    protected static ?string $pluralModelLabel = 'instàncies';

    protected static string|UnitEnum|null $navigationGroup = 'Inventari';

    protected static ?string $navigationLabel = 'Instàncies';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return AgentInstancesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentInstances::route('/'),
        ];
    }
}
