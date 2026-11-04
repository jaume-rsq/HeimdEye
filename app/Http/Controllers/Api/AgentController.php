<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActionStatus;
use App\Enums\CheckStatus;
use App\Models\ActionRun;
use App\Models\AgentInstance;
use App\Models\AgentTask;
use App\Models\ConsoleSession;
use App\Models\Student;
use App\Runner\CheckResult;
use App\Runner\Masker;
use App\Runner\ResultRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API que fa servir l'agent instal·lat a la instància de l'alumne (model pull).
 *
 * L'alumne s'autentica amb el seu token (middleware AuthenticateAgent). El hostname identifica
 * la instància i fa de rol per a la capçalera "# hosts:".
 *
 * El mateix long poll serveix dues cues: les accions (incidències injectades, taula execucions_accio)
 * i els checks (verificacions llançades des de HeimdEye, taula feines_agent). L'agent no distingeix:
 * cada feina porta un identificador opac (`id`) que torna tal qual al resultat, i és el servidor qui,
 * segons la forma de l'identificador, encamina el resultat a una cua o l'altra.
 */
class AgentController
{
    public function __construct(private ResultRecorder $recorder) {}

    /** Alta o reactivació de la instància en arrencar l'agent. */
    public function register(Request $request): JsonResponse
    {
        $data = $this->validateInstance($request);
        $instance = $this->touchInstance($this->student($request), $data);

        return response()->json([
            'ok' => true,
            'instance_id' => $instance->id,
            'poll_hold_seconds' => (int) config('heimdeye.agent.poll_hold_seconds', 25),
        ]);
    }

    /** Long poll: retorna la feina pendent d'aquesta instància (acció o check), o null si no n'hi ha. */
    public function poll(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $data = $this->validateInstance($request);
        $instance = $this->touchInstance($student, $data);

        $hold = (int) config('heimdeye.agent.poll_hold_seconds', 25);
        $deadline = microtime(true) + $hold;
        do {
            $this->expireOverdue();
            // Les accions (incidències) tenen prioritat sobre els checks, i tots dos sobre la consola.
            $task = $this->nextAction($student, $instance)
                ?? $this->nextCheck($student, $instance)
                ?? $this->nextConsole($student, $instance);
            if ($task !== null) {
                return response()->json(['task' => $task]);
            }

            if (microtime(true) >= $deadline || connection_aborted()) {
                break;
            }
            sleep(1);
        } while (true);

        return response()->json(['task' => null]);
    }

    /** Resultat d'una feina que l'agent ha executat a la instància. */
    public function result(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $validated = $request->validate([
            'task_id' => ['required'],
            'exit_code' => ['required', 'integer'],
            'output' => ['nullable', 'string'],
            'duration_ms' => ['nullable', 'integer'],
            'timed_out' => ['nullable', 'boolean'],
        ]);

        // L'identificador d'un check és "check:<id>"; el d'una acció és el seu id enter.
        if (preg_match('/^check:(\d+)$/', (string) $validated['task_id'], $m)) {
            return $this->recordCheckResult($student, (int) $m[1], $validated);
        }
        if (! ctype_digit((string) $validated['task_id'])) {
            return response()->json(['error' => 'Identificador de feina no vàlid'], 422);
        }

        return $this->recordActionResult($student, (int) $validated['task_id'], $validated);
    }

    /** Pròxima acció pendent d'aquest rol; la marca recollida i la lliga a la instància. */
    private function nextAction(Student $student, AgentInstance $instance): ?array
    {
        $task = ActionRun::query()
            ->where('alumne_id', $student->id)
            ->where('rol_agent', $instance->hostname)
            ->where('estat', ActionStatus::Pendent)
            ->whereNull('recollit_a')
            ->where('caduca_a', '>', now())
            ->orderBy('data_inici')
            ->first();
        if ($task === null) {
            return null;
        }

        $task->update(['recollit_a' => now(), 'instancia_id' => $instance->id]);

        return $this->taskPayload($task->id, $task->payload_agent ?? []);
    }

