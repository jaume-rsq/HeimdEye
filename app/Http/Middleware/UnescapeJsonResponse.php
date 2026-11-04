<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fa que el JSON de l'API de l'agent no escapi els accents (à, è, ò...): els deixa tal qual en
 * comptes de "à", que és vàlid però il·legible quan es mira amb curl. Es posa al davant del
 * grup de rutes de l'agent, així que també afecta les respostes dels altres middlewares (p. ex.
 * el 401 d'AuthenticateAgent). Els agents ho llegeixen igual: és el mateix JSON.
 */
class UnescapeJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response->setEncodingOptions($response->getEncodingOptions() | JSON_UNESCAPED_UNICODE);
        }

        return $response;
    }
}
