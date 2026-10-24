<?php

namespace App\Filament\Resources\Actions\Pages;

use App\Filament\Resources\Actions\ActionResource;
use App\Filament\Resources\Actions\Schemas\ActionForm;
use App\Models\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateAction extends CreateRecord
{
    protected static string $resource = ActionResource::class;

    protected static ?string $title = 'Nova acció';

    /** Crea l'acció i la seva versió 1. */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $action = Action::create(array_diff_key($data, array_flip(ActionForm::VERSION_FIELDS)));
            $action->versions()->create([
                ...ActionForm::versionData($data),
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
