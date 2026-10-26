<?php

namespace App\Filament\Resources\Checks\Pages;

use App\Filament\Resources\Checks\CheckResource;
use App\Filament\Resources\Checks\Pages\Concerns\TestsDraftCheck;
use App\Filament\Resources\Checks\Schemas\CheckForm;
use App\Models\Check;
use App\Support\DeletionImpact;
use Filament\Actions\Action as PageAction;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * "Editar la darrera versió": les dades del check es modifiquen; si canvia el codi, els
 * paràmetres o el temps màxim, es crea la versió N+1. Els assoliments no es recalculen mai.
 *
 * @property Check $record
 */
class EditCheck extends EditRecord
{
    use TestsDraftCheck;

    protected static string $resource = CheckResource::class;

    protected function draftCheck(): Check
    {
        return $this->record;
    }

    /** Una versió nova només es desa si s'ha provat tal com és. */
    protected function beforeSave(): void
    {
        $current = $this->record->currentVersion;
        $draft = CheckForm::versionData($this->form->getRawState());
        if ($current === null || $draft !== CheckForm::versionData($current->only(CheckForm::VERSION_FIELDS))) {
            $this->requireTestedDraft();
        }
    }

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
            $this->testAction(),
            PageAction::make('versions')
                ->label('Versions')
                ->icon(Heroicon::OutlinedRectangleStack)
                ->color('gray')
                ->url(fn () => CheckResource::getUrl('versions', ['record' => $this->record])),
            PageAction::make('historial')
                ->label('Historial')
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->url(fn () => CheckResource::getUrl('runs', ['record' => $this->record])),
            DeleteAction::make()
                ->modalDescription(fn () => DeletionImpact::check($this->record)),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $version = $this->record->currentVersion;

        return [
            ...$data,
            'modul_id' => $this->record->module?->id,
            ...($version?->only(CheckForm::VERSION_FIELDS) ?? []),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Check $record */
        return DB::transaction(function () use ($record, $data) {
            $record->update(array_diff_key($data, array_flip([...CheckForm::VERSION_FIELDS, 'comentari', 'executor', 'modul_id'])));

            $current = $record->currentVersion;
            $new = CheckForm::versionData($data);
            if ($current === null || $new !== CheckForm::versionData($current->only(CheckForm::VERSION_FIELDS))) {
                $version = $record->versions()->create([
                    ...$new,
                    'comentari' => $data['comentari'] ?? null,
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
        return 'Check desat';
    }
}
