<?php

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Pages\InventoryOverview;
use App\Filament\Pages\StudentTracking;
use App\Filament\Resources\Students\StudentResource;
use App\Models\Student;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
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
