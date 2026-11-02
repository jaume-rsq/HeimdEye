<?php

namespace App\Filament\Resources\Groups;

use App\Filament\Resources\Groups\Pages\ManageGroups;
use App\Models\AcademicYear;
use App\Models\Cycle;
use App\Models\Group;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * Grups-classe de cada curs (1r SMIX, 2n SMIX...). Un alumne és d'un grup i es matricula als mòduls;
 * el leaderboard i el seguiment es filtren per grup. Un grup amb alumnes no es pot esborrar.
 */
class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static ?string $slug = 'grups';

    protected static ?string $modelLabel = 'grup';

    protected static ?string $pluralModelLabel = 'grups';

    protected static ?string $recordTitleAttribute = 'codi';

    protected static string|UnitEnum|null $navigationGroup = 'Administració';

    protected static ?string $navigationLabel = 'Grups';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('curs_id')
                    ->label('Curs')
                    ->options(fn () => AcademicYear::orderByDesc('data_inici')->pluck('nom', 'id'))
                    ->default(fn () => AcademicYear::where('es_actual', true)->value('id'))
                    ->required(),
                Select::make('cicle_id')
                    ->label('Cicle')
                    ->options(fn () => Cycle::orderBy('codi')->pluck('codi', 'id'))
                    ->placeholder('Cap'),
                TextInput::make('codi')
                    ->required()
                    ->maxLength(30)
                    ->placeholder('SMIX2')
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('curs_id', $get('curs_id'))),
                TextInput::make('nom')->placeholder('2n SMIX'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('codi')
            ->modifyQueryUsing(fn ($query) => $query->with(['academicYear', 'cycle'])->withCount('students'))
            ->columns([
                TextColumn::make('codi')->searchable()->sortable(),
                TextColumn::make('nom')->searchable(),
                TextColumn::make('academicYear.nom')->label('Curs'),
                TextColumn::make('cycle.codi')->label('Cicle')->placeholder('—'),
                TextColumn::make('students_count')->label('Alumnes'),
            ])
            ->filters([
                SelectFilter::make('curs_id')
                    ->label('Curs')
                    ->options(fn () => AcademicYear::orderByDesc('data_inici')->pluck('nom', 'id'))
                    ->default(fn () => AcademicYear::where('es_actual', true)->value('id')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->disabled(fn (Group $record) => $record->students_count > 0)
                    ->tooltip(fn (Group $record) => $record->students_count > 0 ? 'Té alumnes: no es pot esborrar.' : null),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGroups::route('/'),
        ];
    }
}
