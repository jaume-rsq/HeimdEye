<?php

namespace App\Filament\Resources\Actions\Tables;

use App\Enums\ActionOverview;
use App\Enums\ActionStatus;
use App\Enums\Executor;
use App\Filament\Actions\LaunchAction;
use App\Filament\Resources\Actions\ActionResource;
use App\Models\AcademicYear;
use App\Models\Action as ActionModel;
use App\Models\Module;
use App\Support\ActionSummary;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use WeakMap;

class ActionsTable
{
    /** @var WeakMap<ActionModel, ActionSummary>|null */
    private static ?WeakMap $summaries = null;

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['module', 'block', 'currentVersion']))
            ->defaultSort('posicio')
            // Un grup per bloc, plegable, com a la llista de checks: es veu la seqüència d'accions.
            ->groups([
                Group::make('bloc_id')
                    ->label('Bloc')
                    ->titlePrefixedWithLabel(false)
                    ->getTitleFromRecordUsing(fn (ActionModel $record) => $record->block
                        ? "{$record->module->codi} · Bloc {$record->block->numero}: {$record->block->titol}"
                        : "{$record->module->codi} · Sense bloc")
                    ->orderQueryUsing(fn (Builder $query, string $direction) => $query->orderByBlock($direction))
                    ->collapsible(),
            ])
            ->defaultGroup('bloc_id')
            // En lloc del desplegable d'agrupar: una casella i el botó d'ordre a la capçalera de les accions.
            ->groupingSettingsHidden()
            ->recordActionsColumnLabel(fn () => view('filament.partials.group-toggle', ['group' => 'bloc_id']))
            ->columns([
                TextColumn::make('posicio')
                    ->label('Posició')
                    ->state(fn (ActionModel $record) => $record->positionLabel())
                    ->tooltip('Bloc.acció: l\'ordre en què es llancen')
                    ->fontFamily('mono')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByPosition($direction)),
                IconColumn::make('estat')
                    ->label('Estat')
                    ->state(fn (ActionModel $record) => self::summary($record)->overview()->value)
                    ->icon(fn (string $state) => ActionOverview::from($state)->icon())
                    ->color(fn (string $state) => ActionOverview::from($state)->color())
                    ->tooltip(fn (ActionModel $record) => self::summary($record)->overview()->label().': '.self::summary($record)->describe()),
                TextColumn::make('nom')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (ActionModel $record) => $record->es_incidencia ? 'avaria (incidències)' : null),
                TextColumn::make('descripcio')
                    ->label('Descripció')
                    ->wrap()
                    ->grow()
                    ->extraAttributes(['style' => 'min-width: 22rem']),
                TextColumn::make('executor')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('module.codi')
                    ->label('Mòdul')
                    ->tooltip(fn (ActionModel $record) => $record->module?->nom)
                    ->toggleable(),
                TextColumn::make('currentVersion.versio')
                    ->label('Versió')
                    ->prefix('v')
                    ->placeholder('sense codi'),
                TextColumn::make('updated_at')
                    ->label('Modificada')
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
                        fn (Builder $q, $id) => $q->whereHas('module', fn (Builder $m) => $m->where('curs_id', $id)),
                    )),
                SelectFilter::make('modul_id')
                    ->label('Mòdul')
                    ->relationship('module', 'nom', fn (Builder $query) => $query->orderBy('codi'))
                    ->getOptionLabelFromRecordUsing(fn (Module $m) => trim("{$m->codi} {$m->nom}"))
                    ->columnSpan(2),
                SelectFilter::make('executor')
                    ->options(collect(Executor::cases())->mapWithKeys(fn (Executor $e) => [$e->value => $e->value])),
                Filter::make('execucions')
                    ->label('Execucions')
                    ->columns(2)
                    ->columnSpan(2)
                    ->schema([
                        Select::make('estat')
                            ->label('Darrera execució en estat')
                            ->options(collect(ActionStatus::cases())->mapWithKeys(fn (ActionStatus $s) => [$s->value => $s->value])),
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
                        $status = ActionStatus::from($data['estat']);
                        $all = ($data['abast'] ?? 'algun') === 'tots';
                        $ids = collect(ActionSummary::forMany())->filter(fn (ActionSummary $s) => $s->has($status, $all))->keys();

                        return $query->whereKey($ids);
                    })
                    ->indicateUsing(fn (array $data) => blank($data['estat'] ?? null) ? null
                        : "Darrera execució {$data['estat']} per a ".(($data['abast'] ?? 'algun') === 'tots' ? 'tots els alumnes' : 'algun alumne')),
            ], layout: FiltersLayout::AboveContent)
            // Amb poc espai, els filtres passen a dues línies.
            ->filtersFormColumns(['default' => 1, 'md' => 3, 'xl' => 6])
            ->deferFilters(false)
            ->recordActions([
                Action::make('versions')
                    ->label('Versions')
                    ->icon(Heroicon::OutlinedRectangleStack)
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Versions')
                    ->url(fn (ActionModel $record) => ActionResource::getUrl('versions', ['record' => $record])),
                Action::make('historial')
                    ->label('Historial')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Historial d\'execucions')
                    ->url(fn (ActionModel $record) => ActionResource::getUrl('runs', ['record' => $record])),
                EditAction::make()
                    ->label('Editar darrera versió')
                    ->iconButton()
                    ->tooltip('Editar darrera versió')
                    ->visible(fn (ActionModel $record) => ActionResource::canEdit($record)),
                // Simular només surt a les d'Ansible: a la resta, Executar va sol, sense menú.
                LaunchAction::make(simulate: true)->button(),
                LaunchAction::make()->button(),
            ]);
    }

    private static function summary(ActionModel $record): ActionSummary
    {
        self::$summaries ??= new WeakMap;

        return self::$summaries[$record] ??= ActionSummary::for($record);
    }
}
