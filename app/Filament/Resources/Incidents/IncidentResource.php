<?php

namespace App\Filament\Resources\Incidents;

use App\Filament\Resources\Incidents\Pages\CreateIncident;
use App\Filament\Resources\Incidents\Pages\EditIncident;
use App\Filament\Resources\Incidents\Pages\ListIncidents;
use App\Filament\Resources\Incidents\Pages\ManageIncidentAssignments;
use App\Filament\Resources\Incidents\Schemas\IncidentForm;
use App\Filament\Resources\Incidents\Tables\IncidentsTable;
use App\Models\Incident;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Incidències: avaries intencionades que el professor injecta als equips dels alumnes perquè
 * les resolguin. Es defineixen aquí (opcions = acció + check de resolució) i es llancen a l'acte.
 */
class IncidentResource extends Resource
{
    protected static ?string $model = Incident::class;

    protected static ?string $slug = 'incidencies';

    protected static ?string $modelLabel = 'incidència';

    protected static ?string $pluralModelLabel = 'incidències';

    protected static ?string $recordTitleAttribute = 'nom';

    protected static string|UnitEnum|null $navigationGroup = 'Accions';

    protected static ?string $navigationLabel = 'Incidències';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBugAnt;

    public static function form(Schema $schema): Schema
    {
        return IncidentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IncidentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIncidents::route('/'),
            'create' => CreateIncident::route('/nova'),
            'edit' => EditIncident::route('/{record}/editar'),
            'seguiment' => ManageIncidentAssignments::route('/{record}/seguiment'),
        ];
    }
}
