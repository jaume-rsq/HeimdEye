<?php

namespace App\Filament\Resources\Actions\Pages;

use App\Enums\Executor;
use App\Filament\Actions\LaunchAction;
use App\Filament\Resources\ActionRuns\Tables\ActionRunsTable;
use App\Filament\Resources\Actions\ActionResource;
use App\Models\Action;
use App\Support\ActionSummary;
use Filament\Actions\Action as PageAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * Historial d'execucions reals d'una acció, amb l'estat de cada alumne a dalt i, si n'hi ha
 * que l'han rebuda amb una versió anterior, el botó per executar-hi la vigent.
 * Amb ?simulacio=1 mostra només el resultat de la darrera simulació (les simulacions
 * no formen part de l'historial).
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
            LaunchAction::make(outdated: true)->record($action),
            LaunchAction::make(simulate: true)->record($action),
            LaunchAction::make()->record($action),
        ];
    }

    /** Com la de ManageRelatedRecords, amb la graella d'estat per alumne a sobre de la taula (no a la simulació). */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.action-students')
                ->viewData(['students' => $this->simulacio ? [] : ActionSummary::students($this->getOwnerRecord())])
                ->visible(! $this->simulacio),
            Group::make([
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_AFTER),
            ]),
        ]);
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
