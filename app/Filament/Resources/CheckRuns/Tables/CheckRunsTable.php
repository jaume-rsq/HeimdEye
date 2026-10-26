<?php

namespace App\Filament\Resources\CheckRuns\Tables;

use App\Enums\CheckStatus;
use App\Enums\RunOrigin;
use App\Models\Check;
use App\Models\CheckRun;
use App\Models\RunBatch;
use App\Models\Student;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Historial d'execucions de checks: global (tots els checks) o d'un sol check.
 * Les proves de l'editor no hi surten: no es desen.
 */
class CheckRunsTable
{
    public static function configure(Table $table, ?Check $check = null): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['check', 'checkVersion', 'student', 'batch.triggeredBy']))
            ->defaultSort('id', 'desc')
            ->poll(fn () => RunBatch::where('origen', '!=', RunOrigin::Action)->whereNull('data_fi')->exists() ? '5s' : null)
            ->emptyStateHeading('Encara no s\'ha executat')
            ->recordAction('sortida')
            ->columns([
                TextColumn::make('check.nom')
                    ->label('Check')
                    ->searchable()
                    ->visible($check === null),
                TextColumn::make('checkVersion.versio')
                    ->label('Versió')
                    ->prefix('v'),
                TextColumn::make('student.nom_complet')
                    ->label('Alumne')
                    ->formatStateUsing(fn (CheckRun $record) => sprintf('%02d · %s', $record->student->num_taula, $record->student->nom_complet))
                    ->searchable(['nom_complet', 'usuari']),
                TextColumn::make('batch.origen')
                    ->label('Origen')
                    ->formatStateUsing(fn (RunOrigin $state) => $state->value)
                    ->badge()
                    ->color('gray'),
                TextColumn::make('batch.triggeredBy.nom')
                    ->label('Llançat per')
                    ->placeholder('—'),
                TextColumn::make('estat')
                    ->badge(),
                TextColumn::make('missatge')
                    ->label('Missatge')
                    ->limit(60)
                    ->placeholder('—'),
                TextColumn::make('durada_ms')
                    ->label('Durada')
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : round($state / 1000, 1).' s')
                    ->placeholder('—'),
                TextColumn::make('data_execucio')
                    ->label('Data')
                    ->dateTime('d/m/y H:i:s')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('check_id')
                    ->label('Check')
                    ->relationship('check', 'nom')
                    ->searchable()
                    ->preload()
                    ->visible($check === null),
                SelectFilter::make('versio_check_id')
                    ->label('Versió')
                    ->options(fn () => $check?->versions()->orderByDesc('versio')->pluck('versio', 'id')->map(fn ($v) => "v{$v}") ?? [])
                    ->visible($check !== null),
                SelectFilter::make('alumne_id')
                    ->label('Alumne')
                    ->options(fn () => Student::where('es_actiu', true)
                        ->when($check, fn (Builder $q) => $q->whereHas('modules', fn (Builder $m) => $m->whereKey($check->module->id)))
                        ->orderBy('num_taula')->get()
                        ->mapWithKeys(fn (Student $s) => [$s->id => sprintf('%02d · %s', $s->num_taula, $s->nom_complet)]))
                    ->searchable(),
                SelectFilter::make('estat')
                    ->options(CheckStatus::class)
                    ->multiple(),
                SelectFilter::make('origen')
                    ->options([RunOrigin::Nightly->value => 'nocturna', RunOrigin::Manual->value => 'manual'])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $origin) => $q->whereHas('batch', fn (Builder $b) => $b->where('origen', $origin)),
                    )),
                Filter::make('dates')
                    ->columns(2)
                    ->columnSpan(2)
                    ->schema([
                        DatePicker::make('des')->label('Des de'),
                        DatePicker::make('fins')->label('Fins a'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['des'] ?? null, fn (Builder $q, $date) => $q->whereDate('data_execucio', '>=', $date))
                        ->when($data['fins'] ?? null, fn (Builder $q, $date) => $q->whereDate('data_execucio', '<=', $date)))
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
                    ->modalHeading(fn (CheckRun $record) => "{$record->check->nom} · {$record->student->nom_complet}")
                    ->modalContent(fn (CheckRun $record) => view('filament.check-run-output', ['run' => $record]))
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tancar'),
            ]);
    }
}
