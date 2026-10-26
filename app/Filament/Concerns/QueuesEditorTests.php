<?php

namespace App\Filament\Concerns;

use App\Jobs\RunEditorTest;
use App\Models\Student;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Proves de l'esborrany a l'editor (d'accions i de checks): cada alumne és un treball de la cua
 * (RunEditorTest), perquè una prova llarga no talli la petició web, i la pàgina en recull els
 * resultats a mesura que acaben. Només es mostren a la pàgina; no queden enlloc. Una versió nova
 * no es pot desar sense haver provat abans el codi tal com està.
 */
trait QueuesEditorTests
{
    /** @var list<array<string, string|null>> resultats de les proves, el més nou primer */
    public array $testResults = [];

    /** Empremta dels camps de versió de l'últim esborrany provat. */
    public ?string $testedHash = null;

    /** Empremta dels camps de versió del formulari tal com està ara. */
    abstract protected function draftHash(): string;

    /** Atura el desament si el codi no es pot executar així (grups d'equips, secrets en clar). */
    abstract protected function requireValidCode(): void;

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
            ->body('Prova l\'esborrany (botó Provar) amb el codi tal com està ara.')
            ->persistent()
            ->send();
        $this->halt();
    }

    /**
     * Valida el formulari amb les mateixes regles que en desar. Els errors surten sota cada camp,
     * que pot quedar lluny del botó Provar: per això també es diuen en una notificació.
     */
    protected function validateDraft(): void
    {
        try {
            $this->form->validate();
        } catch (ValidationException $e) {
            Notification::make()
                ->danger()
                ->title('No es pot provar: el formulari té errors')
                ->body(implode("\n", array_unique($e->validator->errors()->all())))
                ->persistent()
                ->send();

            throw $e;
        }
    }

    public function clearTestResults(): void
    {
        $this->testResults = [];
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
                'comanda' => null,
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
        $failures = collect($finished)->whereNotIn('estat', ['ok', 'changed', 'pass', 'omes'])->count();
        Notification::make()
            ->{$failures ? 'warning' : 'success'}()
            ->title($failures ? "Prova acabada: {$failures} amb errors" : 'Prova acabada')
            ->send();
    }
}
