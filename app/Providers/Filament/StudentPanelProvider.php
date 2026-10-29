<?php

namespace App\Providers\Filament;

use App\Filament\Student\Pages\MyProgress;
use App\Filament\Student\Pages\StudentHelp;
use App\Http\Middleware\AuthenticateWithCloudflareAccess;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panell dels alumnes (/alumne): només hi ha el seu seguiment, de només lectura.
 * S'hi entra igual que al dels professors (Cloudflare Access o, en local, el formulari).
 */
class StudentPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->id('alumne')
            ->path('alumne')
            ->brandName('HeimdEye')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->topNavigation()
            ->pages([
                MyProgress::class,
                StudentHelp::class,
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.partials.native-controls'))
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('partials.favicons'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->middleware([AuthenticateWithCloudflareAccess::class], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ]);

        if (config('heimdeye.auth.mode') !== 'cloudflare') {
            $panel->login();
        }

        return $panel;
    }
}
