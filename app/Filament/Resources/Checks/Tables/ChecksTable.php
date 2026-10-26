<?php

namespace App\Filament\Resources\Checks\Tables;

use App\Enums\CheckKind;
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
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
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
            ->defaultSort('updated_at', 'desc')
            ->columns([
                IconColumn::make('estat')
                    ->label('Estat')
                    ->state(fn (Check $record) => self::summary($record)->overview()->value)
                    ->icon(fn (string $state) => CheckOverview::from($state)->icon())
                    ->color(fn (string $state) => CheckOverview::from($state)->color())
                    ->tooltip(fn (Check $record) => self::summary($record)->overview()->label().': '.self::summary($record)->describe()),
                TextColumn::make('nom')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Check $record) => $record->descripcio)
                    ->wrap(),
                TextColumn::make('task.titol')
                    ->label('Tasca')
                    ->description(fn (Check $record) => "Bloc {$record->task->block->numero} · {$record->task->block->module->nom}")
                    ->wrap(),
                TextColumn::make('executor')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('punts')
                    ->description(fn (Check $record) => $record->tipus === CheckKind::Optional ? 'opcional' : null),
                IconColumn::make('actiu')
                    ->label('Actiu')
                    ->state(fn (Check $record) => $record->isActiveOn())
                    ->boolean()
                    ->tooltip(fn (Check $record) => match (true) {
                        $record->es_actiu !== null => $record->es_actiu ? 'Actiu (forçat)' : 'Inactiu (forçat)',
                        default => 'Segons les dates: '.($record->actiu_des?->format('d/m/Y') ?? '…').' – '.($record->actiu_fins?->format('d/m/Y') ?? '…'),
                    }),
                TextColumn::make('currentVersion.versio')
                    ->label('Versió')
                    ->prefix('v')
                    ->placeholder('sense codi'),
                TextColumn::make('updated_at')
                    ->label('Modificat')
                    ->since()
                    ->dateTimeTooltip('d/m/Y H:i')
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
                Action::make('versions')
                    ->label('Versions')
                    ->icon(Heroicon::OutlinedRectangleStack)
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Versions')
                    ->url(fn (Check $record) => CheckResource::getUrl('versions', ['record' => $record])),
                Action::make('historial')
                    ->label('Historial')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Historial d\'execucions')
                    ->url(fn (Check $record) => CheckResource::getUrl('runs', ['record' => $record])),
                Action::make('duplicar')
                    ->label('Duplicar')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Duplicar com a base d\'un check nou')
                    ->url(fn (Check $record) => CheckResource::getUrl('create', ['copia' => $record->id])),
                EditAction::make()
                    ->label('Editar darrera versió')
                    ->iconButton()
                    ->tooltip('Editar darrera versió'),
                LaunchCheck::make()->button(),
            ]);
    }

    private static function summary(Check $record): CheckSummary
    {
        self::$summaries ??= new WeakMap;

        return self::$summaries[$record] ??= CheckSummary::for($record);
    }
}
