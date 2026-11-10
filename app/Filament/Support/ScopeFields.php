<?php

namespace App\Filament\Support;

use App\Enums\Executor;
use App\Models\AcademicYear;
use App\Models\Module;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;

/**
 * Camps encadenats curs → mòdul dels formularis de checks i accions. El curs només serveix per
 * triar el mòdul (no es desa). En editar, canviar-los mou el check o l'acció a un altre lloc.
 */
class ScopeFields
{
    /** @param  list<string>  $resets  camps que es buiden en canviar el curs (a més del mòdul) */
    public static function course(array $resets = []): Select
    {
        return Select::make('curs_id')
            ->label('Curs')
            ->options(fn () => AcademicYear::orderBy('es_biblioteca')->orderByDesc('data_inici')->pluck('nom', 'id'))
            ->default(fn () => AcademicYear::where('es_actual', true)->value('id'))
            ->live()
            ->dehydrated(false)
            ->afterStateUpdated(function (Set $set) use ($resets) {
                foreach (['modul_id', ...$resets] as $field) {
                    $set($field, null);
                }
            });
    }

    /**
     * Mòduls del curs triat. En carregar el formulari, el curs passa a ser el del mòdul.
     *
     * @param  \Closure(Set): void  $onChange  què es buida en canviar de mòdul
     * @param  (\Closure(Model): ?string)|null  $moveWarning  avís (en editar) si canviar de mòdul té conseqüències
     */
    public static function module(\Closure $onChange, ?\Closure $moveWarning = null): Select
    {
        return Select::make('modul_id')
            ->label('Mòdul')
            ->options(fn (Get $get) => Module::query()
                ->when($get('curs_id'), fn ($q, $year) => $q->where('curs_id', $year))
                ->orderBy('codi')->orderBy('nom')->get()
                ->mapWithKeys(fn (Module $m) => [$m->id => $m->label()]))
            ->searchable()
            ->required()
            ->live()
            ->afterStateHydrated(function ($state, Set $set) {
                if (filled($state)) {
                    $set('curs_id', Module::whereKey($state)->value('curs_id'));
                }
            })
            ->afterStateUpdated(function (Set $set, string $operation, ?Model $record) use ($onChange, $moveWarning) {
                $onChange($set);
                if ($operation === 'edit' && $record && $moveWarning && filled($warning = $moveWarning($record))) {
                    Notification::make()->warning()->title('Compte en moure-ho')->body($warning)->persistent()->send();
                }
            });
    }

    /** Executor: es pot canviar en editar, però llavors cal revisar el codi i se'n crea una versió nova. */
    public static function executor(Executor $default): Select
    {
        return Select::make('executor')
            ->options(collect(Executor::cases())->mapWithKeys(fn (Executor $e) => [$e->value => $e->value]))
            ->default($default->value)
            ->required()
            ->live()
            ->helperText(fn (string $operation) => $operation === 'edit'
                ? 'Si el canvies, revisa el codi i prova\'l: en desar es crea una versió nova.'
                : null)
            ->afterStateUpdated(function (string $operation) {
                if ($operation === 'edit') {
                    Notification::make()
                        ->warning()
                        ->title('Revisa el codi')
                        ->body('El mateix codi no sempre funciona amb un altre executor: capçalera «# hosts:», sintaxi (bash o Ansible) i secrets (amb agent no se\'n poden fer servir). Prova\'l abans de desar; en desar es crea una versió nova.')
                        ->persistent()
                        ->send();
                }
            });
    }
}
