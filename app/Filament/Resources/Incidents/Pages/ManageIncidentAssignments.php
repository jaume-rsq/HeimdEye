<?php

namespace App\Filament\Resources\Incidents\Pages;

use App\Enums\IncidentStatus;
use App\Filament\Actions\LaunchIncident;
use App\Filament\Resources\Actions\ActionResource;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Models\Incident;
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
use InvalidArgumentException;
use Livewire\Attributes\Url;

/**
 * Seguiment d'una incidència: una fila per alumne i avaria, amb l'estat (es refresca sol), quan
 * s'ha injectat i quant ha trigat a resoldre-la, i els botons per comprovar, reparar o tancar.
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
                ->when($this->llancament, fn (Builder $q, int $id) => $q->where('llancament_id', $id)))
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('launch.created_at')
                    ->label('Llançada')
                    ->dateTime('d/m H:i')
                    ->sortable(),
                TextColumn::make('student.usuari')
                    ->label('Alumne')
                    ->description(fn (IncidentStudent $record) => $record->student?->nom_complet)
                    ->searchable(),
                TextColumn::make('nom_opcio')->label('Avaria'),
                TextColumn::make('estat')
                    ->badge()
                    ->tooltip(fn (IncidentStudent $record) => $record->missatge),
                TextColumn::make('data_injeccio')
                    ->label('Injectada')
                    ->dateTime('d/m H:i')
                    ->placeholder('—'),
                TextColumn::make('data_resolucio')
                    ->label('Resolta')
                    ->dateTime('d/m H:i')
                    ->placeholder('—'),
                TextColumn::make('temps')
                    ->label('Ha trigat')
                    ->state(fn (IncidentStudent $record) => ($s = $record->resolutionSeconds()) === null ? null
                        : CarbonInterval::seconds($s)->cascade()->forHumans(['short' => true, 'parts' => 2]))
                    ->placeholder('—'),
                TextColumn::make('launch.data_limit')
                    ->label('Termini')
                    ->dateTime('d/m H:i')
                    ->color(fn (IncidentStudent $record) => $record->estat->isActive() && $record->launch?->data_limit?->isPast() ? 'danger' : null)
                    ->placeholder('—')
                    ->toggleable(),
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
            ]);
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
