<?php

namespace App\Filament\Resources\Students\Schemas;

use App\Models\AcademicYear;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
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
                        TextInput::make('num_taula')->label('Taula')->numeric()->minValue(1),
                        TextInput::make('x')->label('X')->numeric()->helperText('Índex de xarxa: 172.X / 10.X.'),
                        Toggle::make('es_actiu')->label('Actiu')->default(true)->inline(false),
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
                    ->description('Tapen les globals i les derivades del mòdul amb el mateix nom. Serveixen per a les excepcions d\'un alumne.')
                    ->schema([
                        KeyValue::make('variables')
                            ->hiddenLabel()
                            ->keyLabel('Variable')
                            ->valueLabel('Valor')
                            ->addActionLabel('Afegir variable'),
                    ]),
                Section::make('Inventari calculat')
                    ->description('Com queda l\'inventari d\'aquest alumne amb les dades desades.')
                    ->visibleOn('edit')
                    ->schema([View::make('filament.student-inventory')]),
            ]);
    }
}
