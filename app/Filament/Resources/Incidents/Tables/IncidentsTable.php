<?php

namespace App\Filament\Resources\Incidents\Tables;

use App\Enums\IncidentStatus;
use App\Filament\Actions\LaunchIncident;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Filament\Support\ScopeFilters;
use App\Models\AcademicYear;
use App\Models\Block;
use App\Models\Incident;
use App\Models\Module;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class IncidentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['module', 'block', 'task'])
                ->withCount([
                    'options',
                    'assignments as obertes_count' => fn (Builder $q) => $q->whereIn('estat', IncidentStatus::activeValues()),
                    'assignments as resoltes_count' => fn (Builder $q) => $q->where('estat', IncidentStatus::Resolta),
                ]))
            ->defaultSort('posicio')
            ->emptyStateHeading('Encara no hi ha cap incidència')
            ->emptyStateDescription('Una incidència és una avaria feta a propòsit als equips dels alumnes: una acció d\'avaria, un check que diu quan està resolta i, si vols, una acció de reparació.')
            ->columns([
                // Columna estreta: el nom i el símptoma ocupen com a molt 3 línies cadascun, tallats pel
                // navegador segons l'amplada real (no per caràcters); el text sencer, al tooltip.
                TextColumn::make('nom')->searchable()->sortable()->weight('bold')
                    ->wrap()
                    ->lineClamp(3)
                    ->tooltip(fn (Incident $record) => $record->nom."\n\n".$record->simptoma)
                    ->description(fn (Incident $record) => new HtmlString(
                        '<span style="display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; white-space: normal">'
                        .e($record->simptoma).'</span>'
                    ))
                    ->extraCellAttributes(['style' => 'min-width: 14rem; max-width: 22rem']),
                TextColumn::make('module.codi')->label('Mòdul')->tooltip(fn (Incident $record) => $record->module?->nom)
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy(
                        Module::select('codi')->whereColumn('moduls.id', 'incidencies.modul_id')->limit(1), $direction)),
                TextColumn::make('block.numero')->label('Bloc')->placeholder('—')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy(
                        Block::select('numero')->whereColumn('blocs.id', 'incidencies.bloc_id')->limit(1), $direction)),
                TextColumn::make('task.titol')->label('Tasca')->placeholder('—')->toggleable()
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy(
                        Task::select('titol')->whereColumn('tasques.id', 'incidencies.tasca_id')->limit(1), $direction)),
                TextColumn::make('equip')->badge()->color('gray')->placeholder('—')->sortable(),
                TextColumn::make('options_count')->label('Opcions')->sortable(),
                TextColumn::make('obertes_count')->label('Obertes')->color(fn (int $state) => $state > 0 ? 'danger' : 'gray')->sortable(),
                TextColumn::make('resoltes_count')->label('Resoltes')->color('success')->sortable(),
            ])
            ->filters([
                ScopeFilters::course('curs',
                    fn (Builder $q, int $id) => $q->whereHas('module', fn (Builder $m) => $m->where('curs_id', $id)),
                    children: ['modul', 'bloc', 'tasca'])
                    ->options(fn () => AcademicYear::regular()->orderByDesc('data_inici')->pluck('nom', 'id')),
                ScopeFilters::module('modul', 'curs', fn (Builder $q, int $id) => $q->where('modul_id', $id), children: ['bloc', 'tasca']),
                ScopeFilters::block('bloc', 'curs', 'modul', fn (Builder $q, int $id) => $q->where('bloc_id', $id), children: ['tasca']),
                ScopeFilters::task('tasca', 'bloc', fn (Builder $q, int $id) => $q->where('tasca_id', $id)),
                SelectFilter::make('equip')
                    ->options(fn () => Incident::whereNotNull('equip')->distinct()->orderBy('equip')->pluck('equip', 'equip'))
                    ->multiple(),
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