    /** Pròxim check pendent d'aquest rol; el marca enviat i el lliga a la instància. */
    private function nextCheck(Student $student, AgentInstance $instance): ?array
    {
        $feina = AgentTask::query()
            ->where('alumne_id', $student->id)
            ->where('rol_agent', $instance->hostname)
            ->where('estat', 'pendent')
            ->whereNull('recollit_a')
            ->where(fn ($q) => $q->whereNull('caduca_a')->orWhere('caduca_a', '>', now()))
            ->orderBy('created_at')
            ->first();
        if ($feina === null) {
            return null;
        }

        $feina->update(['estat' => 'enviada', 'recollit_a' => now(), 'instancia_id' => $instance->id]);

        return $this->taskPayload('check:'.$feina->id, $feina->payload_agent ?? []);
    }

    /** Pròxima sessió de consola pendent d'aquest rol; la marca activa i l'obre. */
    private function nextConsole(Student $student, AgentInstance $instance): ?array
    {
        $session = ConsoleSession::query()
            ->where('alumne_id', $student->id)
            ->where('rol_agent', $instance->hostname)
            ->where('estat', 'pendent')
            ->where(fn ($q) => $q->whereNull('caduca_a')->orWhere('caduca_a', '>', now()))
            ->orderBy('created_at')
            ->first();
        if ($session === null) {
            return null;
        }

        $session->update([
            'estat' => 'activa',
            'instancia_id' => $instance->id,
            'data_inici' => now(),
            'darrera_activitat' => now(),
        ]);

        return ['id' => 'console:'.$session->id, 'console' => true];
    }

