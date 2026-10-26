<?php

namespace App\Providers\Filament;

use App\Filament\Pages\ComingSoon;
use App\Filament\Resources\Actions\ActionResource;
use App\Filament\Resources\Checks\CheckResource;
use App\Http\Middleware\AuthenticateWithCloudflareAccess;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('HeimdEye')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make('Checks'),
                NavigationGroup::make('Accions'),
                NavigationGroup::make('Inventari'),
                NavigationGroup::make('Administració'),
            ])
            ->navigationItems([
                NavigationItem::make('Nou check')
                    ->group('Checks')
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->url(fn () => CheckResource::getUrl('create'))
                    ->isActiveWhen(fn () => request()->routeIs(CheckResource::getRouteBaseName().'.create'))
                    ->sort(2),
                NavigationItem::make('Nova acció')
                    ->group('Accions')
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->url(fn () => ActionResource::getUrl('create'))
                    ->isActiveWhen(fn () => request()->routeIs(ActionResource::getRouteBaseName().'.create'))
                    ->sort(2),
                self::comingSoon('Gestió d\'usuaris i rols', Heroicon::OutlinedUsers, 'Administració', 1),
                self::comingSoon('CRUD taules', Heroicon::OutlinedTableCells, 'Administració', 2),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->renderHook(PanelsRenderHook::PAGE_START, fn () => view('filament.queue-warning'))
            ->renderHook(PanelsRenderHook::PAGE_END, fn () => view('filament.back-button'))
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

        // Amb Cloudflare Access el login el fa Google Workspace: no cal formulari.
        if (config('heimdeye.auth.mode') !== 'cloudflare') {
            $panel->login();
        }

        return $panel;
    }

    /** Entrada del menú d'una pantalla que encara no existeix. */
    private static function comingSoon(string $label, Heroicon $icon, ?string $group, int $sort): NavigationItem
    {
        return NavigationItem::make($label)
            ->group($group)
            ->icon($icon)
            ->badge('aviat', 'gray')
            ->url(fn () => ComingSoon::getUrl(['que' => $label]))
            ->isActiveWhen(fn () => request()->routeIs(ComingSoon::getRouteName()) && request('que') === $label)
            ->sort($sort);
    }
}
