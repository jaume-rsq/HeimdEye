<?php

namespace App\Filament\Resources\Incidents\Pages;

use App\Enums\IncidentStatus;
use App\Filament\Actions\LaunchIncident;
use App\Filament\Resources\Actions\ActionResource;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Models\ActionRun;
use App\Models\Incident;
use App\Models\IncidentLaunch;
use App\Models\IncidentStudent;
use App\Models\Student;
use App\Runner\Incidents\IncidentLauncher;
use App\Runner\Incidents\IncidentTracker;
use Carbon\CarbonInterval;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Attributes\Url;

/**
 * Seguiment d'una incidència: una fila per alumne i avaria, amb l'estat (es refresca sol), quan
 * s'ha injectat i quant ha trigat a resoldre-la, i els botons per comprovar, reparar, tancar o esborrar.
 *
 * @property Incident $record
 */
class ManageIncidentAssignments extends ManageRelatedRecords
{
    protected static string $resource = IncidentResource::class;

    protected static string $relationship = 'assignments';

    protected Width|string|null $maxContentWidth = Width::Full;

    /** Filtre inicial pel llançament que s'acaba de fer. */
    #[Url]
    public ?int $llancament = null;

    private const DELETE_WARNING = 'S\'esborra el registre (estat, temps, historial d\'avaries de l\'alumne), però no es toca l\'equip: si l\'avaria hi és injectada, es queda. Si cal, repara-la abans.';

    public function getTitle(): string|Htmlable
    {
        return 'Seguiment de '.$this->getOwnerRecord()->nom;
    }

    public function getBreadcrumb(): string
    {
        return 'Seguiment';
    }

