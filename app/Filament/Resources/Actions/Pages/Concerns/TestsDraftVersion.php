<?php

namespace App\Filament\Resources\Actions\Pages\Concerns;

use App\Enums\Executor;
use App\Filament\Actions\LaunchAction;
use App\Filament\Resources\Actions\Schemas\ActionForm;
use App\Jobs\RunEditorTest;
use App\Models\Action as ActionModel;
use App\Models\ActionVersion;
use App\Models\Check;
use App\Models\Student;
use App\Runner\ActionRunner;
use App\Runner\CodeValidator;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Proves de l'esborrany a l'editor d'accions: simular o executar el codi del formulari sense
 * desar-lo i verificar amb un check. Cada alumne és un treball de la cua (RunEditorTest), perquè
 * una prova llarga no talli la petició web, i la pàgina en recull els resultats a mesura que
 * acaben. Només es mostren a la pàgina; no queden enlloc. Una versió nova no es pot desar
 * sense haver provat abans el codi tal com està.
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

    /**
     * Posa a la cua una prova per alumne i en deixa la fila «en cua» a la secció Proves;
     * refreshTestResults() hi posa el resultat quan arriba.
     *
     * @param  list<int|string>  $studentIds
     * @param  callable(string, string, int): RunEditorTest  $job
     */
    private function queueTests(string $title, array $studentIds, callable $job): void
    {
        $rows = [];
        foreach (Student::whereKey($studentIds)->orderBy('num_taula')->get() as $student) {
            $key = (string) Str::uuid();
            dispatch($job($key, $title, $student->id));
            $rows[] = [
                'clau' => $key,
                'titol' => $title,
                'alumne' => sprintf('%02d · %s', $student->num_taula, $student->nom_complet),
                'estat' => 'en cua',
                'color' => RunEditorTest::hex('gray'),
                'resum' => null,
                'parametres' => null,
                'sortida' => null,
                'hora' => now()->format('H:i:s'),
            ];
        }
        $this->testResults = [...$rows, ...$this->testResults];
        $this->refreshTestResults(notify: false);

        Notification::make()
            ->info()
            ->title('Prova a la cua per a '.count($rows).' alumnes')
            ->body('Els resultats apareixen a la secció Proves, a sota del formulari, a mesura que acaben.')
            ->send();
    }

    /** Recull els resultats de les proves que ja han acabat (la vista ho crida cada 2 s mentre n'hi ha a la cua). */
    public function refreshTestResults(bool $notify = true): void
    {
        $finished = [];
        foreach ($this->testResults as $i => $row) {
            if (! isset($row['clau'])) {
                continue;
            }
            $result = Cache::pull(RunEditorTest::cacheKey($row['clau']));
            if ($result !== null) {
                unset($row['clau']);
                $this->testResults[$i] = [...$row, ...$result];
                $finished[] = $this->testResults[$i];
            }
        }

        $pending = collect($this->testResults)->filter(fn (array $row) => isset($row['clau']))->count();
        if (! $notify || $finished === [] || $pending > 0) {
            return;
        }
        $failures = collect($finished)->whereNotIn('estat', ['ok', 'changed', 'pass'])->count();
        Notification::make()
            ->{$failures ? 'warning' : 'success'}()
            ->title($failures ? "Prova acabada: {$failures} amb errors" : 'Prova acabada')
            ->send();
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
