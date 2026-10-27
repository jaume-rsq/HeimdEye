<?php

namespace App\Filament\Auth;

use App\Support\Demo;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Entrada de la demo: sense contrasenya, un botó crea un professor visitant i hi entra.
 * Limitat per IP (a Cloudflare, a més, hi ha el Managed Challenge del WAF).
 */
class DemoLogin extends Login
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('Entraràs com a professor visitant dels mòduls de la demo. Pots crear i provar checks i accions contra alumnes ficticis. Tot s\'esborra cada nit: no hi posis dades reals.'),
        ]);
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(3);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        Filament::auth()->login(Demo::createVisitor());
        session()->regenerate();

        return app(LoginResponse::class);
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Demo de HeimdEye';
    }

    protected function getAuthenticateFormAction(): Action
    {
        return parent::getAuthenticateFormAction()->label('Entrar a la demo');
    }
}
