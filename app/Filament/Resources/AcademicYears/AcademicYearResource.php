<?php

namespace App\Filament\Resources\AcademicYears;

use App\Filament\Resources\AcademicYears\Pages\ManageAcademicYears;
use App\Models\AcademicYear;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Cursos acadèmics. Només n'hi ha un d'actual; un curs amb mòduls o alumnes no es pot esborrar. */
class AcademicYearResource extends Resource
{
    protected static ?string $model = AcademicYear::class;

    protected static ?string $slug = 'cursos';

    protected static ?string $modelLabel = 'curs';

    protected static ?string $pluralModelLabel = 'cursos';

    protected static ?string $recordTitleAttribute = 'nom';

    protected static string|UnitEnum|null $navigationGroup = 'Administració';

    protected static ?string $navigationLabel = 'Cursos';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('nom')->required()->unique(ignoreRecord: true)->placeholder('2026/27'),
                Toggle::make('es_actual')
                    ->label('Curs actual')
                    ->helperText('Només n\'hi pot haver un: marcar-lo desmarca l\'anterior.')
                    ->inline(false),
                DatePicker::make('data_inici')->label('Inici')->required(),
                DatePicker::make('data_fi')->label('Fi')->required()->afterOrEqual('data_inici'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('data_inici', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->withCount(['modules', 'students']))
            ->columns([
                TextColumn::make('nom')->searchable()->sortable(),
                TextColumn::make('data_inici')->label('Inici')->date('d/m/Y')->sortable(),
                TextColumn::make('data_fi')->label('Fi')->date('d/m/Y'),
                IconColumn::make('es_actual')->label('Actual')->boolean(),
                TextColumn::make('modules_count')->label('Mòduls'),
                TextColumn::make('students_count')->label('Alumnes'),
            ])
            ->recordActions([
                EditAction::make()->after(fn (AcademicYear $record) => self::keepSingleCurrent($record)),
                DeleteAction::make()
                    ->disabled(fn (AcademicYear $record) => $record->modules_count + $record->students_count > 0)
                    ->tooltip(fn (AcademicYear $record) => $record->modules_count + $record->students_count > 0 ? 'Té mòduls o alumnes: no es pot esborrar.' : null),
            ]);
    }

    /** Si aquest curs és l'actual, els altres deixen de ser-ho. */
    public static function keepSingleCurrent(AcademicYear $record): void
    {
        if ($record->es_actual) {
            AcademicYear::whereKeyNot($record->id)->where('es_actual', true)->update(['es_actual' => false]);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAcademicYears::route('/'),
        ];
    }
}
