<?php

namespace App\Filament\Actions;

use App\Enums\CheckStatus;
use App\Filament\Forms\RowPicker;
use App\Filament\Resources\Checks\CheckResource;
use App\Models\Check;
use App\Models\Student;
use App\Runner\CheckRunner;
use App\Support\CheckSummary;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Botó «Verificar ara» d'un check: triar alumnes i posar-lo a la cua amb la versió vigent, un
 * treball per alumne, com Executar a les accions. El resultat compta com el de la nit.
 * Un check no té simulació (només llegeix) ni paràmetres per execució (sempre els per defecte,
 * calculats per a cada alumne), perquè tots els alumnes es comprovin igual.
 */
class LaunchCheck
{
    /**
     * @param  bool  $outdated  botó «Verificar amb la vN»: només surt si hi ha alumnes la darrera
     *                          execució dels quals és d'una versió anterior, i ja els porta marcats
     */
    public static function make(bool $outdated = false): Action
    {
        return Action::make($outdated ? 'actualitzar' : 'verificar')
            ->label(fn (Check $record) => $outdated ? 'Verificar amb la v'.$record->currentVersion?->versio : 'Verificar ara')
            ->icon($outdated ? Heroicon::OutlinedArrowPath : Heroicon::OutlinedPlay)
            ->color($outdated ? 'success' : 'warning')
            ->visible(fn (Check $record) => ! $outdated || CheckSummary::outdatedStudentIds($record) !== [])
            ->disabled(fn (Check $record) => $record->currentVersion === null)
            ->modalHeading(fn (Check $record) => "Verificar {$record->nom}")
            ->modalDescription('S\'executa la versió vigent per als alumnes triats. El resultat queda a l\'historial i compta per als alumnes, com el de la nit.')
            ->modalSubmitActionLabel('Verificar')
            ->modalWidth('4xl')
            ->schema(fn (Check $record) => [
                self::studentPicker(self::students($record), $record)
                    ->required()
                    ->hintAction(
                        Action::make('noAssolits')
                            ->label('Els que no l\'han assolit')
                            ->action(fn (Set $set) => $set('alumnes', self::notPassedStudentIds($record))),
                    ),
            ])
            ->fillForm(fn (Check $record) => ['alumnes' => array_map('strval', $outdated
                ? CheckSummary::outdatedStudentIds($record)
                : self::students($record)->modelKeys())])
            ->action(function (Check $record, array $data, Action $action) {
                $version = $record->currentVersion;
                $students = Student::whereKey(array_map('intval', $data['alumnes']))->orderBy('num_taula')->get();
                try {
                    app(CheckRunner::class)->launch($record, $students, auth()->user());
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }

                Notification::make()
                    ->success()
                    ->title('Verificació a la cua per a '.$students->count().' alumnes')
                    ->body("Versió {$version->versio}. El resultat apareix a l'historial quan acaba.")
                    ->send();

                $action->redirect(CheckResource::getUrl('runs', ['record' => $record]));
            });
    }

    /** @return Collection<int, Student> alumnes actius del mòdul del check */
    public static function students(Check $check): Collection
    {
        return $check->module->students()->where('es_actiu', true)->orderBy('num_taula')->get();
    }

    /**
     * Taula d'alumnes per triar: taula, usuari, nom, X i, si el check ja existeix, la darrera execució.
     *
     * @param  Collection<int, Student>  $students
     */
    public static function studentPicker(Collection $students, ?Check $check = null): RowPicker
    {
        $last = [];
        if ($check?->exists) {
            $last = collect(CheckSummary::students($check))->mapWithKeys(fn (array $s) => [
                $s['id'] => $s['estat'] === null ? 'mai' : "{$s['estat']->value} · v{$s['versio']} · {$s['data']}",
            ])->all();
        }

        return RowPicker::make('alumnes')
            ->label('Alumnes')
            ->headers(['Taula', 'Usuari', 'Nom', 'X', ...($check?->exists ? ['Darrera execució'] : [])])
            ->rows($students->mapWithKeys(fn (Student $s) => [$s->id => [
                sprintf('%02d', $s->num_taula),
                $s->usuari,
                $s->nom_complet,
                (string) $s->x,
                ...($check?->exists ? [$last[$s->id] ?? 'mai'] : []),
            ]])->all());
    }

    /** @return list<string> alumnes actius la darrera execució dels quals no és pass (o no en tenen) */
    private static function notPassedStudentIds(Check $check): array
    {
        return collect(CheckSummary::students($check))
            ->filter(fn (array $s) => $s['estat'] !== CheckStatus::Pass)
            ->map(fn (array $s) => (string) $s['id'])
            ->values()
            ->all();
    }
}
