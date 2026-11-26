<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\FP\DocumentStorageStatus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class F39StorageStatusTest extends TestCase
{
    public function test_only_active_admin_can_inspect_storage_and_direct_actions_are_authorized(): void
    {
        config(['fp.enabled' => true, 'fp.core_schema_only' => false]);
        foreach (['hrd', 'ro', 'bm'] as $role) {
            auth()->setUser(\Tests\VictoryAccess\RoleMatrixTest::actor([$role]));
            self::assertFalse(DocumentStorageStatus::canAccess());
            try {
                (new DocumentStorageStatus)->checkConnection();
                self::fail('Non-admin health check accepted');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                self::assertSame(403, $error->getStatusCode());
            }
        }
        auth()->forgetUser();
        self::assertFalse(DocumentStorageStatus::canAccess());
        Http::assertNothingSent();
    }

    public function test_missing_credentials_prevent_even_admin_network_requests(): void
    {
        config(['fp.enabled' => true, 'fp.core_schema_only' => false, 'nextcloud.enabled' => false, 'nextcloud.username' => '', 'nextcloud.app_password' => '']);
        auth()->setUser(\Tests\VictoryAccess\RoleMatrixTest::actor(['admin']));
        self::assertTrue(DocumentStorageStatus::canAccess());
        $page = new DocumentStorageStatus;
        $page->checkConnection();
        self::assertSame('CONFIGURATION_REQUIRED', $page->connectionStatus);
        Http::assertNothingSent();
    }

    public function test_authentication_failure_never_reaches_livewire_as_upstream_content(): void
    {
        config(['fp.enabled' => true, 'fp.core_schema_only' => false, 'nextcloud' => [
            'enabled' => true, 'base_url' => 'https://storage.invalid', 'username' => 'test',
            'app_password' => 'synthetic-test-secret', 'root_folder' => 'Victory-FP-Management',
        ]]);
        auth()->setUser(\Tests\VictoryAccess\RoleMatrixTest::actor(['admin']));
        Http::fake(['*' => Http::response('private upstream content', 401)]);
        $page = new DocumentStorageStatus;
        $page->checkConnection();
        self::assertSame('AUTHENTICATION_FAILED', $page->connectionStatus);
        self::assertStringNotContainsString('secret', json_encode(\Livewire\Drawer\Utils::getPublicPropertiesDefinedOnSubclass($page)));
    }
}
