<?php

declare(strict_types=1);

namespace App\Filament\Pages\FP;

use App\Application\FP\FeatureAccess;
use App\Application\FP\VictoryAccess;
use App\Infrastructure\FP\NextcloudWebDavClient;
use Filament\Pages\Page;

final class DocumentStorageStatus extends Page
{
    protected static ?string $slug = 'document-storage';

    protected static bool $isDiscovered = false;

    protected static ?string $title = 'Penyimpanan Dokumen';

    protected static string $view = 'filament.fp.document-storage-status';

    public string $connectionStatus = 'NOT_CHECKED';

    public static function canAccess(): bool
    {
        return FeatureAccess::enabled() && VictoryAccess::role(auth()->user()) === 'admin';
    }

    public function checkConnection(): void
    {
        abort_unless(self::canAccess(), 403);
        $actor = (string) auth()->id();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts('f39-health:'.$actor, 5)) {
            $this->connectionStatus = 'RATE_LIMITED';

            return;
        }
        \Illuminate\Support\Facades\RateLimiter::hit('f39-health:'.$actor, 60);
        try {
            $this->connectionStatus = NextcloudWebDavClient::configured(healthCheckOnly: true)->health();
        } catch (\RuntimeException $error) {
            $this->connectionStatus = match ($error->getMessage()) {
                'NEXTCLOUD_DISABLED' => 'DISABLED',
                'NEXTCLOUD_AUTHENTICATION_FAILED' => 'AUTHENTICATION_FAILED',
                'NEXTCLOUD_CONFIGURATION_INVALID' => 'CONFIGURATION_REQUIRED',
                default => 'STORAGE_UNAVAILABLE',
            };
        }
    }
}
