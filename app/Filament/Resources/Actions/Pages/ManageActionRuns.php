<?php

namespace App\Filament\Resources\Actions\Pages;

use App\Filament\Actions\LaunchAction;
use App\Filament\Resources\ActionRuns\Tables\ActionRunsTable;
use App\Filament\Resources\Actions\ActionResource;
use App\Models\Action;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/** Historial d'execucions d'una acció. */
class ManageActionRuns extends ManageRelatedRecords
{
    protected static string $resource = ActionResource::class;

    protected static string $relationship = 'runs';

    protected static ?string $breadcrumb = 'Historial';

    public function getTitle(): string|Htmlable
    {
        return "Historial de {$this->getOwnerRecord()->nom}";
    }

    protected function getHeaderActions(): array
    {
        return [
            LaunchAction::make(simulate: true)->record($this->getOwnerRecord()),
            LaunchAction::make()->record($this->getOwnerRecord()),
        ];
    }

    public function table(Table $table): Table
    {
        /** @var Action $action */
        $action = $this->getOwnerRecord();

        return ActionRunsTable::configure($table, $action);
    }
}
