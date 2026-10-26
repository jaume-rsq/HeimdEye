<?php

namespace App\Filament\Resources\Checks\Pages;

use App\Enums\Executor;
use App\Filament\Resources\Checks\CheckResource;
use App\Filament\Resources\Checks\Pages\Concerns\TestsDraftCheck;
use App\Filament\Resources\Checks\Schemas\CheckForm;
use App\Models\Check;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateCheck extends CreateRecord
{
    use TestsDraftCheck;

    protected static string $resource = CheckResource::class;

    protected static ?string $title = 'Nou check';

    /** El check encara sense desar, si ja té tasca i executor. */
    protected function draftCheck(): ?Check
    {
        $state = $this->form->getRawState();
        if (blank($state['tasca_id'] ?? null) || blank($state['executor'] ?? null)) {
            return null;
        }

        return new Check([
            'tasca_id' => $state['tasca_id'],
            'nom' => $state['nom'] ?? 'Nou check',
            'executor' => $state['executor'] instanceof Executor ? $state['executor'] : Executor::from($state['executor']),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [$this->testAction()];
    }

    protected function beforeCreate(): void
    {
        $this->requireTestedDraft();
    }

    /** Crea el check i la seva versió 1. */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $check = Check::create(array_diff_key($data, array_flip([...CheckForm::VERSION_FIELDS, 'comentari'])));
            $check->versions()->create([
                ...CheckForm::versionData($data),
                'comentari' => $data['comentari'] ?? null,
                'versio' => 1,
                'creat_per' => auth()->id(),
            ]);

            return $check;
        });
    }

    protected function getRedirectUrl(): string
    {
        return CheckResource::getUrl('index');
    }
}
