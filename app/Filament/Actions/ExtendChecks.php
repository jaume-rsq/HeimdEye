<?php

namespace App\Filament\Actions;

use App\Models\Check;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Collection;

/**
 * «Prorrogar»: posa una data nova d'actiu fins a i/o de data límit a uns quants checks (els d'un
 * bloc al seguiment, o els marcats a la llista). Un camp buit no es toca. Val per a tots els
 * alumnes. Allargar la data límit dona el bonus de puntualitat als que l'han assolit abans de la
 * data nova. Els checks amb «Actiu» forçat a Sí o No no fan servir actiu_fins.
 */
class ExtendChecks
{
    /** @return list<Component|Field> */
    public static function schema(): array
    {
        return [
            DatePicker::make('actiu_fins')
                ->label('Actiu fins a')
                ->helperText('Buit: no es canvia. No afecta els checks amb «Actiu» forçat a Sí o No.'),
            DateTimePicker::make('data_limit')
                ->label('Data límit')
                ->seconds(false)
                ->helperText('Buit: no es canvia. Qui l\'assoleixi abans d\'aquesta data té el bonus de puntualitat.'),
        ];
    }

    /**
     * Aplica les dates del formulari als checks que l'usuari pot editar i ho notifica.
     *
     * @param  Collection<int, Check>  $checks
     * @param  array{actiu_fins?: ?string, data_limit?: ?string}  $data
     * @return int checks modificats
     */
    public static function apply(Collection $checks, array $data, User $user): int
    {
        $dates = array_filter([
            'actiu_fins' => $data['actiu_fins'] ?? null,
            'data_limit' => $data['data_limit'] ?? null,
        ], 'filled');
        if ($dates === []) {
            Notification::make()->warning()->title('No has posat cap data')->send();

            return 0;
        }

        [$editable, $other] = $checks->partition(fn (Check $check) => $user->canEditCheck($check));
        $editable->each(fn (Check $check) => $check->update($dates));

        Notification::make()
            ->{$other->isEmpty() ? 'success' : 'warning'}()
            ->title($editable->count() === 1 ? 'S\'ha prorrogat 1 check' : "S'han prorrogat {$editable->count()} checks")
            ->body($other->isEmpty() ? null : 'No pots editar: '.$other->pluck('nom')->implode(', '))
            ->send();

        return $editable->count();
    }
}
