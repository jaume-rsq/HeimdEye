<?php

namespace App\Filament\Resources\Students\Tables;

use App\Models\Module;
use App\Models\Student;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class StudentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('num_taula')
            ->columns([
                TextColumn::make('num_taula')->label('Taula')->formatStateUsing(fn ($state) => sprintf('%02d', $state))->sortable(),
                TextColumn::make('usuari')->searchable()->sortable(),
                TextColumn::make('nom_complet')->label('Nom')->searchable()->sortable(),
                TextColumn::make('alies')->label('Àlies')->placeholder('—'),
                TextColumn::make('x')->label('X')->sortable(),
                TextColumn::make('modules.codi')->label('Mòduls')->badge(),
                TextColumn::make('own_variables_count')
                    ->label('Variables pròpies')
                    ->counts('ownVariables')
                    ->formatStateUsing(fn (int $state) => $state ?: '—'),
                IconColumn::make('es_actiu')->label('Actiu')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('es_actiu')->label('Actiu')->default(true),
                // Sense relationship(): Filament hi fa un "select distinct moduls.*" i Postgres no
                // sap comparar la columna json "combina".
                SelectFilter::make('modul')
                    ->label('Mòdul')
                    ->options(fn () => Module::orderBy('nom')->pluck('nom', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'],
                        fn (Builder $query, $id) => $query->whereHas('modules', fn (Builder $query) => $query->whereKey($id)),
                    )),
                SelectFilter::make('curs_id')->label('Curs')->relationship('academicYear', 'nom'),
            ])
            ->recordActions([
                EditAction::make()->label('Fitxa'),
            ])
            ->toolbarActions([
                BulkAction::make('desactivar')
                    ->label('Marcar inactius')
                    ->icon(Heroicon::OutlinedPauseCircle)
                    ->requiresConfirmation()
                    ->modalDescription('Deixen de sortir a les execucions i al leaderboard. No s\'esborra res.')
                    ->action(fn (Collection $records) => Student::whereKey($records->modelKeys())->update(['es_actiu' => false]))
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('activar')
                    ->label('Marcar actius')
                    ->icon(Heroicon::OutlinedPlayCircle)
                    ->action(fn (Collection $records) => Student::whereKey($records->modelKeys())->update(['es_actiu' => true]))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }
}
