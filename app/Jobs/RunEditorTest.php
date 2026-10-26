<?php

namespace App\Jobs;

use App\Enums\CheckStatus;
use App\Models\Action;
use App\Models\ActionVersion;
use App\Models\Check;
use App\Models\Student;
use App\Runner\ActionRunner;
use App\Runner\CheckRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Prova de l'editor d'accions per a un alumne: l'esborrany (simulat o executat) o un check
 * de verificació. Va per la cua perquè la petició web no es talli (Cloudflare talla als 100 s);
 * el resultat es deixa a la memòria cau amb la clau $key i la pàgina el recull. No es desa enlloc.
 */
class RunEditorTest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * @param  array{modul_id: int, nom: string, executor: string, id: ?int}  $action  l'acció del formulari (pot no estar desada)
     * @param  array<string, mixed>  $version  camps de la versió de l'esborrany
     * @param  list<string>  $hostTypes
     * @param  array<string, string>  $params
     */
    public function __construct(
        public string $key,
        public string $title,
        public int $studentId,
        public array $action = [],
        public array $version = [],
        public array $hostTypes = [],
        public array $params = [],
        public bool $checkMode = false,
        public ?int $checkId = null,
    ) {
        $this->onQueue(config('heimdeye.runner.queue'));
        $this->timeout = (int) ($version['temps_max_seg'] ?? 60) + 60;
    }

    /** Clau de la memòria cau amb el resultat d'una prova. */
    public static function cacheKey(string $key): string
    {
        return "heimdeye:prova:{$key}";
    }

    public function handle(): void
    {
        $student = Student::findOrFail($this->studentId);

        $this->put($this->checkId === null ? $this->runDraft($student) : $this->runCheck($student));
    }

    public function failed(?Throwable $e): void
    {
        $this->put([
            'estat' => 'error',
            'color' => self::hex('danger'),
            'resum' => null,
            'parametres' => null,
            'sortida' => "No s'ha pogut executar: ".($e?->getMessage() ?: 'error desconegut'),
        ]);
    }

    /** @return array<string, ?string> */
    private function runDraft(Student $student): array
    {
        $action = (new Action)->forceFill($this->action);
        $action->exists = $this->action['id'] !== null;
        $run = app(ActionRunner::class)->runFor($action, new ActionVersion($this->version), $student, $this->hostTypes, $this->params, $this->checkMode);

        return [
            'estat' => $run->estat->value,
            'color' => self::hex($run->estat->getColor()),
            'resum' => $run->ok === null ? null : "ok={$run->ok} changed={$run->changed} failed={$run->failed}",
            'parametres' => $run->parametres ? collect($run->parametres)->map(fn ($v, $k) => "{$k}={$v}")->implode(' ') : null,
            'sortida' => $run->sortida,
        ];
    }

    /** @return array<string, ?string> */
    private function runCheck(Student $student): array
    {
        $result = app(CheckRunner::class)->run(Check::findOrFail($this->checkId), $student);

        return [
            'estat' => $result->status->value,
            'color' => self::hex(match ($result->status) {
                CheckStatus::Pass => 'success',
                CheckStatus::Fail => 'danger',
                default => 'warning',
            }),
            'resum' => $result->message,
            'parametres' => null,
            'sortida' => $result->output,
        ];
    }

    /** @param  array<string, ?string>  $result */
    private function put(array $result): void
    {
        Cache::put(self::cacheKey($this->key), [...$result, 'hora' => now()->format('H:i:s')], now()->addHours(2));
    }

    public static function hex(string $color): string
    {
        return match ($color) {
            'success' => '#16a34a',
            'info' => '#2563eb',
            'danger' => '#dc2626',
            'gray' => '#6b7280',
            default => '#d97706',
        };
    }
}
