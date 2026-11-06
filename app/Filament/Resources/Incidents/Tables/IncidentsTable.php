<?php

namespace App\Filament\Resources\Incidents\Tables;

use App\Enums\IncidentStatus;
use App\Filament\Actions\LaunchIncident;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Models\AcademicYear;
use App\Models\Incident;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                TextColumn::make('nom')->searchable()->sortable()->weight('bold')
                    ->description(fn (Incident $record) => str($record->simptoma)->limit(90)),
                TextColumn::make('module.codi')->label('Mòdul')->tooltip(fn (Incident $record) => $record->module?->nom),
                TextColumn::make('block.numero')->label('Bloc')->placeholder('—'),
                TextColumn::make('equip')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('options_count')->label('Opcions'),
                TextColumn::make('obertes_count')->label('Obertes')->color(fn (int $state) => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('resoltes_count')->label('Resoltes')->color('success'),
            ])
            ->filters([
                SelectFilter::make('curs')
                    ->label('Curs')
                    ->options(fn () => AcademicYear::regular()->orderByDesc('data_inici')->pluck('nom', 'id'))
                    ->default(fn () => AcademicYear::where('es_actual', true)->value('id'))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $id) => $q->whereHas('module', fn (Builder $m) => $m->where('curs_id', $id)),
                    )),
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
