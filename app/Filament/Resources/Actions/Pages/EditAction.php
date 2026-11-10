<?php

namespace App\Filament\Resources\Actions\Pages;

use App\Filament\Actions\CopyFromLibrary;
use App\Filament\Resources\Actions\ActionResource;
use App\Filament\Resources\Actions\Pages\Concerns\TestsDraftVersion;
use App\Filament\Resources\Actions\Schemas\ActionForm;
use App\Models\Action;
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
 * "Editar la darrera versió": les dades de l'acció es modifiquen; si canvia el codi,
 * els paràmetres, el temps màxim o els checks de verificació, es crea la versió N+1.
 *
 * @property Action $record
 */
class EditAction extends EditRecord
{
    use TestsDraftVersion;

    protected static string $resource = ActionResource::class;

    /** L'acció amb el mòdul i l'executor del formulari (es poden canviar). */
    protected function draftAction(): Action
    {
        $state = $this->form->getRawState();
        $action = clone $this->record;
        $action->forceFill([
            'modul_id' => $state['modul_id'] ?? $this->record->modul_id,
            'executor' => $this->draftExecutor() ?? $this->record->executor,
        ]);

        return $action->unsetRelation('module');
    }

    /**
     * Una versió nova (codi, paràmetres, temps, checks o executor diferents) només es desa si
     * s'ha provat tal com és. Si només es mou a un altre mòdul, el codi hi ha de ser vàlid.
     */
    protected function beforeSave(): void
    {
        $current = $this->record->currentVersion;
        $draft = ActionForm::versionData($this->form->getRawState());
        if ($current === null || $this->executorChanged() || $draft !== ActionForm::versionData($current->only(ActionForm::VERSION_FIELDS))) {
            $this->requireTestedDraft();
        } elseif ((int) ($this->form->getRawState()['modul_id'] ?? 0) !== $this->record->modul_id) {
            $this->requireValidCode();
        }
    }

    private function executorChanged(): bool
    {
        return $this->draftExecutor() !== null && $this->draftExecutor() !== $this->record->executor;
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
            $this->testActionGroup(),
            CopyFromLibrary::action()->record($this->record),
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
            DeleteAction::make()
                ->modalDescription(fn () => DeletionImpact::action($this->record)),
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
            $executorChanged = $this->executorChanged();
            $record->update(array_diff_key($data, array_flip([...ActionForm::VERSION_FIELDS, 'comentari'])));

            $current = $record->currentVersion;
            $new = ActionForm::versionData($data);
            if ($current === null || $executorChanged || $new !== ActionForm::versionData($current->only(ActionForm::VERSION_FIELDS))) {
                $version = $record->versions()->create([
                    ...$new,
                    'executor' => $record->executor,
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
        return 'Acció desada';
    }
}
