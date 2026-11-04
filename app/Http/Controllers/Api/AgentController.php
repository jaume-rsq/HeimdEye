<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActionStatus;
use App\Models\ActionRun;
use App\Models\AgentInstance;
use App\Models\Student;
use App\Runner\Masker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API que fa servir l'agent instal·lat a la instància de l'alumne (model pull).
 *
 * L'alumne s'autentica amb el seu token (middleware AuthenticateAgent). El hostname identifica
 * la instància i fa de rol per a la capçalera "# hosts:" de les accions.
 */
class AgentController
{
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

    /** Long poll: retorna la feina pendent d'aquesta instància, o null si no n'hi ha. */
    public function poll(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $data = $this->validateInstance($request);
        $instance = $this->touchInstance($student, $data);

        $hold = (int) config('heimdeye.agent.poll_hold_seconds', 25);
        $deadline = microtime(true) + $hold;
        do {
            $this->expireOverdue();
            $task = ActionRun::query()
                ->where('alumne_id', $student->id)
                ->where('rol_agent', $instance->hostname)
                ->where('estat', ActionStatus::Pendent)
                ->whereNull('recollit_a')
                ->where('caduca_a', '>', now())
                ->orderBy('data_inici')
                ->first();

            if ($task !== null) {
                $task->update(['recollit_a' => now(), 'instancia_id' => $instance->id]);
                $payload = $task->payload_agent ?? [];

                return response()->json(['task' => [
                    'id' => $task->id,
                    'code' => $payload['code'] ?? '',
                    'env' => (object) ($payload['env'] ?? []),
                    'timeout' => (int) ($payload['timeout'] ?? 60),
                ]]);
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
            'task_id' => ['required', 'integer'],
            'exit_code' => ['required', 'integer'],
            'output' => ['nullable', 'string'],
        ]);

        $run = ActionRun::query()
            ->where('id', $validated['task_id'])
            ->where('alumne_id', $student->id)
            ->where('estat', ActionStatus::Pendent)
            ->whereNotNull('recollit_a')
            ->first();

        if ($run === null) {
            return response()->json(['error' => 'La feina no existeix o ja no espera resultat'], 409);
        }

        $exit = (int) $validated['exit_code'];
        $output = (string) ($validated['output'] ?? '');
        $run->update([
            'estat' => $this->statusFor($exit, $output),
            'ok' => $exit === 0 ? 1 : 0,
            'failed' => $exit === 0 ? 0 : 1,
            'sortida' => $this->maskedOutput($output, $run->mascara_agent ?? []),
            'data_fi' => now(),
        ]);

        return response()->json(['ok' => true]);
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

    /** Marca caducades les feines que ningú no ha recollit a temps. */
    private function expireOverdue(): void
    {
        ActionRun::query()
            ->where('estat', ActionStatus::Pendent)
            ->whereNull('recollit_a')
            ->whereNotNull('caduca_a')
            ->where('caduca_a', '<=', now())
            ->update(['estat' => ActionStatus::Caducada, 'data_fi' => now()]);
    }

    /** Una línia HEIMDEYE_OMES fa que la feina sigui "no disponible"; si no, l'exit code mana. */
    private function statusFor(int $exit, string $output): ActionStatus
    {
        if (preg_match('/^HEIMDEYE_OMES:/m', $output)) {
            return ActionStatus::Unavailable;
        }

        return $exit === 0 ? ActionStatus::Ok : ActionStatus::Failed;
    }

    /** @param  list<string>  $mask */
    private function maskedOutput(string $output, array $mask): ?string
    {
        $masked = Masker::mask(trim($output), $mask);
        if ($masked === null || $masked === '') {
            return null;
        }

        return mb_strcut($masked, 0, (int) config('heimdeye.runner.output_limit', 8192));
    }

    private function student(Request $request): Student
    {
        return $request->attributes->get('agent_student');
    }
}
