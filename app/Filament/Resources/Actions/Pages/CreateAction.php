<?php

namespace App\Filament\Resources\Actions\Pages;

use App\Enums\Executor;
use App\Filament\Resources\Actions\ActionResource;
use App\Filament\Resources\Actions\Pages\Concerns\TestsDraftVersion;
use App\Filament\Resources\Actions\Schemas\ActionForm;
use App\Models\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateAction extends CreateRecord
{
    protected static string $resource = ActionResource::class;

    use TestsDraftVersion;

    protected static ?string $title = 'Nova acció';

    /** L'acció encara sense desar, si ja té mòdul i executor. */
    protected function draftAction(): ?Action
    {
        $state = $this->form->getRawState();
        if (blank($state['modul_id'] ?? null) || blank($state['executor'] ?? null)) {
            return null;
        }

        return new Action([
            'modul_id' => $state['modul_id'],
            'nom' => $state['nom'] ?? 'Nova acció',
            'executor' => $state['executor'] instanceof Executor ? $state['executor'] : Executor::from($state['executor']),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [$this->testActionGroup()];
    }

    protected function beforeCreate(): void
    {
        $this->requireTestedDraft();
    }

    /** Crea l'acció i la seva versió 1. */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $action = Action::create(array_diff_key($data, array_flip([...ActionForm::VERSION_FIELDS, 'comentari'])));
            $action->versions()->create([
                ...ActionForm::versionData($data),
                'comentari' => $data['comentari'] ?? null,
                'versio' => 1,
                'creat_per' => auth()->id(),
            ]);

            return $action;
        });
    }

    protected function getRedirectUrl(): string
    {
        return ActionResource::getUrl('index');
    }
}
