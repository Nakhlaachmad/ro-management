<?php

declare(strict_types=1);

namespace Tests\FpStorage;

use PHPUnit\Framework\TestCase;

final class FpStorageAdapterConfigTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $original = [];

    private const KEYS = [
        'APP_ENV', 'FP_ENABLED', 'FP_STORAGE_ADAPTER',
        'NEXTCLOUD_BASE_URL', 'NEXTCLOUD_USERNAME', 'NEXTCLOUD_APP_PASSWORD', 'NEXTCLOUD_ROOT_FOLDER',
        'FP_NEXTCLOUD_ENDPOINT', 'FP_NEXTCLOUD_USER', 'FP_NEXTCLOUD_PASSWORD',
    ];

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->original[$key] = $_ENV[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            $value = $this->original[$key];
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
    }

    /** @return array<string, mixed> */
    private function loadConfig(array $values): array
    {
        foreach (self::KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        foreach ($values as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        return require dirname(__DIR__, 2).'/config/fp.php';
    }

    public function test_storage_adapter_defaults_to_local(): void
    {
        $config = $this->loadConfig(['APP_ENV' => 'local', 'FP_ENABLED' => 'true']);

        self::assertSame('local', $config['storage_adapter']);
    }

    public function test_nextcloud_adapter_reuses_shared_service_account_configuration(): void
    {
        $config = $this->loadConfig([
            'APP_ENV' => 'local',
            'FP_ENABLED' => 'true',
            'FP_STORAGE_ADAPTER' => 'nextcloud',
            'NEXTCLOUD_BASE_URL' => 'https://cloud.example',
            'NEXTCLOUD_USERNAME' => 'service-user',
            'NEXTCLOUD_APP_PASSWORD' => 'synthetic-password',
            'NEXTCLOUD_ROOT_FOLDER' => 'Victory-FP-Management',
        ]);

        self::assertSame('nextcloud', $config['storage_adapter']);
        self::assertSame('https://cloud.example/remote.php/dav/files/service-user/Victory-FP-Management', $config['nextcloud']['endpoint']);
        self::assertSame('service-user', $config['nextcloud']['user']);
        self::assertSame('synthetic-password', $config['nextcloud']['password']);
    }

    public function test_legacy_overrides_are_preserved(): void
    {
        $config = $this->loadConfig([
            'APP_ENV' => 'local',
            'FP_ENABLED' => 'true',
            'FP_STORAGE_ADAPTER' => 'nextcloud',
            'FP_NEXTCLOUD_ENDPOINT' => 'https://legacy.example/dav',
            'FP_NEXTCLOUD_USER' => 'legacy-user',
            'FP_NEXTCLOUD_PASSWORD' => 'legacy-password',
        ]);

        self::assertSame('https://legacy.example/dav', $config['nextcloud']['endpoint']);
        self::assertSame('legacy-user', $config['nextcloud']['user']);
        self::assertSame('legacy-password', $config['nextcloud']['password']);
    }
}
