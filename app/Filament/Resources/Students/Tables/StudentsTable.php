<?php

namespace App\Filament\Resources\Students\Tables;

use App\Models\Student;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

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
                TextColumn::make('variables')
                    ->label('Variables pròpies')
                    ->state(fn (Student $record) => count($record->variables ?? []))
                    ->formatStateUsing(fn (int $state) => $state ?: '—'),
                IconColumn::make('es_actiu')->label('Actiu')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('es_actiu')->label('Actiu')->default(true),
                SelectFilter::make('modul')->label('Mòdul')->relationship('modules', 'nom'),
                SelectFilter::make('curs_id')->label('Curs')->relationship('academicYear', 'nom'),
            ])
            ->recordActions([
                EditAction::make()->label('Fitxa'),
            ]);
    }
}