    protected function getHeaderActions(): array
    {
        return [LaunchIncident::make()->record($this->getOwnerRecord())];
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['student', 'launch', 'option.check', 'option.repairAction'])
                ->addSelect(['instancia' => self::instanceQuery()->select('instancies_agent.hostname')->limit(1)])
                ->when($this->llancament, fn (Builder $q, int $id) => $q->where('llancament_id', $id)))
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('launch.created_at')
                    ->label('Llançada')
                    ->dateTime('d/m H:i')
                    ->sortable(query: fn (Builder $query, string $direction) => $query
                        ->orderBy('llancament_id', $direction)
                        ->orderBy('incidencies_alumne.id', $direction)),
                TextColumn::make('student.usuari')
                    ->label('Alumne')
                    ->description(fn (IncidentStudent $record) => $record->student?->nom_complet)
                    ->searchable()
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy(
                        Student::select('num_taula')->whereColumn('alumnes.id', 'incidencies_alumne.alumne_id')->limit(1),
                        $direction,
                    )),
                TextColumn::make('instancia')
                    ->label('Instància')
                    ->tooltip('Instància d\'agent que ha executat l\'avaria')
                    ->placeholder('—')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('instancia', $direction)),
                TextColumn::make('nom_opcio')
                    ->label('Avaria')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('estat')
                    ->badge()
                    ->tooltip(fn (IncidentStudent $record) => $record->missatge)
                    ->sortable(),
                TextColumn::make('data_injeccio')
                    ->label('Injectada')
                    ->dateTime('d/m H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('data_resolucio')
                    ->label('Resolta')
                    ->dateTime('d/m H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('temps')
                    ->label('Ha trigat')
                    ->state(fn (IncidentStudent $record) => ($s = $record->resolutionSeconds()) === null ? null
                        : CarbonInterval::seconds($s)->cascade()->forHumans(['short' => true, 'parts' => 2]))
                    ->placeholder('—')
                    // Les que no estan resoltes, sempre al final.
                    ->sortable(query: fn (Builder $query, string $direction) => $query
                        ->orderByRaw('case when data_resolucio is null or data_injeccio is null then 1 else 0 end')
                        ->orderByRaw(self::durationSql().' '.($direction === 'desc' ? 'desc' : 'asc'))),
                TextColumn::make('launch.data_limit')
                    ->label('Termini')
                    ->dateTime('d/m H:i')
                    ->color(fn (IncidentStudent $record) => $record->estat->isActive() && $record->launch?->data_limit?->isPast() ? 'danger' : null)
                    ->placeholder('—')
                    ->toggleable()
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy(
                        IncidentLaunch::select('data_limit')->whereColumn('llancaments_incidencia.id', 'incidencies_alumne.llancament_id')->limit(1),
                        $direction,
                    )),
            ])
            ->filters([
                // Els alumnes que han rebut alguna avaria d'aquesta incidència.
                SelectFilter::make('alumne_id')
                    ->label('Alumne')
                    ->options(fn () => Student::whereIn('id', $this->getOwnerRecord()->assignments()->select('alumne_id'))
                        ->orderBy('num_taula')->get()
                        ->mapWithKeys(fn (Student $s) => [$s->id => sprintf('%02d · %s', $s->num_taula, $s->nom_complet ?: $s->usuari)]))
                    ->searchable()
                    ->multiple(),
                SelectFilter::make('estat')
                    ->options(IncidentStatus::class)
                    ->multiple(),
                // Per hostname de la instància d'agent que l'ha executada (aws, web...).
                SelectFilter::make('instancia')
                    ->label('Instància')
                    ->options(fn () => self::instanceQuery(false)
                        ->whereIn('execucions_accio.execucio_id', $this->getOwnerRecord()->assignments()->select('execucio_id'))
                        ->distinct()
                        ->orderBy('instancies_agent.hostname')
                        ->pluck('instancies_agent.hostname', 'instancies_agent.hostname'))
                    ->multiple()
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['values'] ?? [],
                        fn (Builder $q, array $hosts) => $q->whereExists(self::instanceQuery()->whereIn('instancies_agent.hostname', $hosts)->toBase()),
                    )),
                SelectFilter::make('opcio_id')
                    ->label('Avaria')
                    ->options(fn () => $this->getOwnerRecord()->options()->pluck('nom', 'id'))
                    ->multiple(),
            ])
            ->recordActions([
                Action::make('accio')
                    ->label('Execució')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Historial de l\'acció d\'avaria (amb la sortida)')
                    ->visible(fn (IncidentStudent $record) => $record->option?->accio_id !== null)
                    ->url(fn (IncidentStudent $record) => ActionResource::getUrl('runs', ['record' => $record->option->accio_id])),
                Action::make('comprovar')
                    ->label('Comprovar')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->iconButton()
                    ->tooltip('Llançar ara el check de resolució')
                    ->visible(fn (IncidentStudent $record) => in_array($record->estat, [IncidentStatus::Verificant, IncidentStatus::Oberta], true) && $record->option?->check_id)
                    ->action(fn (IncidentStudent $record) => self::verify(collect([$record]))),
                Action::make('reparar')
                    ->label('Reparar')
                    ->icon(Heroicon::OutlinedWrench)
                    ->color('info')
                    ->iconButton()
                    ->tooltip(fn (IncidentStudent $record) => 'Reparar amb '.$record->option?->repairAction?->nom)
                    ->visible(fn (IncidentStudent $record) => $record->option?->accio_reparacio_id && ! in_array($record->estat, [IncidentStatus::Reparada, IncidentStatus::Pendent], true))
                    ->requiresConfirmation()
                    ->modalDescription('S\'executa l\'acció de reparació a l\'equip de l\'alumne i la incidència queda tancada com a reparada.')
                    ->action(function (IncidentStudent $record) {
                        try {
                            app(IncidentLauncher::class)->repair($record, auth()->user());
                            Notification::make()->success()->title('Reparació a la cua')->send();
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                Action::make('tancar')
                    ->label('Tancar')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Tancar sense resoldre (no repara res)')
                    ->visible(fn (IncidentStudent $record) => $record->estat->isActive())
                    ->requiresConfirmation()
                    ->action(fn (IncidentStudent $record) => self::close(collect([$record]))),
                Action::make('esborrar')
                    ->label('Esborrar')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->iconButton()
                    ->tooltip('Esborrar aquesta fila del seguiment')
                    ->requiresConfirmation()
                    ->modalDescription(self::DELETE_WARNING)
                    ->action(fn (IncidentStudent $record) => self::delete(collect([$record]))),
            ])
            ->toolbarActions([
                BulkAction::make('comprovarTots')
                    ->label('Comprovar')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->action(fn (Collection $records) => self::verify($records)),
                BulkAction::make('repararTots')
                    ->label('Reparar')
                    ->icon(Heroicon::OutlinedWrench)
                    ->requiresConfirmation()
                    ->modalDescription('S\'executa l\'acció de reparació de cada avaria marcada a l\'equip del seu alumne, i queden tancades com a reparades. Les pendents (encara no injectades), les ja reparades i les que no tenen acció de reparació se salten.')
                    ->action(fn (Collection $records) => self::repair($records)),
                BulkAction::make('tancarTots')
                    ->label('Tancar')
                    ->icon(Heroicon::OutlinedXMark)
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => self::close($records)),
                BulkAction::make('esborrarTots')
                    ->label('Esborrar')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(self::DELETE_WARNING)
                    ->action(fn (Collection $records) => self::delete($records)),
            ]);
    }

    /**
     * Execucions de l'acció d'avaria amb la instància d'agent que les ha recollit. Correlacionada
     * amb la fila (mateix lot i alumne) per a la columna i el filtre; sense, per llistar-les totes.
     */
    private static function instanceQuery(bool $correlated = true): Builder
    {
        return ActionRun::query()
            ->join('instancies_agent', 'instancies_agent.id', '=', 'execucions_accio.instancia_id')
            ->when($correlated, fn (Builder $q) => $q
                ->whereColumn('execucions_accio.execucio_id', 'incidencies_alumne.execucio_id')
                ->whereColumn('execucions_accio.alumne_id', 'incidencies_alumne.alumne_id'));
    }

    /** Segons entre la injecció i la resolució, en SQL (PostgreSQL en producció, SQLite als tests). */
    private static function durationSql(): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? 'extract(epoch from (data_resolucio - data_injeccio))'
            : '(julianday(data_resolucio) - julianday(data_injeccio))';
    }

    /** @param  \Illuminate\Support\Collection<int, IncidentStudent>  $records */
    private static function verify($records): void
    {
        $tracker = app(IncidentTracker::class);
        $n = $records
            ->filter(fn (IncidentStudent $r) => in_array($r->estat, [IncidentStatus::Verificant, IncidentStatus::Oberta], true))
            ->filter(fn (IncidentStudent $r) => $tracker->verify($r, auth()->user()))
            ->count();

        Notification::make()->success()->title("Check llançat per a {$n} avaries")->send();
    }

    /** @param  \Illuminate\Support\Collection<int, IncidentStudent>  $records */
    private static function repair($records): void
    {
        $launcher = app(IncidentLauncher::class);
        $n = 0;
        $errors = [];
        foreach ($records as $record) {
            if (! $record->option?->accio_reparacio_id || in_array($record->estat, [IncidentStatus::Reparada, IncidentStatus::Pendent], true)) {
                continue;
            }
            try {
                $launcher->repair($record, auth()->user());
                $n++;
            } catch (InvalidArgumentException $e) {
                $errors[] = ($record->student?->usuari ?? '?').': '.$e->getMessage();
            }
        }

        Notification::make()->{$errors ? 'warning' : 'success'}()
            ->title("{$n} reparacions a la cua")
            ->body($errors ? implode("\n", $errors) : null)
            ->send();
    }

    /**
     * Esborra les files (per netejar proves o llançaments que han fallat) i els llançaments que
     * es queden sense cap fila. Les execucions de l'acció i dels checks es queden a l'historial.
     *
     * @param  \Illuminate\Support\Collection<int, IncidentStudent>  $records
     */
    private static function delete($records): void
    {
        $launchIds = $records->pluck('llancament_id')->unique()->all();
        $n = IncidentStudent::whereKey($records->pluck('id')->all())->delete();
        IncidentLaunch::whereKey($launchIds)->whereDoesntHave('assignments')->delete();

        Notification::make()->success()->title("{$n} avaries esborrades")->send();
    }

    /** @param  \Illuminate\Support\Collection<int, IncidentStudent>  $records */
    private static function close($records): void
    {
        $n = 0;
        foreach ($records as $record) {
            if ($record->estat->isActive()) {
                $record->update(['estat' => IncidentStatus::Tancada, 'tancat_per' => auth()->id()]);
                $n++;
            }
        }

        Notification::make()->success()->title("{$n} avaries tancades")->send();
    }
}
