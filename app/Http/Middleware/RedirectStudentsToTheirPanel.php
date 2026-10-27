<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'Authenticate del panell dels professors, però un alumne que hi arriba (p. ex. des de /) va al
 * seu panell, /alumne, en lloc de rebre un 403. Ha de ser el middleware d'autenticació: Laravel
 * posa els d'autenticació davant dels altres.
 */
class RedirectStudentsToTheirPanel extends Authenticate
{
    public function handle($request, Closure $next, ...$guards): Response
    {
        if (Auth::user()?->rol === Role::Student) {
            return redirect('/alumne');
        }

        return parent::handle($request, $next, ...$guards);
    }
}
