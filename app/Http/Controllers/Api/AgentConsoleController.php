<?php

namespace App\Http\Controllers\Api;

use App\Models\ConsoleMessage;
use App\Models\ConsoleSession;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API de la consola interactiva que fa servir l'agent (model pull). Un cop el long poll normal li
 * ha donat una sessió de consola, l'agent obre un bash persistent i parla amb HeimdEye per dos
 * extrems pel mateix canal de sortida HTTPS:
 *
 * - pull: long poll de l'entrada que ha escrit el professor (i avís quan la sessió es tanca);
 * - push: la sortida que va generant el bash.
 *
 * Cap port a la instància: tot surt de l'agent cap a HeimdEye. Tot queda registrat (auditable).
 */
class AgentConsoleController
{
    /** Long poll de l'entrada pendent de la sessió; retorna també si la sessió s'ha tancat. */
    public function pull(Request $request, ConsoleSession $session): JsonResponse
    {
        $student = $this->student($request);
        if ($session->alumne_id !== $student->id) {
            return response()->json(['error' => 'La sessió no és d\'aquest alumne'], 403);
        }

        $hold = (int) config('heimdeye.agent.console_hold_seconds', 20);
        $deadline = microtime(true) + $hold;
        do {
            if (! $session->fresh()->isOpen()) {
                return response()->json(['closed' => true, 'input' => '']);
            }
            $pending = ConsoleMessage::query()
                ->where('sessio_id', $session->id)
                ->where('direccio', 'entrada')
                ->where('consumit', false)
                ->orderBy('id')
                ->get();
            if ($pending->isNotEmpty()) {
                ConsoleMessage::whereKey($pending->pluck('id'))->update(['consumit' => true]);

                return response()->json(['closed' => false, 'input' => $pending->pluck('contingut')->implode('')]);
            }

            if (microtime(true) >= $deadline || connection_aborted()) {
                break;
            }
            sleep(1);
        } while (true);

        return response()->json(['closed' => false, 'input' => '']);
    }

    /** La sortida que genera el bash de la instància; retorna si la sessió ja s'ha tancat. */
    public function push(Request $request, ConsoleSession $session): JsonResponse
    {
        $student = $this->student($request);
        if ($session->alumne_id !== $student->id) {
            return response()->json(['error' => 'La sessió no és d\'aquest alumne'], 403);
        }
        $data = $request->validate(['output' => ['required', 'string']]);

        if ($session->isOpen()) {
            if ($data['output'] !== '') {
                ConsoleMessage::create([
                    'sessio_id' => $session->id,
                    'direccio' => 'sortida',
                    'contingut' => $data['output'],
                    'consumit' => true,
                ]);
            }
            $session->update(['darrera_activitat' => now()]);
        }

        return response()->json(['closed' => ! $session->fresh()->isOpen()]);
    }

    private function student(Request $request): Student
    {
        return $request->attributes->get('agent_student');
    }
}
