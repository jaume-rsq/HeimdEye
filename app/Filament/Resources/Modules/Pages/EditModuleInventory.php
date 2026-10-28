<?php

namespace App\Filament\Resources\Modules\Pages;

use App\Filament\Pages\InventoryOverview;
use App\Filament\Resources\Modules\Concerns\PreviewsInventory;
use App\Filament\Resources\Modules\ModuleResource;
use App\Models\Module;
use App\Support\InventoryGrid;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * Equips, variables i secrets d'un mòdul. La previsualització es calcula amb l'esborrany;
 * en desar, si alguna plantilla queda sense calcular per a algun alumne, s'avisa.
 *
 * @property Module $record
 */
class EditModuleInventory extends EditRecord
{
    use PreviewsInventory;

    protected static string $resource = ModuleResource::class;

    public function getTitle(): string
    {
        return 'Equips del mòdul · '.trim("{$this->record->codi} {$this->record->nom}");
    }

    /** Comprova les plantilles desades contra tots els alumnes actius. */
    protected function afterSave(): void
    {
        $grid = app(InventoryGrid::class)->build($this->record->fresh());
        $broken = collect($grid['rows'])->filter(fn (array $row) => $row['problemes'] > 0);
        $globals = collect($grid['globals'])->filter(fn (array $host) => $host['problemes'] !== []);
        if ($broken->isEmpty() && $globals->isEmpty()) {
            return;
        }

        $names = $broken->map(fn (array $row) => sprintf('%02d', $row['student']->num_taula))->implode(', ');
        Notification::make()
            ->warning()
            ->persistent()
            ->title('Hi ha plantilles que no es poden calcular')
            ->body(trim(($broken->isNotEmpty() ? "Alumnes afectats (taula): {$names}. " : '')
                .($globals->isNotEmpty() ? 'També equips globals: '.$globals->map(fn ($h) => $h['tipus']->nom)->implode(', ').'.' : '')))
            ->actions([
                Action::make('veure')
                    ->label('Veure a la vista de conjunt')
                    ->url(InventoryOverview::getUrl(['modul' => $this->record->id, 'problemes' => 1])),
            ])
            ->send();
    }
}
