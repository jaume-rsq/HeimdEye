<?php

namespace App\Filament\Actions;

use App\Enums\Executor;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Models\Incident;
use App\Models\IncidentOption;
use App\Models\Student;
use App\Runner\Incidents\IncidentLauncher;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Botó «Llançar» d'una incidència, en dos passos: triar alumnes i com es reparteixen les
 * opcions, i revisar (i canviar si cal) qui rep què abans d'injectar-ho.
 */
class LaunchIncident
{
    public static function make(): Action
    {
        return Action::make('llancar')
            ->label('Llançar')
            ->icon(Heroicon::OutlinedBolt)
            ->color('danger')
            ->disabled(fn (Incident $record) => ! $record->options()->exists())
            ->tooltip(fn (Incident $record) => $record->options()->exists() ? null : 'Afegeix-hi alguna opció primer')
            ->modalHeading(fn (Incident $record) => 'Llançar '.$record->nom)
            ->modalDescription('S\'executa ara mateix: es trencaran els equips dels alumnes triats.')
            ->modalWidth('4xl')
            ->modalSubmitActionLabel('Injectar')
            ->steps(fn (Incident $record) => self::steps($record))
            ->action(function (Incident $record, array $data, Action $action) {
                $plan = collect($data['pla'] ?? [])
                    ->mapWithKeys(fn (array $row) => [(int) $row['alumne_id'] => array_map('intval', $row['opcions'] ?? [])])
                    ->filter()
                    ->all();
                $proposal = json_decode($data['proposta'] ?? '[]', true) ?: [];
                $mode = self::sameplan($plan, $proposal) ? $data['mode'] : 'manual';
                $count = $data['mode'] === 'igual' ? 1 : (int) ($data['quantitat'] ?? 1);
                $deadline = filled($data['data_limit'] ?? null) ? Carbon::parse($data['data_limit']) : null;

                try {
                    $launch = app(IncidentLauncher::class)->launch($record, $plan, $mode, $count, $deadline, auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }

                Notification::make()
                    ->success()
                    ->title('Incidència injectada a '.count($plan).' alumnes')
                    ->body('Quan acabi cada acció, es comprova amb el seu check. El seguiment s\'actualitza sol.')
                    ->send();
                self::warnAgent($record, $plan);

                $action->redirect(IncidentResource::getUrl('seguiment', ['record' => $record, 'llancament' => $launch->id]));
            });
    }

    /** @return list<Step> */
    private static function steps(Incident $record): array
    {
        $options = $record->options()->get();
        $labels = $options->mapWithKeys(fn (IncidentOption $o) => [$o->id => $o->nom]);
        $students = $record->module->students()->where('es_actiu', true)->orderBy('num_taula')->get();

        return [
            Step::make('Alumnes')
                ->schema([
                    LaunchAction::studentPicker($students)->required(),
                    Radio::make('mode')
                        ->label('Com es reparteixen les opcions')
                        ->options(IncidentLauncher::MODES)
                        ->default('aleatori')
                        ->inline()
                        ->live()
                        ->helperText('A l\'atzar, cada alumne en rep de diferents (i no repeteix les que ja ha tingut). Al pas següent es pot canviar qualsevol fila.'),
                    TextInput::make('quantitat')
                        ->label('Avaries per alumne')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(max(1, $options->count()))
                        ->default(1)
                        ->visible(fn (Get $get) => $get('mode') !== 'igual'),
                    Select::make('opcio')
                        ->label('Opció per a tothom')
                        ->options($labels)
                        ->required(fn (Get $get) => $get('mode') === 'igual')
                        ->visible(fn (Get $get) => $get('mode') === 'igual'),
                    DateTimePicker::make('data_limit')
                        ->label('Termini')
                        ->seconds(false)
                        ->helperText('Opcional. Només informatiu: en acabar, no es repara res sol.'),
                ])
                ->afterValidation(function (Get $get, Set $set) use ($record) {
                    $students = Student::whereKey(array_map('intval', $get('alumnes') ?? []))->orderBy('num_taula')->get();
                    $result = app(IncidentLauncher::class)->propose($record, $students, $get('mode'), (int) ($get('quantitat') ?: 1), $get('opcio') ? (int) $get('opcio') : null);
                    $set('proposta', json_encode($result['plan']));
                    $set('pla', $students->filter(fn (Student $s) => isset($result['plan'][$s->id]))->map(fn (Student $s) => [
                        'alumne_id' => $s->id,
                        'alumne' => sprintf('%02d · %s · %s', $s->num_taula, $s->usuari, $s->nom_complet),
                        'opcions' => array_map('strval', $result['plan'][$s->id]),
                    ])->values()->all());
                    $set('saltats', $students->whereIn('id', $result['skipped'])->map(fn (Student $s) => $s->usuari)->implode(', '));
                }),
            Step::make('Qui rep què')
                ->schema([
                    Hidden::make('proposta'),
                    TextInput::make('saltats')
                        ->label('Ja en tenen una d\'activa (no la reben)')
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (Get $get) => filled($get('saltats'))),
                    Repeater::make('pla')
                        ->label('Previsualització')
                        ->helperText('Pots canviar les opcions de qualsevol alumne o treure\'l de la llista.')
                        ->addable(false)
                        ->reorderable(false)
                        ->columns(2)
                        ->schema([
                            Hidden::make('alumne_id'),
                            TextInput::make('alumne')->hiddenLabel()->disabled()->dehydrated(false),
                            Select::make('opcions')->hiddenLabel()->multiple()->options($labels)->required(),
                        ]),
                ]),
        ];
    }

    /**
     * @param  array<int, list<int>>  $plan
     * @param  array<int|string, list<int>>  $proposal
     */
    private static function sameplan(array $plan, array $proposal): bool
    {
        $norm = fn (array $p) => collect($p)->mapWithKeys(fn ($ids, $id) => [(int) $id => collect($ids)->map('intval')->sort()->values()->all()])->sortKeys()->all();

        return $norm($plan) === $norm($proposal);
    }

    /**
     * Amb l'agent, avisa dels alumnes sense instància activa: l'avaria els queda pendent i caduca.
     *
     * @param  array<int, list<int>>  $plan
     */
    private static function warnAgent(Incident $record, array $plan): void
    {
        $options = $record->options()->with('action.currentVersion')->get()->keyBy('id');
        foreach ($options as $option) {
            $action = $option->action;
            if ($action?->executor !== Executor::Agent || $action->currentVersion === null) {
                continue;
            }
            $ids = array_keys(array_filter($plan, fn (array $ids) => in_array($option->id, $ids, true)));
            if ($ids !== []) {
                LaunchAction::warnMissingInstances(Student::whereKey($ids)->get(), $action->currentVersion->codi, $action->agentExpirySeconds());
            }
        }
    }
}
