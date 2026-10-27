<?php

namespace App\Filament\Resources\Checks\Tables;

use App\Enums\CheckOverview;
use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Filament\Actions\LaunchCheck;
use App\Filament\Resources\Checks\CheckResource;
use App\Models\AcademicYear;
use App\Models\Block;
use App\Models\Check;
use App\Models\Module;
use App\Support\CheckSummary;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use WeakMap;

class ChecksTable
{
    /** @var WeakMap<Check, CheckSummary>|null */
    private static ?WeakMap $summaries = null;

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['task.block.module', 'currentVersion']))
            ->defaultSort('posicio')
            ->columns([
                TextColumn::make('posicio')
                    ->label('Posició')
                    ->state(fn (Check $record) => $record->positionLabel())
                    ->tooltip('Bloc.tasca.check: l\'ordre en què l\'alumne els ha d\'anar assolint')
                    ->fontFamily('mono')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByPosition($direction)),
                IconColumn::make('estat')
                    ->label('Estat')
                    ->state(fn (Check $record) => self::summary($record)->overview()->value)
                    ->icon(fn (string $state) => CheckOverview::from($state)->icon())
                    ->color(fn (string $state) => CheckOverview::from($state)->color())
                    ->tooltip(fn (Check $record) => self::summary($record)->overview()->label().': '.self::summary($record)->describe()),
                ViewColumn::make('nom')
                    ->label('Check')
                    ->view('filament.checks.check-cell')
                    ->searchable(['nom', 'descripcio'])
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Modificat')
                    // Fa poc: «fa 20 minuts»; si no, la data i l'hora en dues línies.
                    ->formatStateUsing(fn ($state) => $state->greaterThan(now()->subHours(2))
                        ? e($state->diffForHumans())
                        : $state->format('d/m/Y').'<br>'.$state->format('H:i:s'))
                    ->html()
                    ->dateTimeTooltip('d/m/Y H:i:s')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('curs')
                    ->label('Curs')
                    ->options(fn () => AcademicYear::orderByDesc('data_inici')->pluck('nom', 'id'))
                    ->default(fn () => AcademicYear::where('es_actual', true)->value('id'))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $id) => $q->whereHas('task.block.module', fn (Builder $m) => $m->where('curs_id', $id)),
                    )),
                SelectFilter::make('modul')
                    ->label('Mòdul')
                    ->options(fn () => Module::orderBy('nom')->pluck('nom', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $id) => $q->whereHas('task.block', fn (Builder $b) => $b->where('modul_id', $id)),
                    )),
                SelectFilter::make('bloc')
                    ->label('Bloc')
                    ->options(fn () => Block::with('module')->orderBy('modul_id')->orderBy('numero')->get()
                        ->mapWithKeys(fn (Block $b) => [$b->id => "{$b->module->nom} · Bloc {$b->numero}: {$b->titol}"]))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $id) => $q->whereHas('task', fn (Builder $t) => $t->where('bloc_id', $id)),
                    )),
                SelectFilter::make('executor')
                    ->options(collect(Executor::cases())->mapWithKeys(fn (Executor $e) => [$e->value => $e->value])),
                TernaryFilter::make('actiu')
                    ->label('Actiu avui')
                    ->queries(
                        true: fn (Builder $query) => $query->activeOn(),
                        false: fn (Builder $query) => $query->whereNotIn('id', Check::query()->activeOn()->select('id')),
                    ),
                Filter::make('execucions')
                    ->label('Execucions')
                    ->columns(2)
                    ->columnSpan(2)
                    ->schema([
                        Select::make('estat')
                            ->label('Darrera execució en estat')
                            ->options(collect(CheckStatus::cases())->reject(fn (CheckStatus $s) => $s === CheckStatus::Skipped)
                                ->mapWithKeys(fn (CheckStatus $s) => [$s->value => $s->value])),
                        Select::make('abast')
                            ->label('Per a')
                            ->options(['tots' => 'tots els alumnes', 'algun' => 'algun alumne'])
                            ->default('algun')
                            ->selectablePlaceholder(false),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (blank($data['estat'] ?? null)) {
                            return $query;
                        }
                        $status = CheckStatus::from($data['estat']);
                        $all = ($data['abast'] ?? 'algun') === 'tots';
                        $ids = collect(CheckSummary::forMany())->filter(fn (CheckSummary $s) => $s->has($status, $all))->keys();

                        return $query->whereKey($ids);
                    })
                    ->indicateUsing(fn (array $data) => blank($data['estat'] ?? null) ? null
                        : "Darrera execució {$data['estat']} per a ".(($data['abast'] ?? 'algun') === 'tots' ? 'tots els alumnes' : 'algun alumne')),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(5)
            ->deferFilters(false)
            ->recordActions([
                ActionGroup::make([
                    Action::make('versions')
                        ->hiddenLabel()
                        ->color('gray')
                        ->label('Versions')
                        ->icon(Heroicon::OutlinedRectangleStack)
                        ->tooltip('Versions')
                        ->url(fn (Check $record) => CheckResource::getUrl('versions', ['record' => $record])),
                    Action::make('historial')
                        ->hiddenLabel()
                        ->color('gray')
                        ->label('Historial')
                        ->icon(Heroicon::OutlinedClock)
                        ->tooltip('Historial d\'execucions')
                        ->url(fn (Check $record) => CheckResource::getUrl('runs', ['record' => $record])),
                    Action::make('duplicar')
                        ->hiddenLabel()
                        ->color('gray')
                        ->label('Duplicar')
                        ->icon(Heroicon::OutlinedDocumentDuplicate)
                        ->tooltip('Duplicar com a base d\'un check nou')
                        ->url(fn (Check $record) => CheckResource::getUrl('create', ['copia' => $record->id])),
                    EditAction::make()
                        ->hiddenLabel()
                        ->color('gray')
                        ->label('Editar')
                        ->tooltip('Editar darrera versió'),
                    LaunchCheck::make()
                        ->hiddenLabel()
                        ->tooltip('Verificar ara'),
                ])->buttonGroup(),
            ]);
    }

    private static function summary(Check $record): CheckSummary
    {
        self::$summaries ??= new WeakMap;

        return self::$summaries[$record] ??= CheckSummary::for($record);
    }
}
