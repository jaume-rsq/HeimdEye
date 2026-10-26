<?php

namespace App\Filament\Resources\Actions\Pages;

use App\Filament\Resources\Actions\ActionResource;
use App\Models\ActionVersion;
use App\Models\Check;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/** Versions d'una acció, de la més nova a la més antiga. Les versions no es modifiquen. */
class ManageActionVersions extends ManageRelatedRecords
{
    protected static string $resource = ActionResource::class;

    protected static string $relationship = 'versions';

    protected static ?string $breadcrumb = 'Versions';

    public function getTitle(): string|Htmlable
    {
        return "Versions de {$this->getOwnerRecord()->nom}";
    }

    public function getSubheading(): ?string
    {
        return $this->getOwnerRecord()->descripcio;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editar')
                ->label('Editar darrera versió')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->url(ActionResource::getUrl('edit', ['record' => $this->getOwnerRecord()])),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('author'))
            ->defaultSort('versio', 'desc')
            ->recordAction('codi')
            ->columns([
                TextColumn::make('versio')
                    ->label('Versió')
                    ->prefix('v')
                    ->weight('bold'),
                TextColumn::make('comentari')
                    ->label('Comentari')
                    ->limit(60)
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('codi')
                    ->label('Codi')
                    ->formatStateUsing(fn (string $state) => strtok($state, "\n"))
                    ->limit(60)
                    ->fontFamily('mono'),
                TextColumn::make('parametres')
                    ->label('Paràmetres')
                    ->state(fn (ActionVersion $record) => collect($record->parametres)->pluck('name')->implode(', '))
                    ->placeholder('cap'),
                TextColumn::make('temps_max_seg')
                    ->label('Temps màx.')
                    ->suffix(' s'),
                TextColumn::make('author.nom')
                    ->label('Creat per')
                    ->placeholder('seeder'),
                TextColumn::make('created_at')
                    ->label('Data de creació')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->recordActions([
                Action::make('codi')
                    ->label('Veure')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->modalHeading(fn (ActionVersion $record) => "{$this->getOwnerRecord()->nom} · v{$record->versio}")
                    ->modalContent(fn (ActionVersion $record) => view('filament.action-version', [
                        'version' => $record,
                        'checks' => Check::whereKey($record->checks_verificacio ?? [])->pluck('nom'),
                        'previous' => $record->action->versions()->where('versio', '<', $record->versio)->orderByDesc('versio')->first(),
                    ]))
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tancar'),
            ]);
    }
}
