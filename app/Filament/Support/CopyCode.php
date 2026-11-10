<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\CodeEditor;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;

/** Botó «Copiar» damunt d'un camp de codi: copia al porta-retalls el que hi ha escrit ara (desat o no). */
final class CopyCode
{
    public static function hint(): \Closure
    {
        return fn (CodeEditor $component) => Action::make('copiarCodi')
            ->label('Copiar')
            ->icon(Heroicon::OutlinedClipboardDocument)
            ->link()
            ->alpineClickHandler(self::js('$wire.get('.Js::from($component->getStatePath()).')'));
    }

    /** JavaScript que copia el text que dona l'expressió $text i ho avisa. */
    public static function js(string $text): string
    {
        return 'navigator.clipboard.writeText(String('.$text.' ?? \'\'))'
            .'.then(() => new FilamentNotification().title(\'Codi copiat\').success().send())'
            .'.catch(() => new FilamentNotification().title(\'No s\\\'ha pogut copiar\').danger().send())';
    }
}
