<?php

namespace App\Filament\Resources\ActionRuns\Tables;

use App\Enums\ActionStatus;
use App\Enums\RunOrigin;
use App\Models\Action as ActionModel;
use App\Models\ActionRun;
use App\Models\RunBatch;
use App\Models\Student;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Historial d'execucions d'accions: global (totes les accions) o d'una sola acció.
 */
class ActionRunsTable
{
    public static function configure(Table $table, ?ActionModel $action = null): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['action', 'version', 'student', 'triggeredBy']))
            ->defaultSort('id', 'desc')
            ->poll(fn () => RunBatch::where('origen', RunOrigin::Action)->whereNull('data_fi')->exists() ? '5s' : null)
            ->recordAction('sortida')
            ->columns([
                TextColumn::make('action.nom')
                    ->label('Acció')
                    ->searchable()
                    ->visible($action === null),
                TextColumn::make('version.versio')
                    ->label('Versió')
                    ->prefix('v'),
                TextColumn::make('student.nom_complet')
                    ->label('Alumne')
                    ->formatStateUsing(fn (ActionRun $record) => sprintf('%02d · %s', $record->student->num_taula, $record->student->nom_complet))
                    ->searchable(['nom_complet', 'usuari']),
                TextColumn::make('triggeredBy.nom')
                    ->label('Llançat per')
                    ->placeholder('consola'),
                IconColumn::make('es_simulacio')
                    ->label('Simulació')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedBeaker)
                    ->falseIcon(Heroicon::OutlinedMinus)
                    ->trueColor('info')
                    ->falseColor('gray'),
                TextColumn::make('estat')
                    ->badge(),
                TextColumn::make('resum')
                    ->label('ok / changed / failed')
                    ->state(fn (ActionRun $record) => $record->ok === null ? null : "{$record->ok} / {$record->changed} / {$record->failed}")
                    ->placeholder('—'),
                TextColumn::make('sortida')
                    ->label('Sortida')
                    ->formatStateUsing(fn (?string $state) => strtok((string) $state, "\n"))
                    ->limit(60)
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('data_inici')
                    ->label('Inici')
                    ->dateTime('d/m/y H:i:s')
                    ->sortable(),
                TextColumn::make('data_fi')
                    ->label('Fi')
                    ->dateTime('d/m/y H:i:s')
                    ->description(fn (ActionRun $record) => $record->data_inici && $record->data_fi
                        ? $record->data_inici->diffInSeconds($record->data_fi).' s'
                        : null),
            ])
            ->filters([
                SelectFilter::make('accio_id')
                    ->label('Acció')
                    ->relationship('action', 'nom')
                    ->searchable()
                    ->preload()
                    ->visible($action === null),
                SelectFilter::make('versio_accio_id')
                    ->label('Versió')
                    ->options(fn () => $action?->versions()->orderByDesc('versio')->pluck('versio', 'id')->map(fn ($v) => "v{$v}") ?? [])
                    ->visible($action !== null),
                SelectFilter::make('alumne_id')
                    ->label('Alumne')
                    ->options(fn () => Student::where('es_actiu', true)
                        ->when($action, fn (Builder $q) => $q->whereHas('modules', fn (Builder $m) => $m->whereKey($action->modul_id)))
                        ->orderBy('num_taula')->get()
                        ->mapWithKeys(fn (Student $s) => [$s->id => sprintf('%02d · %s', $s->num_taula, $s->nom_complet)]))
                    ->searchable(),
                SelectFilter::make('estat')
                    ->options(ActionStatus::class)
                    ->multiple(),
                TernaryFilter::make('es_simulacio')
                    ->label('Simulació')
                    ->trueLabel('Només simulacions')
                    ->falseLabel('Només execucions reals'),
                Filter::make('dates')
                    ->columns(2)
                    ->columnSpan(2)
                    ->schema([
                        DatePicker::make('des')->label('Des de'),
                        DatePicker::make('fins')->label('Fins a'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['des'] ?? null, fn (Builder $q, $date) => $q->whereDate('data_inici', '>=', $date))
                        ->when($data['fins'] ?? null, fn (Builder $q, $date) => $q->whereDate('data_inici', '<=', $date)))
                    ->indicateUsing(fn (array $data) => array_filter([
                        filled($data['des'] ?? null) ? 'Des de '.$data['des'] : null,
                        filled($data['fins'] ?? null) ? 'Fins a '.$data['fins'] : null,
                    ])),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
            ->deferFilters(false)
            ->recordActions([
                Action::make('sortida')
                    ->label('Sortida')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->modalHeading(fn (ActionRun $record) => "{$record->action->nom} · {$record->student->nom_complet}")
                    ->modalContent(fn (ActionRun $record) => view('filament.action-run-output', ['run' => $record]))
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tancar'),
            ]);
    }
}
