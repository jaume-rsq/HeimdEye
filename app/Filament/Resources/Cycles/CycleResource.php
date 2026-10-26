<?php

namespace App\Filament\Resources\Cycles;

use App\Filament\Resources\Cycles\Pages\ManageCycles;
use App\Models\Cycle;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Cicles formatius (SMIX, ASIX...). Un cicle amb mòduls o alumnes no es pot esborrar. */
class CycleResource extends Resource
{
    protected static ?string $model = Cycle::class;

    protected static ?string $slug = 'cicles';

    protected static ?string $modelLabel = 'cicle';

    protected static ?string $pluralModelLabel = 'cicles';

    protected static ?string $recordTitleAttribute = 'codi';

    protected static string|UnitEnum|null $navigationGroup = 'Administració';

    protected static ?string $navigationLabel = 'Cicles';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('codi')->required()->unique(ignoreRecord: true)->placeholder('SMIX'),
                TextInput::make('nom')->required()->placeholder('Sistemes Microinformàtics i Xarxes'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('codi')
            ->modifyQueryUsing(fn ($query) => $query->withCount(['modules', 'students']))
            ->columns([
                TextColumn::make('codi')->searchable()->sortable(),
                TextColumn::make('nom')->searchable(),
                TextColumn::make('modules_count')->label('Mòduls'),
                TextColumn::make('students_count')->label('Alumnes'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->disabled(fn (Cycle $record) => $record->modules_count + $record->students_count > 0)
                    ->tooltip(fn (Cycle $record) => $record->modules_count + $record->students_count > 0 ? 'Té mòduls o alumnes: no es pot esborrar.' : null),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCycles::route('/'),
        ];
    }
}
