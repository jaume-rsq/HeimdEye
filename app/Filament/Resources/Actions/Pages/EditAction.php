<?php

namespace App\Filament\Resources\Actions\Pages;

use App\Filament\Resources\Actions\ActionResource;
use App\Filament\Resources\Actions\Schemas\ActionForm;
use App\Models\Action;
use Filament\Actions\Action as PageAction;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * "Editar la darrera versió": les dades de l'acció es modifiquen; si canvia el codi,
 * els paràmetres, el temps màxim o els checks de verificació, es crea la versió N+1.
 *
 * @property Action $record
 */
class EditAction extends EditRecord
{
    protected static string $resource = ActionResource::class;

    public function getTitle(): string|Htmlable
    {
        $version = $this->record->currentVersion?->versio;

        return "Editar {$this->record->nom}".($version ? " (v{$version})" : '');
    }

    public function getBreadcrumb(): string
    {
        return 'Editar darrera versió';
    }

    protected function getHeaderActions(): array
    {
        return [
            PageAction::make('versions')
                ->label('Versions')
                ->icon(Heroicon::OutlinedRectangleStack)
                ->color('gray')
                ->url(fn () => ActionResource::getUrl('versions', ['record' => $this->record])),
            PageAction::make('historial')
                ->label('Historial')
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->url(fn () => ActionResource::getUrl('runs', ['record' => $this->record])),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $version = $this->record->currentVersion;

        return [...$data, ...($version?->only(ActionForm::VERSION_FIELDS) ?? [])];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Action $record */
        return DB::transaction(function () use ($record, $data) {
            $record->update(array_diff_key($data, array_flip([...ActionForm::VERSION_FIELDS, 'executor', 'modul_id'])));

            $current = $record->currentVersion;
            $new = ActionForm::versionData($data);
            if ($current === null || $new !== ActionForm::versionData($current->only(ActionForm::VERSION_FIELDS))) {
                $version = $record->versions()->create([
                    ...$new,
                    'versio' => ($current->versio ?? 0) + 1,
                    'creat_per' => auth()->id(),
                ]);
                $record->unsetRelation('currentVersion');
                Notification::make()->success()->title("S'ha creat la versió {$version->versio}")->send();
            }

            return $record;
        });
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Acció desada';
    }
}
