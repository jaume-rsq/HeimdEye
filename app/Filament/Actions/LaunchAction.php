<?php

namespace App\Filament\Actions;

use App\Enums\ActionStatus;
use App\Enums\Executor;
use App\Filament\Forms\RowPicker;
use App\Filament\Resources\Actions\ActionResource;
use App\Models\Action as ActionModel;
use App\Models\HostType;
use App\Models\Student;
use App\Runner\ActionRunner;
use App\Support\ActionSummary;
use App\Support\VariableResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Botons Executar i Simular d'una acció: triar alumnes, equips i paràmetres i posar-la a la cua.
 * Amb un sol alumne, els paràmetres es mostren calculats per a ell i es poden modificar.
 * El formulari (schema, defaults, givenParams) també el fa servir l'editor per provar esborranys.
 */
class LaunchAction
{
    /**
     * @param  bool  $outdated  botó «Executar amb la vN»: només surt si hi ha alumnes que han
     *                          rebut bé una versió anterior, i ja els porta marcats
     */
    public static function make(bool $simulate = false, bool $outdated = false): Action
    {
        return Action::make($simulate ? 'simular' : ($outdated ? 'actualitzar' : 'executar'))
            ->label(fn (ActionModel $record) => match (true) {
                $simulate => 'Simular',
                $outdated => 'Executar amb la v'.$record->currentVersion?->versio,
                default => 'Executar',
            })
            ->icon($simulate ? Heroicon::OutlinedBeaker : ($outdated ? Heroicon::OutlinedArrowPath : Heroicon::OutlinedPlay))
            ->color($simulate ? 'info' : ($outdated ? 'success' : 'warning'))
            ->visible(fn (ActionModel $record) => match (true) {
                $simulate => $record->executor === Executor::Ansible,
                $outdated => ActionSummary::outdatedStudentIds($record) !== [],
                default => true,
            })
            ->disabled(fn (ActionModel $record) => $record->currentVersion === null)
            ->modalHeading(fn (ActionModel $record) => ($simulate ? 'Simular ' : 'Executar ').$record->nom)
            ->modalDescription($simulate
                ? 'Ansible s\'executa amb --check --diff: no es canvia res als equips.'
                : 'Es canviaran els equips dels alumnes triats.')
            ->modalSubmitActionLabel($simulate ? 'Simular' : 'Executar')
            ->modalWidth('4xl')
            ->schema(fn (ActionModel $record) => self::schema($record, $record->currentVersion?->parametres ?? []))
            ->fillForm(fn (ActionModel $record) => [
                ...self::fill($record->currentVersion?->parametres ?? []),
                ...($outdated ? ['alumnes' => array_map('strval', ActionSummary::outdatedStudentIds($record))] : []),
            ])
            ->action(function (ActionModel $record, array $data, Action $action) use ($simulate) {
                $version = $record->currentVersion;
                $given = self::givenParams($version->parametres ?? [], $data);

                try {
                    $students = Student::whereKey(array_map('intval', $data['alumnes']))->orderBy('num_taula')->get();
                    app(ActionRunner::class)->launch($record, $students, $data['equips'] ?? [], $given, $simulate, auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }
                $ids = $students->modelKeys();

                Notification::make()
                    ->success()
                    ->title(($simulate ? 'Simulació' : 'Execució').' a la cua per a '.count($ids).' alumnes')
                    ->body($simulate
                        ? "Versió {$version->versio}. El resultat apareix aquí quan acaba; no queda a l'historial."
                        : "Versió {$version->versio}. El resultat apareix a l'historial quan acaba.")
                    ->send();

                $action->redirect(ActionResource::getUrl('runs', ['record' => $record, ...($simulate ? ['simulacio' => 1] : [])]));
            });
    }

    /**
     * Camps per triar alumnes, equips i valors dels paràmetres declarats.
     *
     * @param  list<array<string, mixed>>  $declared
     */
    public static function schema(ActionModel $record, array $declared): array
    {
        $students = $record->module->students()->where('es_actiu', true)->orderBy('num_taula')->get();

        return [
            self::studentPicker($students, $record)
                ->required()
                ->live()
                ->hintAction(
                    Action::make('fallats')
                        ->label('Els que han fallat')
                        ->visible($record->exists)
                        ->action(fn (Set $set) => $set('alumnes', self::failedStudentIds($record))),
                )
                ->afterStateUpdated(fn (?array $state, Set $set) => self::fillParams($record, $declared, $state ?? [], $set)),
            RowPicker::make('equips')
                ->label('Tipus d\'equip')
                ->helperText('Cap marcat = tots els que fa servir el codi.')
                ->headers(['Tipus', 'Grup Ansible', 'Abast', 'Nom de host', 'Adreça', 'Credencial'])
                ->rows($record->module->hostTypes->sortBy('posicio')->mapWithKeys(fn (HostType $t) => [$t->nom => [
                    $t->nom, $t->grup_ansible, $t->abast->value, $t->nom_host, $t->adreca, $t->credential?->nom ?? '—',
                ]])->all())
                ->visible($record->module->hostTypes->isNotEmpty()),
            Fieldset::make('Paràmetres')
                ->visible($declared !== [])
                ->schema(array_map(fn (array $p) => TextInput::make("parametres.{$p['name']}")
                    ->label($p['label'] ?? $p['name'])
                    ->helperText(filled($p['default'] ?? null) ? "Per defecte: {$p['default']}" : 'Sense valor per defecte')
                    ->required(blank($p['default'] ?? null)), $declared)),
        ];
    }

    /** @param  list<array<string, mixed>>  $declared */
    public static function fill(array $declared): array
    {
        return ['alumnes' => [], 'equips' => [], 'parametres' => self::defaults($declared)];
    }

    /**
     * Només els paràmetres que difereixen del valor per defecte.
     *
     * @param  list<array<string, mixed>>  $declared
     * @return array<string, string>
     */
    public static function givenParams(array $declared, array $data): array
    {
        $defaults = self::defaults($declared);

        return array_filter(
            array_map('strval', $data['parametres'] ?? []),
            fn (string $value, string $name) => $value !== ($defaults[$name] ?? ''),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $declared
     * @return array<string, string>
     */
    private static function defaults(array $declared): array
    {
        return collect($declared)
            ->mapWithKeys(fn (array $p) => [$p['name'] => (string) ($p['default'] ?? '')])
            ->all();
    }

    /** Amb un sol alumne, valors calculats amb les seves variables; si no, els valors per defecte. */
    private static function fillParams(ActionModel $record, array $declared, array $studentIds, Set $set): void
    {
        $resolver = app(VariableResolver::class);
        $vars = null;
        if (count($studentIds) === 1 && $student = Student::find(reset($studentIds))) {
            $vars = $resolver->resolve($student, $record->module);
        }

        foreach (self::defaults($declared) as $name => $default) {
            $set("parametres.{$name}", $vars === null ? $default : $resolver->render($default, $vars));
        }
    }

    /**
     * Taula d'alumnes per triar: taula, usuari, nom, X i, si l'acció ja existeix, el darrer estat real.
     *
     * @param  Collection<int, Student>  $students
     */
    public static function studentPicker(Collection $students, ?ActionModel $record = null): RowPicker
    {
        $last = [];
        if ($record?->exists) {
            $latest = DB::table('execucions_accio')
                ->where('accio_id', $record->id)
                ->where('es_simulacio', false)
                ->groupBy('alumne_id')
                ->selectRaw('max(id)');
            $last = $record->runs()->whereIn('id', $latest)->get()->mapWithKeys(fn ($run) => [
                $run->alumne_id => $run->estat->value.' · '.$run->data_inici?->format('d/m H:i'),
            ])->all();
        }

        return RowPicker::make('alumnes')
            ->label('Alumnes')
            ->headers(['Taula', 'Usuari', 'Nom', 'X', ...($record?->exists ? ['Darrera execució'] : [])])
            ->rows($students->mapWithKeys(fn (Student $s) => [$s->id => [
                sprintf('%02d', $s->num_taula),
                $s->usuari,
                $s->nom_complet,
                (string) $s->x,
                ...($record?->exists ? [$last[$s->id] ?? 'mai'] : []),
            ]])->all());
    }

    /** Alumnes la darrera execució real dels quals ha fallat. */
    private static function failedStudentIds(ActionModel $record): array
    {
        $latest = DB::table('execucions_accio')
            ->where('accio_id', $record->id)
            ->where('es_simulacio', false)
            ->groupBy('alumne_id')
            ->selectRaw('max(id)');

        return $record->runs()
            ->whereIn('id', $latest)
            ->whereIn('estat', ActionStatus::failureValues())
            ->pluck('alumne_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }
}
