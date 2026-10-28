<?php

namespace App\Filament\Resources\Students\Schemas;

use App\Enums\Role;
use App\Models\AcademicYear;
use App\Models\Student;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class StudentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Dades')
                    ->columns(3)
                    ->schema([
                        TextInput::make('usuari')->required()->maxLength(255),
                        TextInput::make('nom_complet')->label('Nom complet')->required()->maxLength(255),
                        TextInput::make('alies')->label('Àlies')->helperText('Nom al leaderboard públic.')->maxLength(255),
                        TextInput::make('num_taula')->label('Taula')->numeric()->minValue(0)->helperText('Pot ser 0 (p. ex. la taula del professor).'),
                        TextInput::make('x')->label('X')->numeric()->minValue(0)->helperText('Índex de xarxa: 172.X / 10.X.'),
                        Toggle::make('es_actiu')->label('Actiu')->default(true)->inline(false),
                        Select::make('usuari_id')
                            ->label('Usuari (accés)')
                            ->relationship('user', 'email', fn (Builder $query) => $query->where('rol', Role::Student))
                            ->searchable()
                            ->preload()
                            ->unique(ignoreRecord: true)
                            ->helperText('Usuari amb rol alumne que entra a /alumne amb aquesta fitxa. L\'importador l\'enllaça sol.'),
                        Select::make('curs_id')
                            ->label('Curs')
                            ->relationship('academicYear', 'nom')
                            ->default(fn () => AcademicYear::where('es_actual', true)->value('id'))
                            ->required(),
                        Select::make('cicle_id')->label('Cicle')->relationship('cycle', 'codi')->required(),
                        Select::make('modules')->label('Mòduls')
                            // Només id i nom: amb moduls.* el "select distinct" falla a Postgres per la columna json.
                            ->relationship('modules', 'nom', fn (Builder $query) => $query->select('moduls.id', 'moduls.nom'))
                            ->multiple()->preload(),
                    ]),
                Section::make('Variables pròpies')
                    ->description('Tapen les globals i les derivades del mòdul amb el mateix nom. Serveixen per a les excepcions d\'un alumne. Què rep l\'alumne i d\'on surt es veu al botó Inventari.')
                    ->schema([
                        KeyValue::make('variables')
                            // Són a la taula de variables, no a les columnes de l'alumne.
                            ->formatStateUsing(fn (?Student $record) => $record?->variables ?? [])
                            ->hiddenLabel()
                            ->keyLabel('Variable')
                            ->valueLabel('Valor')
                            ->addActionLabel('Afegir variable'),
                    ]),
            ]);
    }
}
