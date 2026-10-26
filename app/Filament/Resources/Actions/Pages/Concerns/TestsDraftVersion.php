<?php

namespace App\Filament\Resources\Actions\Pages\Concerns;

use App\Enums\Executor;
use App\Filament\Actions\LaunchAction;
use App\Filament\Concerns\QueuesEditorTests;
use App\Filament\Resources\Actions\Schemas\ActionForm;
use App\Jobs\RunEditorTest;
use App\Models\Action as ActionModel;
use App\Models\ActionVersion;
use App\Models\Check;
use App\Runner\ActionRunner;
use App\Runner\CodeValidator;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

/**
 * Proves de l'esborrany a l'editor d'accions: simular o executar el codi del formulari sense
 * desar-lo i verificar amb un check. Van per la cua com totes les proves de l'editor
 * (QueuesEditorTests).
 */
trait TestsDraftVersion
{
    use QueuesEditorTests;

    /** L'acció tal com és al formulari (pot no estar desada). */
    abstract protected function draftAction(): ?ActionModel;

    protected function draftVersion(): ActionVersion
    {
        return new ActionVersion(ActionForm::versionData($this->form->getRawState()));
    }

    protected function draftHash(): string
    {
        return md5(json_encode(ActionForm::versionData($this->form->getRawState())));
    }

    /** Atura el desament si el codi no es pot executar així (grups d'equips, secrets en clar). */
    protected function requireValidCode(): void
    {
        $action = $this->draftAction();
        if ($action === null) {
            return;
        }

        try {
            app(CodeValidator::class)->validate($action->module, $action->executor, $this->draftVersion()->codi);
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

    protected function testActionGroup(): ActionGroup
    {
        return ActionGroup::make([
            $this->draftRunAction(simulate: true),
            $this->draftRunAction(simulate: false),
            $this->draftCheckAction(),
        ])
            ->label('Provar')
            ->icon(Heroicon::OutlinedBeaker)
            ->color('info')
            ->button();
    }

    private function draftRunAction(bool $simulate): Action
    {
        return Action::make($simulate ? 'provarSimular' : 'provarExecutar')
            ->label($simulate ? 'Simular l\'esborrany' : 'Executar l\'esborrany')
            ->icon($simulate ? Heroicon::OutlinedBeaker : Heroicon::OutlinedPlay)
            ->visible(fn () => ! $simulate || $this->draftAction()?->executor === Executor::Ansible)
            ->disabled(fn () => $this->draftAction() === null)
            // Abans de triar alumnes: si el formulari té errors, no s'obre la finestra.
            ->beforeFormFilled(fn () => $this->validateDraft())
            ->modalHeading($simulate ? 'Simular l\'esborrany' : 'Executar l\'esborrany')
            ->modalDescription($simulate
                ? 'Es prova el codi del formulari, sense desar, amb --check --diff. El resultat només es mostra en aquesta pàgina.'
                : 'Es prova el codi del formulari, sense desar. ATENCIÓ: s\'executa de veritat als equips triats. El resultat només es mostra en aquesta pàgina.')
            ->modalSubmitActionLabel($simulate ? 'Simular' : 'Executar')
            ->modalWidth('4xl')
            ->schema(fn () => LaunchAction::schema($this->draftAction(), $this->draftVersion()->parametres ?? []))
            ->fillForm(fn () => LaunchAction::fill($this->draftVersion()->parametres ?? []))
            ->action(function (array $data, Action $action) use ($simulate) {
                $draft = $this->draftAction();
                $version = $this->draftVersion();
                $hostTypes = $data['equips'] ?? [];

                try {
                    $params = app(ActionRunner::class)->prepare($draft, $version, $hostTypes, LaunchAction::givenParams($version->parametres ?? [], $data), $simulate);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }

                $this->testedHash = $this->draftHash();
                $this->queueTests($simulate ? 'Simulació de l\'esborrany' : 'Execució de l\'esborrany', $data['alumnes'], fn (string $key, string $title, int $studentId) => new RunEditorTest(
                    $key,
                    $title,
                    $studentId,
                    action: ['id' => $draft->id, 'modul_id' => $draft->modul_id, 'nom' => $draft->nom, 'executor' => $draft->executor->value],
                    version: $version->only(ActionForm::VERSION_FIELDS),
                    hostTypes: $hostTypes,
                    params: $params,
                    checkMode: $simulate,
                ));
            });
    }

    private function draftCheckAction(): Action
    {
        return Action::make('provarCheck')
            ->label('Verificar amb un check')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->disabled(fn () => $this->draftAction() === null)
            ->modalHeading('Verificar amb un check')
            ->modalDescription('Executa el check triat (la seva versió vigent) sobre els alumnes. No es desa cap resultat.')
            ->modalSubmitActionLabel('Verificar')
            ->modalWidth('4xl')
            ->schema(function () {
                $action = $this->draftAction();
                $students = $action->module->students()->where('es_actiu', true)->orderBy('num_taula')->get();

                return [
                    Select::make('check_id')
                        ->label('Check')
                        ->options(Check::whereHas('task.block', fn ($q) => $q->where('modul_id', $action->modul_id))->orderBy('nom')->pluck('nom', 'id'))
                        ->default(fn () => $this->form->getRawState()['checks_verificacio'][0] ?? null)
                        ->searchable()
                        ->required(),
                    LaunchAction::studentPicker($students)->required(),
                ];
            })
            ->action(function (array $data) {
                $check = Check::findOrFail($data['check_id']);
                $this->queueTests("Check «{$check->nom}»", $data['alumnes'], fn (string $key, string $title, int $studentId) => new RunEditorTest(
                    $key,
                    $title,
                    $studentId,
                    version: ['temps_max_seg' => $check->currentVersion?->temps_max_seg ?? 15],
                    checkId: $check->id,
                ));
            });
    }
}
