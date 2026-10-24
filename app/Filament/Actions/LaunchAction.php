<?php

namespace App\Filament\Actions;

use App\Enums\ActionStatus;
use App\Enums\Executor;
use App\Filament\Resources\Actions\ActionResource;
use App\Jobs\RunAction;
use App\Models\Action as ActionModel;
use App\Models\Student;
use App\Runner\Params;
use App\Support\VariableResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
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
 */
class LaunchAction
{
    public static function make(bool $simulate = false): Action
    {
        return Action::make($simulate ? 'simular' : 'executar')
            ->label($simulate ? 'Simular' : 'Executar')
            ->icon($simulate ? Heroicon::OutlinedBeaker : Heroicon::OutlinedPlay)
            ->color($simulate ? 'info' : 'warning')
            ->visible(fn (ActionModel $record) => ! $simulate || $record->executor === Executor::Ansible)
            ->disabled(fn (ActionModel $record) => $record->currentVersion === null)
            ->modalHeading(fn (ActionModel $record) => ($simulate ? 'Simular ' : 'Executar ').$record->nom)
            ->modalDescription($simulate
                ? 'Ansible s\'executa amb --check --diff: no es canvia res als equips.'
                : 'Es canviaran els equips dels alumnes triats.')
            ->modalSubmitActionLabel($simulate ? 'Simular' : 'Executar')
            ->modalWidth('4xl')
            ->schema(fn (ActionModel $record) => self::schema($record))
            ->fillForm(fn (ActionModel $record) => [
                'alumnes' => [],
                'equips' => [],
                'parametres' => self::defaults($record),
            ])
            ->action(function (ActionModel $record, array $data, Action $action) use ($simulate) {
                $version = $record->currentVersion;
                $defaults = self::defaults($record);
                $given = array_filter(
                    array_map('strval', $data['parametres'] ?? []),
                    fn (string $value, string $name) => $value !== ($defaults[$name] ?? ''),
                    ARRAY_FILTER_USE_BOTH,
                );

                try {
                    Params::values($version->parametres, $given);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }

                $ids = array_map('intval', $data['alumnes']);
                RunAction::dispatch($record, $ids, $data['equips'] ?? [], $given, $simulate, auth()->user());

                Notification::make()
                    ->success()
                    ->title(($simulate ? 'Simulació' : 'Execució').' a la cua per a '.count($ids).' alumnes')
                    ->body("Versió {$version->versio}. El resultat apareix a l'historial quan acaba.")
                    ->send();

                $action->redirect(ActionResource::getUrl('runs', ['record' => $record]));
            });
    }

    private static function schema(ActionModel $record): array
    {
        $students = self::students($record);
        $declared = $record->currentVersion?->parametres ?? [];

        return [
            CheckboxList::make('alumnes')
                ->label('Alumnes')
                ->options($students->mapWithKeys(fn (Student $s) => [$s->id => sprintf('%02d · %s', $s->num_taula, $s->nom_complet)]))
                ->searchable()
                ->bulkToggleable()
                ->columns(3)
                ->required()
                ->live()
                ->hintAction(
                    Action::make('fallats')
                        ->label('Els que han fallat')
                        ->action(fn (Set $set) => $set('alumnes', self::failedStudentIds($record))),
                )
                ->afterStateUpdated(fn (?array $state, Set $set) => self::fillParams($record, $state ?? [], $set)),
            CheckboxList::make('equips')
                ->label('Tipus d\'equip')
                ->helperText('Cap marcat = tots els de l\'alumne.')
                ->options($record->module->hostTypes->pluck('nom', 'nom'))
                ->columns(4)
                ->visible($record->module->hostTypes->isNotEmpty()),
            Fieldset::make('Paràmetres')
                ->visible($declared !== [])
                ->schema(array_map(fn (array $p) => TextInput::make("parametres.{$p['name']}")
                    ->label($p['label'] ?? $p['name'])
                    ->helperText(filled($p['default'] ?? null) ? "Per defecte: {$p['default']}" : 'Sense valor per defecte')
                    ->required(blank($p['default'] ?? null)), $declared)),
        ];
    }

    /** Alumnes actius del mòdul de l'acció, per taula. */
    private static function students(ActionModel $record): Collection
    {
        return $record->module->students()->where('es_actiu', true)->orderBy('num_taula')->get();
    }

    /** @return array<string, string> */
    private static function defaults(ActionModel $record): array
    {
        return collect($record->currentVersion?->parametres ?? [])
            ->mapWithKeys(fn (array $p) => [$p['name'] => (string) ($p['default'] ?? '')])
            ->all();
    }

    /** Amb un sol alumne, valors calculats amb les seves variables; si no, els valors per defecte. */
    private static function fillParams(ActionModel $record, array $studentIds, Set $set): void
    {
        $defaults = self::defaults($record);
        $vars = null;
        if (count($studentIds) === 1 && $student = Student::find(reset($studentIds))) {
            $vars = app(VariableResolver::class)->resolve($student, $record->module);
        }

        foreach ($defaults as $name => $default) {
            $set("parametres.{$name}", $vars === null ? $default : app(VariableResolver::class)->render($default, $vars));
        }
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