    /** @param  array<string, mixed>  $payload */
    private function taskPayload(int|string $id, array $payload): array
    {
        return [
            'id' => $id,
            'code' => $payload['code'] ?? '',
            'env' => (object) ($payload['env'] ?? []),
            'timeout' => (int) ($payload['timeout'] ?? 60),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function recordActionResult(Student $student, int $taskId, array $data): JsonResponse
    {
        $run = ActionRun::query()
            ->where('id', $taskId)
            ->where('alumne_id', $student->id)
            ->where('estat', ActionStatus::Pendent)
            ->whereNotNull('recollit_a')
            ->first();
        if ($run === null) {
            return response()->json(['error' => 'La feina no existeix o ja no espera resultat'], 409);
        }

        $exit = (int) $data['exit_code'];
        $output = (string) ($data['output'] ?? '');
        $run->update([
            'estat' => $this->actionStatusFor($exit, $output, (bool) ($data['timed_out'] ?? false)),
            'ok' => $exit === 0 ? 1 : 0,
            'failed' => $exit === 0 ? 0 : 1,
            'sortida' => $this->maskedOutput($output, $run->mascara_agent ?? []),
            'data_fi' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /** @param  array<string, mixed>  $data */
    private function recordCheckResult(Student $student, int $feinaId, array $data): JsonResponse
    {
        $feina = AgentTask::query()
            ->where('id', $feinaId)
            ->where('alumne_id', $student->id)
            ->where('estat', 'enviada')
            ->whereNotNull('recollit_a')
            ->first();
        if ($feina === null) {
            return response()->json(['error' => 'La feina no existeix o ja no espera resultat'], 409);
        }

        $result = $this->checkResultFrom(
            (int) $data['exit_code'],
            (string) ($data['output'] ?? ''),
            (bool) ($data['timed_out'] ?? false),
            (int) ($data['duration_ms'] ?? 0),
        )->masked($feina->mascara_agent ?? []);

        $this->recorder->record($feina->batch, $feina->check, $student, $result, $feina->checkVersion);
        $feina->update(['estat' => 'feta']);
        $feina->batch->refreshTotals();

        return response()->json(['ok' => true]);
    }

    /**
     * Converteix la sortida de l'agent en un CheckResult amb els mateixos criteris que una execució
     * local: temps màxim → Timeout; línia HEIMDEYE_OMES → Skipped; exit 0 → Pass; si no → Fail.
     */
    private function checkResultFrom(int $exit, string $output, bool $timedOut, int $durationMs): CheckResult
    {
        $output = trim($output);
        if ($timedOut) {
            return new CheckResult(CheckStatus::Timeout, 'Ha superat el temps màxim', $this->limit($output), null, $durationMs);
        }

        $status = $exit === 0 ? CheckStatus::Pass : CheckStatus::Fail;

        return (new CheckResult($status, $this->message($output), $this->limit($output), $exit, $durationMs))->skippedIfAsked();
    }

    /** @return array<string, string|null> */
    private function validateInstance(Request $request): array
    {
        return $request->validate([
            'hostname' => ['required', 'string', 'max:255'],
            'ip_publica' => ['nullable', 'string', 'max:255'],
            'ip_privada' => ['nullable', 'string', 'max:255'],
            'sistema' => ['nullable', 'string', 'max:255'],
            'versio' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** @param  array<string, string|null>  $data */
    private function touchInstance(Student $student, array $data): AgentInstance
    {
        $instance = AgentInstance::firstOrNew([
            'alumne_id' => $student->id,
            'hostname' => $data['hostname'],
        ]);
        if (! $instance->exists) {
            $instance->registrat_a = now();
        }
        $instance->fill([
            'ip_publica' => $data['ip_publica'] ?? $instance->ip_publica,
            'ip_privada' => $data['ip_privada'] ?? $instance->ip_privada,
            'sistema' => $data['sistema'] ?? $instance->sistema,
            'versio_agent' => $data['versio'] ?? $instance->versio_agent,
            'estat' => 'activa',
            'darrer_batec' => now(),
        ])->save();

        return $instance;
    }

    /** Marca caducades les feines (accions i checks) que ningú no ha recollit a temps. */
    private function expireOverdue(): void
    {
        ActionRun::query()
            ->where('estat', ActionStatus::Pendent)
            ->whereNull('recollit_a')
            ->whereNotNull('caduca_a')
            ->where('caduca_a', '<=', now())
            ->update(['estat' => ActionStatus::Caducada, 'data_fi' => now()]);

        AgentTask::query()
            ->where('estat', 'pendent')
            ->whereNull('recollit_a')
            ->whereNotNull('caduca_a')
            ->where('caduca_a', '<=', now())
            ->get()
            ->each(fn (AgentTask $feina) => $this->expireCheck($feina));

        // Una consola que ningú no ha recollit a temps es tanca.
        ConsoleSession::query()
            ->where('estat', 'pendent')
            ->whereNotNull('caduca_a')
            ->where('caduca_a', '<=', now())
            ->update(['estat' => 'tancada', 'data_fi' => now()]);
    }

    /** Un check que ningú no ha recollit a temps es desa com a "no disponible" i es tanca. */
    private function expireCheck(AgentTask $feina): void
    {
        $this->recorder->record(
            $feina->batch,
            $feina->check,
            $feina->student,
            new CheckResult(CheckStatus::Unavailable, 'Cap instància no ha recollit el check a temps'),
            $feina->checkVersion,
        );
        $feina->update(['estat' => 'caducada']);
        $feina->batch->refreshTotals();
    }

    /** Una línia HEIMDEYE_OMES fa que l'acció sigui "no disponible"; si no, el temps o l'exit code manen. */
    private function actionStatusFor(int $exit, string $output, bool $timedOut): ActionStatus
    {
        if ($timedOut) {
            return ActionStatus::Timeout;
        }
        if (preg_match('/^HEIMDEYE_OMES:/m', $output)) {
            return ActionStatus::Unavailable;
        }

        return $exit === 0 ? ActionStatus::Ok : ActionStatus::Failed;
    }

    /**
     * Missatge de la feina: la línia HEIMDEYE_MSG:, o si no n'hi ha, la primera línia no buida.
     */
    private function message(string $output): ?string
    {
        if (preg_match('/^HEIMDEYE_MSG:\s*(.+)$/m', $output, $m)) {
            return mb_substr(trim($m[1]), 0, 255);
        }
        foreach (preg_split('/\R/', $output) as $line) {
            if (trim($line) !== '') {
                return mb_substr(trim($line), 0, 255);
            }
        }

        return null;
    }

    /** @param  list<string>  $mask */
    private function maskedOutput(string $output, array $mask): ?string
    {
        return $this->limit(Masker::mask(trim($output), $mask) ?? '');
    }

    private function limit(string $output): ?string
    {
        $output = trim($output);
        if ($output === '') {
            return null;
        }

        return mb_strcut($output, 0, (int) config('heimdeye.runner.output_limit', 8192));
    }

    private function student(Request $request): Student
    {
        return $request->attributes->get('agent_student');
    }
}
