<?php

namespace App\Jobs;

use App\Enums\Executor;
use App\Models\Action;
use App\Models\ActionVersion;
use App\Models\Check;
use App\Models\CheckVersion;
use App\Models\Module;
use App\Models\Student;
use App\Runner\ActionRunner;
use App\Runner\AgentEditorTests;
use App\Runner\CheckRunner;
use App\Runner\CommandPreview;
use App\Runner\ContextBuilder;
use App\Runner\Params;
use App\Support\Library;
use App\Support\VariableResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * Prova de l'editor per a un alumne: l'esborrany d'una acció (simulat o executat), l'esborrany
 * d'un check ($check) o un check desat, amb la seva versió vigent ($checkId). Amb l'executor agent
 * no s'executa aquí: queda pendent perquè la reculli la instància (AgentEditorTests). Va per la cua perquè la petició web no es talli (Cloudflare talla als 100 s);
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
     * @param  array<string, mixed>  $version  camps de la versió de l'esborrany (d'acció o de check)
     * @param  list<string>  $hostTypes
     * @param  array<string, string>  $params
     * @param  array{tasca_id: int, nom: string, executor: string, id: ?int}|array{}  $check  el check del formulari (pot no estar desat)
     * @param  ?int  $contextModuleId  a la biblioteca, el mòdul real on es prova el check
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
        public array $check = [],
        public ?int $contextModuleId = null,
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

        $result = match (true) {
            $this->checkId !== null => $this->runCheck(Check::findOrFail($this->checkId), $student),
            $this->check !== [] => $this->runCheck($this->draftCheck(), $student, new CheckVersion($this->version)),
            default => $this->runDraft($student),
        };
        // Amb l'agent, la prova queda pendent i el resultat el deixa AgentEditorTests quan torna.
        if ($result !== null) {
            $this->put($result);
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->put([
            'estat' => 'error',
            'color' => self::hex('danger'),
            'resum' => null,
            'parametres' => null,
            'comanda' => null,
            'sortida' => "No s'ha pogut executar: ".($e?->getMessage() ?: 'error desconegut'),
        ]);
    }

    /** @return array<string, ?string>|null null si ha quedat pendent per a l'agent */
    private function runDraft(Student $student): ?array
    {
        $action = (new Action)->forceFill($this->action);
        $action->exists = $this->action['id'] !== null;
        if ($action->executor === Executor::Agent) {
            return app(AgentEditorTests::class)->enqueue($this->key, 'accio', $action->module, $student, $this->version['codi'] ?? '', (int) ($this->version['temps_max_seg'] ?? 60), $this->params);
        }
        $run = app(ActionRunner::class)->runFor($action, new ActionVersion($this->version), $student, $this->hostTypes, $this->params, $this->checkMode);

        return [
            'estat' => $run->estat->value,
            'color' => self::hex($run->estat->getColor()),
            'resum' => $run->ok === null ? null : "ok={$run->ok} changed={$run->changed} failed={$run->failed}",
            'parametres' => $run->parametres ? collect($run->parametres)->map(fn ($v, $k) => "{$k}={$v}")->implode(' ') : null,
            'comanda' => $this->preview($action->module, $action->executor, $student, $this->version['codi'] ?? '', $this->params, $this->hostTypes ?: null),
            'sortida' => $run->sortida,
        ];
    }

    /** El check del formulari (pot no estar desat); a la biblioteca, en el mòdul de context. */
    private function draftCheck(): Check
    {
        $check = (new Check)->forceFill($this->check);
        $check->exists = $this->check['id'] !== null;

        return $this->contextModuleId === null ? $check : Library::inContext($check, Module::findOrFail($this->contextModuleId));
    }

    /** @return array<string, ?string>|null null si ha quedat pendent per a l'agent */
    private function runCheck(Check $check, Student $student, ?CheckVersion $version = null): ?array
    {
        $version ??= $check->currentVersion;
        if ($check->executor === Executor::Agent && $version !== null) {
            try {
                $params = Params::values($version->parametres);
            } catch (InvalidArgumentException $e) {
                return ['estat' => 'error', 'color' => self::hex('danger'), 'resum' => $e->getMessage(), 'parametres' => null, 'comanda' => null, 'sortida' => null];
            }

            return app(AgentEditorTests::class)->enqueue($this->key, 'check', $check->module, $student, $version->codi, (int) $version->temps_max_seg, $params);
        }

        $result = app(CheckRunner::class)->run($check, $student, $version);
        try {
            $params = Params::values($version?->parametres);
        } catch (InvalidArgumentException) {
            $params = null;
        }

        return [
            'estat' => $result->status->value,
            'color' => self::hex($result->status->getColor()),
            'resum' => $result->message,
            'parametres' => null,
            'comanda' => $version && $params !== null ? $this->preview($check->module, $check->executor, $student, $version->codi, $params) : null,
            'sortida' => $result->output,
        ];
    }

    /**
     * El codi amb les variables i els paràmetres de l'alumne ja substituïts (null si no es pot calcular).
     *
     * @param  array<string, string>  $params
     * @param  list<string>|null  $hostTypes
     */
    private function preview(Module $module, Executor $executor, Student $student, string $code, array $params, ?array $hostTypes = null): ?string
    {
        $builder = new ContextBuilder(app(VariableResolver::class));
        try {
            $context = $builder->build($module, $student, $executor, $code, 1, $params, $this->checkMode, $hostTypes);

            return CommandPreview::describe($executor, $context);
        } catch (InvalidArgumentException) {
            return null;
        } finally {
            $builder->cleanup();
        }
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
