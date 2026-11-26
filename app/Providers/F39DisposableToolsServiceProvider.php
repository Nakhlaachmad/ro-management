<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\FP\DocumentOperationDiagnostics;
use App\Filament\Pages\FP\DocumentOperationsStatus;
use App\Infrastructure\FP\NextcloudRevisionStorage;
use App\Infrastructure\FP\NextcloudWebDavClient;
use Filament\Facades\Filament;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/** Explicitly registered only by an operator-owned disposable acceptance runtime. */
final class F39DisposableToolsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $identity = DB::selectOne('SELECT current_database() db,host(inet_server_addr()) host,inet_server_port() port,(SELECT system_identifier::text FROM pg_control_system()) cluster');
        if (! $this->app->environment('local') || config('nextcloud.enabled') !== false
            || ! preg_match('/\Afp_f39_stage68_[0-9]+\z/', $identity->db)
            || $identity->cluster !== '7694154787604477244'
            || $identity->host !== '127.0.0.1' || $identity->port !== 55432) {
            throw new \LogicException('F39 verified disposable runtime required');
        }
        $this->app->bind(NextcloudRevisionStorage::class,
            fn () => new NextcloudRevisionStorage(NextcloudWebDavClient::configured(healthCheckOnly: true)));
        Filament::getPanel('fp-dev')->pages([DocumentOperationsStatus::class])
            ->renderHook(PanelsRenderHook::TOPBAR_END, fn () => DocumentOperationDiagnostics::allowed(auth()->user())
                ? view('filament.fp.document-operations-nav') : '');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/f39-disposable.php'));
    }
}
