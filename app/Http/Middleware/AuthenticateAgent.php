<?php

namespace App\Http\Middleware;

use App\Models\Student;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica l'agent d'una instància pel token de l'alumne (capçalera "Authorization: Bearer ...").
 *
 * El token el genera i el revoca el professor. A Cloudflare Access cal una regla de Bypass per a
 * /api/agent/*, perquè l'agent no passa pel login de Google; aquí és el token qui identifica l'alumne.
 */
class AuthenticateAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $student = $token ? Student::query()->where('token_agent', $token)->first() : null;

        if ($student === null || ! $student->es_actiu) {
            return response()->json(['error' => 'Token invàlid o alumne inactiu'], 401);
        }

        $request->attributes->set('agent_student', $student);

        return $next($request);
    }
}
