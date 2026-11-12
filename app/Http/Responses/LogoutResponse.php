<?php

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\LogoutResponse as FilamentLogoutResponse;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Amb Cloudflare Access, tancar només la sessió de Laravel no serveix: la cookie CF_Authorization
 * continua vàlida, la següent petició porta el mateix JWT i AuthenticateWithCloudflareAccess torna
 * a entrar el mateix usuari. Per això, en aquest mode, el logout acaba també la sessió d'Access.
 */
class LogoutResponse extends FilamentLogoutResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        if (config('heimdeye.auth.mode') === 'cloudflare') {
            return redirect()->to('/cdn-cgi/access/logout');
        }

        return parent::toResponse($request);
    }
}
