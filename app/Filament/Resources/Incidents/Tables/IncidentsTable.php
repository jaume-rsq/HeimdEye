<?php

namespace App\Filament\Resources\Incidents\Tables;

use App\Enums\IncidentStatus;
use App\Filament\Actions\LaunchIncident;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Filament\Support\ScopeFilters;
use App\Models\AcademicYear;
use App\Models\Incident;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IncidentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['module', 'block'])
                ->withCount([
                    'options',
                    'assignments as obertes_count' => fn (Builder $q) => $q->whereIn('estat', IncidentStatus::activeValues()),
                    'assignments as resoltes_count' => fn (Builder $q) => $q->where('estat', IncidentStatus::Resolta),
                ]))
            ->defaultSort('posicio')
            ->columns([
                // Columna estreta: el nom ocupa com a molt 3 línies (i el símptoma 2); la resta, al tooltip.
                TextColumn::make('nom')->searchable()->sortable()->weight('bold')
                    ->wrap()
                    ->lineClamp(3)
                    ->tooltip(fn (Incident $record) => $record->nom."\n\n".$record->simptoma)
                    ->description(fn (Incident $record) => str($record->simptoma)->limit(70))
                    ->extraCellAttributes(['style' => 'min-width: 14rem; max-width: 22rem']),
                TextColumn::make('module.codi')->label('Mòdul')->tooltip(fn (Incident $record) => $record->module?->nom),
                TextColumn::make('block.numero')->label('Bloc')->placeholder('—'),
                TextColumn::make('equip')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('options_count')->label('Opcions'),
                TextColumn::make('obertes_count')->label('Obertes')->color(fn (int $state) => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('resoltes_count')->label('Resoltes')->color('success'),
            ])
            ->filters([
                ScopeFilters::course('curs',
                    fn (Builder $q, int $id) => $q->whereHas('module', fn (Builder $m) => $m->where('curs_id', $id)),
                    children: ['modul'])
                    ->options(fn () => AcademicYear::regular()->orderByDesc('data_inici')->pluck('nom', 'id')),
                ScopeFilters::module('modul', 'curs', fn (Builder $q, int $id) => $q->where('modul_id', $id)),
            ])
            ->recordActions([
                Action::make('seguiment')
                    ->label('Seguiment')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Seguiment dels alumnes')
                    ->url(fn (Incident $record) => IncidentResource::getUrl('seguiment', ['record' => $record])),
                EditAction::make()->iconButton()->tooltip('Editar'),
                LaunchIncident::make()->button(),
            ]);
    }
}
