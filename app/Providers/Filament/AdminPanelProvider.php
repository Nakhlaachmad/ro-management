<?php

namespace App\Providers\Filament;
use App\Http\Middleware\VerifyCsrfToken;
use Filament\Navigation\MenuItem;
use Filament\View\PanelsRenderHook;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $portalUrl = rtrim(trim((string) config('services.portal_sso.issuer', '')), '/');
        $iwacsDashboardUrl = filter_var($portalUrl, FILTER_VALIDATE_URL) !== false
            ? $portalUrl.'/dashboard'
            : '/';

        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login()
            ->userMenuItems([
                // Kembali tidak mengakhiri sesi RO, sehingga pengguna dapat
                // membuka RO lagi dari dashboard IWACS tanpa login ulang.
                'back-to-iwacs' => MenuItem::make()
                    ->label('Kembali ke IWACS')
                    ->url($iwacsDashboardUrl)
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->sort(90),
                // Aksi logout bawaan Filament menggunakan RoLogoutResponse:
                // sesi RO dan IWACS dihapus melalui tiket sekali pakai.
                'logout' => MenuItem::make()
                    ->label('Logout')
                    ->url(fn (): string => route('portal.sso.global-logout'))
                    ->icon('heroicon-o-arrow-left-on-rectangle')
                    ->color('danger')
                    ->sort(100),
            ])
            ->brandLogo(asset('images/3.png'))
            ->favicon(asset('favicon.png').'?v=20260920b')
            ->darkModeBrandLogo(asset('images/4.png'))
            ->darkMode(true)
            ->brandLogoHeight('150px')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                \App\Filament\Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            // ->widgets([
            // ])
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
            ->authMiddleware([
                \Filament\Http\Middleware\Authenticate::class,
            ]);
    }
}
