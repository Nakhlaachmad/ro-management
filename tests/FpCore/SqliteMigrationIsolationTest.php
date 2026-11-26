<?php

declare(strict_types=1);

namespace Tests\FpCore;

use PHPUnit\Framework\TestCase;

final class SqliteMigrationIsolationTest extends TestCase
{
    public function test_postgres_only_migrations_skip_only_in_testing(): void
    {
        $root = dirname(__DIR__, 2);
        $files = glob($root.'/database/migrations/*.php');
        self::assertNotFalse($files);
        self::assertNotEmpty($files);

        $checked = 0;
        foreach ($files as $file) {
            $code = file_get_contents($file);
            self::assertNotFalse($code, $file);

            if (! str_contains($code, "getDriverName() !== 'pgsql'")) {
                continue;
            }

            $checked++;
            self::assertStringContainsString('FP_SQLITE_TEST_SKIP', $code, $file);
            self::assertStringContainsString("&& app()->environment('testing')", $code, $file);
            self::assertStringContainsString("getDriverName() !== 'pgsql'", $code, $file);
        }

        self::assertGreaterThanOrEqual(17, $checked);
    }
}
