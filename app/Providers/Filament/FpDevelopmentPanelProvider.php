<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

final class FpDevelopmentPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        if (! \App\Application\FP\FeatureAccess::enabled()) {
            throw new \LogicException('FP panel requires FP_ENABLED');
        }

        return $panel->id('fp-dev')->path('fp-dev')->login(\App\Filament\FP\Login::class)->authGuard('web')
            ->brandName('Victory International Futures')
            ->brandLogo(asset('images/logolight.png'))->darkModeBrandLogo(asset('images/logodark.png'))->brandLogoHeight('10rem')->darkMode()
            ->renderHook(\Filament\View\PanelsRenderHook::TOPBAR_END, fn () => \App\Application\FP\FeatureAccess::allowed(auth()->user(), 'f2.view') ? \Illuminate\Support\Facades\Blade::render('<livewire:fp-submission-updates />') : '')
            ->renderHook(\Filament\View\PanelsRenderHook::TOPBAR_START, fn () => view('components.fp.ui.workspace'))
            ->renderHook(\Filament\View\PanelsRenderHook::TOPBAR_END, fn () => view('components.fp.ui.theme-toggle'))

            ->colors(['primary' => \Filament\Support\Colors\Color::hex('#0788D0')])
            ->renderHook(\Filament\View\PanelsRenderHook::HEAD_END, fn () => view('filament.fp.theme-head'))
            ->pages([\App\Filament\Pages\FP\Dashboard::class, \App\Filament\Pages\FP\UserStatus::class, \App\Filament\Pages\FP\Reports::class, \App\Filament\Pages\FP\DocumentStorageStatus::class])
            ->navigation(function (\Filament\Navigation\NavigationBuilder $builder) {
                $items = [];
                $fpItems = [];
                $afterFp = [];
                if (\App\Application\FP\FeatureAccess::allowed(auth()->user(), 'f2.view')) {
                    foreach ([['Dashboard', \App\Filament\Pages\FP\Dashboard::getUrl(panel: 'fp-dev'), 'heroicon-o-squares-2x2'], ['User', (\App\Application\FP\VictoryUsers::enabled() ? \App\Filament\Resources\VictoryUserResource::getUrl('index', panel: 'fp-dev') : \App\Filament\Pages\FP\UserStatus::getUrl(panel: 'fp-dev')), 'heroicon-o-users']] as [$label, $url, $icon]) {
                        $items[] = \Filament\Navigation\NavigationItem::make($label)->url($url)->icon($icon)->isActiveWhen(fn () => url()->current() === $url);
                    }
                }
                if (\App\Application\FP\FeatureAccess::allowed(auth()->user(), 'f2.teams.view')) {
                    $url = \App\Filament\Resources\FpTeamResource::getUrl('index', panel: 'fp-dev');
                    $items[] = \Filament\Navigation\NavigationItem::make('Team')->url($url)->icon('heroicon-o-user-group')->isActiveWhen(fn () => url()->current() === $url);
                }
                if (\App\Application\FP\FeatureAccess::allowed(auth()->user(), 'f2.view')) {

                    foreach (['active' => 'FP Aktif', 'exited' => 'FP Keluar', 'normal' => 'FP Keluar Normal', 'abnormal' => 'FP Keluar Tidak Normal'] as $page => $label) {
                        $url = \App\Filament\Resources\FpMembershipResource::getUrl($page, panel: 'fp-dev');
                        $fpItems[] = \Filament\Navigation\NavigationItem::make($label)->url($url)->isActiveWhen(fn () => url()->current() === $url);
                    }
                    $bankUrl = \App\Filament\Resources\FpMembershipResource::getUrl('index', panel: 'fp-dev');
                    $afterFp[] = \Filament\Navigation\NavigationItem::make('Bank FP')->url($bankUrl)->icon('heroicon-o-identification')->isActiveWhen(fn () => url()->current() === $bankUrl);
                    $url = \App\Filament\Resources\FpSubmissionResource::getUrl('index', panel: 'fp-dev');
                    $afterFp[] = \Filament\Navigation\NavigationItem::make('Validasi Pendaftar')->url($url)->icon('heroicon-o-clipboard-document-check')->isActiveWhen(fn () => str_starts_with(url()->current(), $url));
                }

                if (\App\Filament\Resources\FpRecruitmentPathResource::canViewAny()) {
                    $url = \App\Filament\Resources\FpRecruitmentPathResource::getUrl('index', panel: 'fp-dev');
                    $afterFp[] = \Filament\Navigation\NavigationItem::make('Master Sumber Rekrutmen')->url($url)->icon('heroicon-o-map')->isActiveWhen(fn () => url()->current() === $url);
                }

                if (\App\Application\FP\FpReports::allowed(auth()->user())) {
                    $url = \App\Filament\Pages\FP\Reports::getUrl(panel: 'fp-dev');
                    $afterFp[] = \Filament\Navigation\NavigationItem::make('Laporan')->url($url)->icon('heroicon-o-chart-bar')->isActiveWhen(fn () => url()->current() === $url);
                }

                if (\App\Filament\Pages\FP\DocumentStorageStatus::canAccess()) {
                    $url = \App\Filament\Pages\FP\DocumentStorageStatus::getUrl(panel: 'fp-dev');
                    $afterFp[] = \Filament\Navigation\NavigationItem::make('Penyimpanan Dokumen')->url($url)->icon('heroicon-o-cloud')->isActiveWhen(fn () => url()->current() === $url);
                }

                return $builder->items($items)->groups([
                    \Filament\Navigation\NavigationGroup::make('FP')->icon('heroicon-o-identification')->items($fpItems)->collapsible(),
                    \Filament\Navigation\NavigationGroup::make()->items($afterFp),
                ]);
            })
            ->resources([\App\Filament\Resources\FpSubmissionResource::class, \App\Filament\Resources\FpMembershipResource::class, \App\Filament\Resources\FpTeamResource::class, \App\Filament\Resources\FpRecruitmentPathResource::class, ...(\App\Application\FP\VictoryUsers::enabled() ? [\App\Filament\Resources\VictoryUserResource::class] : [])])
            ->homeUrl(fn () => \App\Filament\Pages\FP\Dashboard::getUrl(panel: 'fp-dev'))
            ->middleware([
                \App\Http\Middleware\FpEnabled::class,
                \Illuminate\Cookie\Middleware\EncryptCookies::class,
                \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
                \Illuminate\Session\Middleware\StartSession::class,
                \Filament\Http\Middleware\AuthenticateSession::class,
                \Illuminate\View\Middleware\ShareErrorsFromSession::class,
                \App\Http\Middleware\VerifyCsrfToken::class,
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
                \Filament\Http\Middleware\DisableBladeIconComponents::class,
                \Filament\Http\Middleware\DispatchServingFilamentEvent::class,
            ])->authMiddleware([\Filament\Http\Middleware\Authenticate::class]);
    }
}
