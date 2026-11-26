<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\FP\NextcloudWebDavClient;
use Illuminate\Console\Command;
use RuntimeException;

/** Read-only DAV readiness check. Never enables writes or exposes configuration. */
final class FpNextcloudHealth extends Command
{
    protected $signature = 'fp:nextcloud-health';

    protected $description = 'Check Nextcloud DAV connectivity without enabling remote writes';

    public function handle(): int
    {
        try {
            $status = NextcloudWebDavClient::configured(healthCheckOnly: true)->health();
        } catch (RuntimeException $error) {
            $status = match ($error->getMessage()) {
                'NEXTCLOUD_DISABLED' => 'DISABLED',
                'NEXTCLOUD_AUTHENTICATION_FAILED' => 'AUTHENTICATION_FAILED',
                'NEXTCLOUD_CONFIGURATION_INVALID' => 'CONFIGURATION_REQUIRED',
                default => 'STORAGE_UNAVAILABLE',
            };
        }

        $this->line($status);

        return $status === 'CONNECTED' ? self::SUCCESS : self::FAILURE;
    }
}
