<?php

namespace App\Filament\Resources\Actions\Pages\Concerns;

use App\Enums\CheckStatus;
use App\Enums\Executor;
use App\Filament\Actions\LaunchAction;
use App\Filament\Resources\Actions\Schemas\ActionForm;
use App\Models\Action as ActionModel;
use App\Models\ActionVersion;
use App\Models\Check;
use App\Models\Student;
use App\Runner\ActionRunner;
use App\Runner\CheckRunner;
use App\Runner\CodeValidator;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

/**
 * Proves de l'esborrany a l'editor d'accions: simular o executar el codi del formulari sense
 * desar-lo i verificar amb un check. Els resultats només es mostren a la pàgina; no queden
 * enlloc. Una versió nova no es pot desar sense haver provat abans el codi tal com està.
 */
trait TestsDraftVersion
{
    /** @var list<array<string, string|null>> resultats de les proves, el més nou primer */
    public array $testResults = [];

    /** Empremta dels camps de versió de l'últim esborrany provat. */
    public ?string $testedHash = null;

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

    protected function draftIsTested(): bool
    {
        return $this->testedHash === $this->draftHash();
    }

    /** Atura el desament si el codi no és vàlid o si l'esborrany no s'ha provat tal com està. */
    protected function requireTestedDraft(): void
    {
        $this->requireValidCode();
        if ($this->draftIsTested()) {
            return;
        }

        Notification::make()
            ->warning()
            ->title('Prova els canvis abans de desar')
            ->body('Simula o executa l\'esborrany (botó Provar) amb el codi tal com està ara.')
            ->persistent()
            ->send();
        $this->halt();
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

    public function clearTestResults(): void
    {
        $this->testResults = [];
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
            ->modalHeading($simulate ? 'Simular l\'esborrany' : 'Executar l\'esborrany')
            ->modalDescription($simulate
                ? 'Es prova el codi del formulari, sense desar, amb --check --diff. El resultat només es mostra en aquesta pàgina.'
                : 'Es prova el codi del formulari, sense desar. ATENCIÓ: s\'executa de veritat als equips triats. El resultat només es mostra en aquesta pàgina.')
            ->modalSubmitActionLabel($simulate ? 'Simular' : 'Executar')
            ->modalWidth('4xl')
            ->schema(fn () => LaunchAction::schema($this->draftAction(), $this->draftVersion()->parametres ?? []))
            ->fillForm(fn () => LaunchAction::fill($this->draftVersion()->parametres ?? []))
            ->action(function (array $data, Action $action) use ($simulate) {
                $version = $this->draftVersion();
                $students = Student::whereKey($data['alumnes'])->orderBy('num_taula')->get();
                @set_time_limit($version->temps_max_seg + 60);

                try {
                    $runs = app(ActionRunner::class)->preview(
                        $this->draftAction(),
                        $version,
                        $students,
                        $data['equips'] ?? [],
                        LaunchAction::givenParams($version->parametres ?? [], $data),
                        $simulate,
                    );
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();
                }

                $this->testedHash = $this->draftHash();
                $title = $simulate ? 'Simulació de l\'esborrany' : 'Execució de l\'esborrany';
                foreach (array_reverse($runs) as $run) {
                    array_unshift($this->testResults, [
                        'titol' => $title,
                        'alumne' => sprintf('%02d · %s', $run->student->num_taula, $run->student->nom_complet),
                        'estat' => $run->estat->value,
                        'color' => self::hex($run->estat->getColor()),
                        'resum' => $run->ok === null ? null : "ok={$run->ok} changed={$run->changed} failed={$run->failed}",
                        'parametres' => $run->parametres ? collect($run->parametres)->map(fn ($v, $k) => "{$k}={$v}")->implode(' ') : null,
                        'sortida' => $run->sortida,
                        'hora' => now()->format('H:i:s'),
                    ]);
                }

                $failures = collect($runs)->filter(fn ($run) => $run->estat->isFailure())->count();
                Notification::make()
                    ->{$failures ? 'warning' : 'success'}()
                    ->title($failures ? "Prova feta: {$failures} amb errors" : 'Prova feta')
                    ->body('Els resultats són a la secció Proves, a sota del formulari.')
                    ->send();
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
                $runner = app(CheckRunner::class);
                $results = [];
                foreach (Student::whereKey($data['alumnes'])->orderBy('num_taula')->get() as $student) {
                    $result = $runner->run($check, $student);
                    $results[] = [
                        'titol' => "Check «{$check->nom}»",
                        'alumne' => sprintf('%02d · %s', $student->num_taula, $student->nom_complet),
                        'estat' => $result->status->value,
                        'color' => self::hex($result->status === CheckStatus::Pass ? 'success' : ($result->status === CheckStatus::Fail ? 'danger' : 'warning')),
                        'resum' => $result->message,
                        'parametres' => null,
                        'sortida' => $result->output,
                        'hora' => now()->format('H:i:s'),
                    ];
                }
                $this->testResults = [...$results, ...$this->testResults];

                $failed = collect($results)->where('estat', '!=', CheckStatus::Pass->value)->count();
                Notification::make()
                    ->{$failed ? 'warning' : 'success'}()
                    ->title($failed ? "Verificació: {$failed} no passen" : 'Verificació: tots passen')
                    ->body('Els resultats són a la secció Proves, a sota del formulari.')
                    ->send();
            });
    }

    private static function hex(string $color): string
    {
        return match ($color) {
            'success' => '#16a34a',
            'info' => '#2563eb',
            'danger' => '#dc2626',
            default => '#d97706',
        };
    }
}
