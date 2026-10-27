<?php

namespace App\Filament\Resources\Modules\Tables;

use App\Filament\Resources\Modules\ModuleResource;
use App\Models\AcademicYear;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ModulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('codi')
            ->columns([
                TextColumn::make('codi')->sortable(),
                TextColumn::make('nom')->searchable(),
                TextColumn::make('academicYear.nom')->label('Curs'),
                TextColumn::make('cycle.codi')->label('Cicle'),
                TextColumn::make('host_types_count')->counts('hostTypes')->label('Tipus d\'equip'),
                TextColumn::make('variables_count')->counts('variables')->label('Variables'),
                TextColumn::make('secrets_count')->counts('secrets')->label('Secrets'),
            ])
            ->filters([
                SelectFilter::make('curs_id')
                    ->label('Curs')
                    ->relationship('academicYear', 'nom')
                    ->default(fn () => AcademicYear::where('es_actual', true)->value('id')),
            ])
            ->recordActions([
                EditAction::make()->label('Equips i variables'),
                ViewAction::make()->label('Equips i variables')->hidden(fn ($record) => ModuleResource::canEdit($record)),
            ]);
    }
}
