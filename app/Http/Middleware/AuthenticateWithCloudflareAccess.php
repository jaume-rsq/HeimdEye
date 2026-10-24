<?php

namespace App\Http\Middleware;

use App\Auth\CloudflareAccess;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * En mode cloudflare, inicia la sessió de l'usuari a partir del JWT de Cloudflare Access.
 * Només entren els usuaris actius que ja són a la taula usuaris (no hi ha autoregistre).
 */
class AuthenticateWithCloudflareAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('heimdeye.auth.mode') !== 'cloudflare') {
            return $next($request);
        }

        $jwt = $request->header('Cf-Access-Jwt-Assertion');
        abort_if(blank($jwt), 401, 'Cal entrar a través de Cloudflare Access');

        try {
            $email = CloudflareAccess::fromConfig()->email($jwt);
        } catch (Throwable $e) {
            report($e);
            abort(401, 'El token de Cloudflare Access no és vàlid');
        }

        if (strtolower((string) Auth::user()?->email) !== $email) {
            $user = User::whereRaw('lower(email) = ?', [$email])->where('es_actiu', true)->first();
            abort_if($user === null, 403, "L'usuari {$email} no té accés a HeimdEye");
            Auth::login($user);
        }

        return $next($request);
    }
}
