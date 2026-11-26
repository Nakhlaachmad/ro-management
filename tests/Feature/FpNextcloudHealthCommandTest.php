<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class FpNextcloudHealthCommandTest extends TestCase
{
    private const COLLECTION = '<d:multistatus xmlns:d="DAV:"><d:response><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';

    private function configureNextcloud(bool $enabled = false): void
    {
        config(['nextcloud' => [
            'enabled' => $enabled,
            'base_url' => 'https://storage.invalid',
            'username' => 'synthetic-user',
            'app_password' => 'synthetic-password',
            'root_folder' => 'F39-Health',
        ]]);
    }

    public function test_connected_health_check_succeeds_without_enabling_writes(): void
    {
        $this->configureNextcloud();
        Http::fake(['*' => Http::response(self::COLLECTION, 207)]);

        $this->artisan('fp:nextcloud-health')
            ->expectsOutput('CONNECTED')
            ->assertExitCode(0);

        self::assertFalse(config('nextcloud.enabled'));
        Http::assertSentCount(1);
    }

    public function test_missing_credentials_fail_before_network_io(): void
    {
        $this->configureNextcloud();
        config(['nextcloud.username' => '', 'nextcloud.app_password' => '']);

        $this->artisan('fp:nextcloud-health')
            ->expectsOutput('CONFIGURATION_REQUIRED')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_authentication_failure_is_sanitized(): void
    {
        $this->configureNextcloud();
        Http::fake(['*' => Http::response('synthetic-password upstream body', 401)]);

        $this->artisan('fp:nextcloud-health')
            ->expectsOutput('AUTHENTICATION_FAILED')
            ->assertExitCode(1);
    }
}
