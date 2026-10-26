<?php

namespace App\Filament\Resources\Checks\Pages\Concerns;

use App\Filament\Actions\LaunchCheck;
use App\Filament\Concerns\QueuesEditorTests;
use App\Filament\Resources\Checks\Schemas\CheckForm;
use App\Jobs\RunEditorTest;
use App\Models\Check;
use App\Models\CheckVersion;
use App\Runner\CheckRunner;
use App\Runner\CodeValidator;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

/**
 * Proves de l'esborrany a l'editor de checks: executar el codi del formulari sense desar-lo
 * per als alumnes triats. Van per la cua com totes les proves de l'editor (QueuesEditorTests)
 * i no compten per als alumnes.
 */
trait TestsDraftCheck
{
    use QueuesEditorTests;

    /** El check tal com és al formulari (pot no estar desat), si ja té tasca i executor. */
    abstract protected function draftCheck(): ?Check;

    protected function draftVersion(): CheckVersion
    {
        return new CheckVersion(CheckForm::versionData($this->form->getRawState()));
    }

    protected function draftHash(): string
    {
        return md5(json_encode(CheckForm::versionData($this->form->getRawState())));
    }

    protected function requireValidCode(): void
    {
        $check = $this->draftCheck();
        if ($check === null) {
            return;
        }

        try {
            app(CodeValidator::class)->validate($check->module, $check->executor, $this->draftVersion()->codi);
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->danger()
                ->title('El codi no es pot desar')
                ->body($e->getMessage())
                ->persistent()
                ->send();
            $this->halt();
        }
    }

    protected function testAction(): Action
    {
        return Action::make('provar')
            ->label('Provar')
            ->icon(Heroicon::OutlinedBeaker)
            ->color('info')
            ->disabled(fn () => $this->draftCheck() === null)
            ->modalHeading('Provar l\'esborrany')
            ->modalDescription('Es prova el codi del formulari, sense desar, per als alumnes triats. El resultat només es mostra en aquesta pàgina i no compta per als alumnes.')
            ->modalSubmitActionLabel('Provar')
            ->modalWidth('4xl')
            ->schema(function () {
                $check = $this->draftCheck();

                return [LaunchCheck::studentPicker(LaunchCheck::students($check), $check)->required()];
            })
            ->action(function (array $data, Action $action) {
                // Les mateixes regles que en desar (noms dels paràmetres...).
                $this->form->validate();
                $check = $this->draftCheck();
                $version = $this->draftVersion();

                try {
                    app(CheckRunner::class)->prepare($check, $version);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }

                $this->testedHash = $this->draftHash();
                $this->queueTests('Prova de l\'esborrany', $data['alumnes'], fn (string $key, string $title, int $studentId) => new RunEditorTest(
                    $key,
                    $title,
                    $studentId,
                    version: $version->only(CheckForm::VERSION_FIELDS),
                    check: ['id' => $check->id, 'tasca_id' => $check->tasca_id, 'nom' => $check->nom, 'executor' => $check->executor->value],
                ));
            });
    }
}
