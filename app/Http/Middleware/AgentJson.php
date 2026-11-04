<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'API de l'agent és sempre JSON. Aquest middleware, al davant del grup de rutes /api/agent/*:
 *
 * - força que la petició "esperi JSON", així els errors (validació, autenticació...) tornen en JSON
 *   i no com una redirecció HTML, encara que el client no enviï "Accept: application/json" (p. ex.
 *   una prova ràpida amb curl);
 * - fa que el JSON no escapi els accents (à, è, ò...): els deixa tal qual en comptes de "à",
 *   vàlid però il·legible. També afecta les respostes dels altres middlewares (p. ex. el 401
 *   d'AuthenticateAgent), perquè els embolcalla.
 *
 * Els agents ho llegeixen igual: és el mateix JSON.
 */
class AgentJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response->setEncodingOptions($response->getEncodingOptions() | JSON_UNESCAPED_UNICODE);
        }

        return $response;
    }
}
