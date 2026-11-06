<?php

namespace App\Filament\Actions;

use App\Enums\Executor;
use App\Filament\Resources\Actions\ActionResource;
use App\Filament\Resources\Checks\CheckResource;
use App\Models\Action as ActionModel;
use App\Models\Block;
use App\Models\Check;
use App\Models\HostType;
use App\Models\Module;
use App\Models\Task;
use App\Runner\CodeValidator;
use App\Runner\HostsRewriter;
use App\Support\Library;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * «Copiar a…» des de la biblioteca de checks i accions: crea un check (a una tasca) o una acció
 * (a un mòdul) reals amb el codi copiat i els equips triats en lloc dels del codi. És una còpia,
 * no un enllaç: la versió 1 del nou, que queda inactiu fins que s'hi posen dates.
 */
final class CopyFromLibrary
{
    public static function check(): Action
    {
        return Action::make('copiarA')
            ->label('Copiar a…')
            ->icon(Heroicon::OutlinedArrowRightCircle)
            ->color('gray')
            ->visible(fn (Check $record) => (bool) $record->module?->isLibrary())
            ->disabled(fn (Check $record) => $record->currentVersion === null)
            ->modalHeading(fn (Check $record) => "Copiar «{$record->nom}» a una tasca")
            ->modalDescription('Es crea un check nou a la tasca triada, amb la versió vigent del codi i els equips triats. Queda inactiu: posa-hi les dates des de l\'editor.')
            ->modalSubmitActionLabel('Copiar')
            ->schema(fn (Check $record) => [
                ...self::targetFields($record->executor),
                Select::make('tasca_id')
                    ->label('Tasca')
                    ->options(fn (Get $get) => Block::where('modul_id', $get('modul'))->with('tasks')->orderBy('numero')->get()
                        ->mapWithKeys(fn (Block $b) => ["Bloc {$b->numero}: {$b->titol}" => $b->tasks->pluck('titol', 'id')->all()])
                        ->all())
                    ->required(),
                TextInput::make('nom')->label('Nom')->required()->maxLength(255),
            ])
            ->fillForm(fn (Check $record) => ['nom' => $record->nom, 'equips' => []])
            ->action(function (Check $record, array $data, Action $action) {
                $task = Task::with('block.module')->findOrFail($data['tasca_id']);
                $version = $record->currentVersion;
                $code = self::code($task->block->module, $record->executor, $version->codi, $data, $action);

                $copy = DB::transaction(function () use ($record, $task, $version, $code, $data) {
                    $copy = $task->checks()->create([
                        ...$record->only(['descripcio', 'info', 'executor', 'tipus', 'punts', 'bonus_puntualitat_pct']),
                        'nom' => $data['nom'],
                        'es_actiu' => false,
                        'posicio' => ($task->checks()->max('posicio') ?? 0) + 1,
                    ]);
                    $copy->versions()->create([
                        'versio' => 1,
                        'codi' => $code,
                        'parametres' => $version->parametres,
                        'temps_max_seg' => $version->temps_max_seg,
                        'comentari' => "Copiat de la biblioteca: {$record->nom} (v{$version->versio})",
                        'creat_per' => auth()->id(),
                    ]);

                    return $copy;
                });

                Notification::make()->success()->title("Check copiat a «{$task->titol}»")->body('Queda inactiu: posa-hi les dates.')->send();
                $action->redirect(CheckResource::getUrl('edit', ['record' => $copy]));
            });
    }

    public static function action(): Action
    {
        return Action::make('copiarA')
            ->label('Copiar a…')
            ->icon(Heroicon::OutlinedArrowRightCircle)
            ->color('gray')
            ->visible(fn (ActionModel $record) => (bool) $record->module?->isLibrary())
            ->disabled(fn (ActionModel $record) => $record->currentVersion === null)
            ->modalHeading(fn (ActionModel $record) => "Copiar «{$record->nom}» a un mòdul")
            ->modalDescription('Es crea una acció nova al mòdul triat, amb la versió vigent del codi i els equips triats.')
            ->modalSubmitActionLabel('Copiar')
            ->schema(fn (ActionModel $record) => [
                ...self::targetFields($record->executor),
                TextInput::make('nom')->label('Nom')->required()->maxLength(255),
            ])
            ->fillForm(fn (ActionModel $record) => ['nom' => $record->nom, 'equips' => []])
            ->action(function (ActionModel $record, array $data, Action $action) {
                $module = Module::regular()->findOrFail($data['modul']);
                $version = $record->currentVersion;
                $code = self::code($module, $record->executor, $version->codi, $data, $action);

                $copy = DB::transaction(function () use ($record, $module, $version, $code, $data) {
                    $copy = $module->actions()->create([
                        ...$record->only(['descripcio', 'executor', 'caducitat_agent_seg']),
                        'nom' => $data['nom'],
                        'posicio' => ($module->actions()->max('posicio') ?? 0) + 1,
                    ]);
                    // Els checks de verificació són de la biblioteca: no es copien.
                    $copy->versions()->create([
                        'versio' => 1,
                        'codi' => $code,
                        'parametres' => $version->parametres,
                        'temps_max_seg' => $version->temps_max_seg,
                        'comentari' => "Copiada de la biblioteca: {$record->nom} (v{$version->versio})",
                        'creat_per' => auth()->id(),
                    ]);

                    return $copy;
                });

                Notification::make()->success()->title("Acció copiada a «{$module->nom}»")->send();
                $action->redirect(ActionResource::getUrl('edit', ['record' => $copy]));
            });
    }

    /** Mòdul de destí i equips on anirà el codi (grups del mòdul amb ssh i Ansible, rols amb l'agent). */
    private static function targetFields(Executor $executor): array
    {
        return array_values(array_filter([
            Select::make('modul')
                ->label('Mòdul')
                ->options(fn () => Library::targetModules())
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('tasca_id', null)),
            match ($executor) {
                Executor::Ssh, Executor::Ansible => Select::make('equips')
                    ->label('Equips')
                    ->helperText('Buit = els que diu el codi.')
                    ->multiple()
                    ->options(fn (Get $get) => ($module = Module::find($get('modul')))
                        ? $module->visibleHostTypes()->mapWithKeys(fn (HostType $t) => [$t->grup_ansible => "{$t->nom} ({$t->grup_ansible})"])->all()
                        : []),
                Executor::Agent => TagsInput::make('equips')
                    ->label('Rols')
                    ->helperText('Hostname de les instàncies (web, bd...). Buit = els que diu el codi.')
                    ->placeholder('web'),
                default => null,
            },
        ]));
    }

    /** El codi amb els equips triats, validat contra el mòdul de destí (atura l'acció si no és vàlid). */
    private static function code(Module $module, Executor $executor, string $code, array $data, Action $action): string
    {
        $code = HostsRewriter::rewrite($executor, $code, array_values($data['equips'] ?? []));
        try {
            app(CodeValidator::class)->validate($module, $executor, $code);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('No es pot copiar')->body($e->getMessage())->persistent()->send();
            $action->halt();
        }

        return $code;
    }
}
