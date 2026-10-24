<?php

namespace App\Filament\Resources\Actions\Pages;

use App\Enums\Executor;
use App\Filament\Actions\LaunchAction;
use App\Filament\Resources\ActionRuns\Tables\ActionRunsTable;
use App\Filament\Resources\Actions\ActionResource;
use App\Models\Action;
use Filament\Actions\Action as PageAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * Historial d'execucions reals d'una acció. Amb ?simulacio=1 mostra només el resultat
 * de la darrera simulació (les simulacions no formen part de l'historial).
 *
 * @property Action $record
 */
class ManageActionRuns extends ManageRelatedRecords
{
    protected static string $resource = ActionResource::class;

    protected static string $relationship = 'runs';

    #[Url]
    public bool $simulacio = false;

    public function getTitle(): string|Htmlable
    {
        return ($this->simulacio ? 'Darrera simulació de ' : 'Historial de ').$this->getOwnerRecord()->nom;
    }

    public function getBreadcrumb(): string
    {
        return $this->simulacio ? 'Darrera simulació' : 'Historial';
    }

    protected function getHeaderActions(): array
    {
        $action = $this->getOwnerRecord();

        return [
            PageAction::make('historial')
                ->label('Historial')
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->url(ActionResource::getUrl('runs', ['record' => $action]))
                ->visible($this->simulacio),
            PageAction::make('darreraSimulacio')
                ->label('Darrera simulació')
                ->icon(Heroicon::OutlinedBeaker)
                ->color('gray')
                ->url(ActionResource::getUrl('runs', ['record' => $action, 'simulacio' => 1]))
                ->visible(! $this->simulacio && $action->executor === Executor::Ansible && $this->latestSimulationBatch() !== null),
            LaunchAction::make(simulate: true)->record($action),
            LaunchAction::make()->record($action),
        ];
    }

    public function table(Table $table): Table
    {
        /** @var Action $action */
        $action = $this->getOwnerRecord();

        // Abans que el job creï el lot, -1 no troba res i la taula es refresca fins que n'hi ha.
        return ActionRunsTable::configure($table, $action, $this->simulacio ? ($this->latestSimulationBatch() ?? -1) : null);
    }

    private function latestSimulationBatch(): ?int
    {
        return $this->getOwnerRecord()->runs()->where('es_simulacio', true)->max('execucio_id');
    }
}
