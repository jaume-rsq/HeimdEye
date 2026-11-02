<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mode plantilla (HEIMDEYE_AUTH=plantilla): la còpia d'un sol professor, p. ex. un escriptori
 * d'IsardVDI clonat. Entra directament com l'administrador de la configuració, sense contrasenya.
 * Només és segur si l'app no és accessible des de fora de la màquina (vegeu docs/demo-smix2.md).
 */
class LogInTemplateAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('heimdeye.auth.mode') !== 'plantilla' || Auth::check()) {
            return $next($request);
        }

        $email = strtolower((string) config('heimdeye.admin.email'));
        $user = User::whereRaw('lower(email) = ?', [$email])->where('es_actiu', true)->first();
        abort_if($user === null, 403, "No hi ha cap administrador {$email}: cal HEIMDEYE_ADMIN_EMAIL i el seeder");
        Auth::login($user);

        return $next($request);
    }
}
