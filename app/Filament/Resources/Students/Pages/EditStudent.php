<?php

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Pages\InventoryOverview;
use App\Filament\Pages\StudentTracking;
use App\Filament\Resources\Students\StudentResource;
use App\Models\Student;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Fitxa d'un alumne: les dades i les variables pròpies. Què rep i d'on surt és al panell de
 * l'alumne a l'Inventari (botó Inventari).
 *
 * @property Student $record
 */
class EditStudent extends EditRecord
{
    protected static string $resource = StudentResource::class;

    /** Mòdul des d'on s'ha obert la fitxa, per tornar al panell de l'alumne a l'Inventari. */
    #[Url]
    public ?int $modul = null;

    public function getTitle(): string
    {
        return sprintf('%02d · %s', $this->record->num_taula, $this->record->nom_complet);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('seguiment')
                ->label('Seguiment')
                ->icon(Heroicon::OutlinedPresentationChartLine)
                ->color('gray')
                ->url(fn () => StudentTracking::getUrl(['alumne' => $this->record->id])),
            Action::make('generarTokenAgent')
                ->label(fn () => filled($this->record->token_agent) ? 'Regenerar token' : 'Generar token')
                ->icon(Heroicon::OutlinedKey)
                ->color('gray')
                ->tooltip('Token de l\'agent de les instàncies d\'aquest alumne')
                ->requiresConfirmation()
                ->modalDescription('Es generarà un token nou per a l\'agent. Si ja n\'hi havia un, deixarà de funcionar i caldrà reinstal·lar l\'agent amb la comanda nova.')
                ->action(function () {
                    $this->record->regenerateAgentToken();
                    Notification::make()->success()->title('Token generat')->body('Ja pots donar la comanda d\'instal·lació a l\'alumne.')->send();

                    return redirect(EditStudent::getUrl(['record' => $this->record, 'modul' => $this->modul]));
                }),
            Action::make('revocarTokenAgent')
                ->label('Revocar token')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->visible(fn () => filled($this->record->token_agent))
                ->requiresConfirmation()
                ->modalDescription('L\'agent d\'aquest alumne deixarà de poder connectar. Per tornar-hi, genera un token nou.')
                ->action(function () {
                    $this->record->forceFill(['token_agent' => null])->save();
                    Notification::make()->success()->title('Token revocat')->send();

                    return redirect(EditStudent::getUrl(['record' => $this->record, 'modul' => $this->modul]));
                }),
            Action::make('conjunt')
                ->label('Inventari')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->color('gray')
                ->tooltip('Què rep l\'alumne i d\'on surt')
                ->visible(fn () => $this->record->modules->isNotEmpty())
                ->url(fn () => InventoryOverview::getUrl([
                    'modul' => $this->record->modules->contains('id', $this->modul) ? $this->modul : $this->record->modules->first()?->id,
                    'alumne' => $this->record->id,
                ])),
            DeleteAction::make()
                ->modalDescription(fn () => 'S\'esborraran totes les execucions de checks i accions d\'aquest alumne i els seus assoliments ('
                    .$this->record->runs()->count().' execucions de checks). Si només ha deixat el curs, millor marca\'l com a inactiu. No es pot desfer.'),
        ];
    }
}
