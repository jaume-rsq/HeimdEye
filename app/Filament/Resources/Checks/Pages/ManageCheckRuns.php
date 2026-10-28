<?php

namespace App\Filament\Resources\Checks\Pages;

use App\Filament\Actions\LaunchCheck;
use App\Filament\Resources\CheckRuns\Tables\CheckRunsTable;
use App\Filament\Resources\Checks\CheckResource;
use App\Models\Check;
use App\Support\CheckSummary;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Historial d'execucions d'un check (nocturnes i manuals), amb l'estat de cada alumne a dalt i,
 * si n'hi ha que s'han comprovat amb una versió anterior, el botó per verificar-los amb la vigent.
 *
 * @property Check $record
 */
class ManageCheckRuns extends ManageRelatedRecords
{
    protected static string $resource = CheckResource::class;

    protected static string $relationship = 'runs';

    protected static ?string $breadcrumb = 'Historial';

    public function getTitle(): string|Htmlable
    {
        return 'Historial de '.$this->getOwnerRecord()->nom;
    }

    protected function getHeaderActions(): array
    {
        $check = $this->getOwnerRecord();

        return [
            LaunchCheck::make(outdated: true)->record($check),
            LaunchCheck::make()->record($check),
        ];
    }

    /** Com la de ManageRelatedRecords, amb la graella d'estat per alumne a sobre de la taula. */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.students-status')->viewData([
                'students' => CheckSummary::students($this->getOwnerRecord()),
                'corrections' => $this->getOwnerRecord()->states()
                    ->whereNotNull('data_correccio')
                    ->whereHas('student', fn ($q) => $q->where('es_actiu', true))
                    ->with(['student', 'teacherUpdatedBy'])
                    ->orderByDesc('data_correccio')
                    ->get(),
                'legend' => 'Verd: assolit amb la versió vigent · verd clar amb número: assolit amb una versió anterior · vermell: no assolit (fail) · taronja: no s\'ha pogut comprovar (error, unavailable, timeout) · gris: mai executat. El número és la versió, si no és la vigent. ✎: corregit pel professor (mana sobre l\'execució).',
            ]),
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
        return CheckRunsTable::configure($table, $this->getOwnerRecord());
    }
}
