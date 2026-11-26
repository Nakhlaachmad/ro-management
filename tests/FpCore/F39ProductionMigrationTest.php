<?php

declare(strict_types=1);

namespace Tests\FpCore;

use PHPUnit\Framework\TestCase;

final class F39ProductionMigrationTest extends TestCase
{
    public function test_disposable_f39_migrations_do_not_install_on_production_targets(): void
    {
        $root = dirname(__DIR__, 2);

        foreach ([
            'database/migrations/2026_11_18_000001_add_f39_document_operations.php',
            'database/migrations/2026_11_18_000002_add_f39_reader_diagnostics.php',
        ] as $relative) {
            $code = file_get_contents($root.'/'.$relative);
            self::assertNotFalse($code, $relative);
            self::assertStringContainsString('if (! $isDisposable)', $code, $relative);
            self::assertStringContainsString('2026_11_26_000001_add_f39_production_document_operations', $code, $relative);
        }
    }

    public function test_production_f39_migration_is_not_bound_to_a_disposable_database(): void
    {
        $root = dirname(__DIR__, 2);
        $relative = 'database/migrations/2026_11_26_000001_add_f39_production_document_operations.php';
        $code = file_get_contents($root.'/'.$relative);
        self::assertNotFalse($code, $relative);

        self::assertStringNotContainsString('fp_f39_stage68_', $code);
        self::assertStringNotContainsString('7694154787604477244', $code);
        self::assertStringContainsString("config('fp.db_app_role')", $code);
        self::assertStringContainsString('GRANT SELECT ON TABLE public.fp_document_operations', $code);
        self::assertStringContainsString('GRANT UPDATE (next_serial) ON TABLE public.fp_nip_year_counters', $code);
        self::assertStringContainsString('GRANT EXECUTE ON FUNCTION public.fp_victory_user_command', $code);
        self::assertStringContainsString('F39 partial schema detected', $code);

        $schemaBlockEnd = strpos($code, "SQL);\n        }");
        $grantPosition = strpos($code, 'GRANT SELECT ON TABLE public.fp_document_operations');
        self::assertNotFalse($schemaBlockEnd);
        self::assertNotFalse($grantPosition);
        self::assertGreaterThan($schemaBlockEnd, $grantPosition, 'grants must also run when the F39 schema already exists');
    }

    public function test_production_role_is_configured_and_documented(): void
    {
        $root = dirname(__DIR__, 2);
        $config = file_get_contents($root.'/config/fp.php');
        self::assertNotFalse($config);
        self::assertStringContainsString("'db_app_role'", $config);
        self::assertStringContainsString("env('FP_DB_APP_ROLE')", $config);

        foreach (['.env.example', '.env.dokploy.example'] as $relative) {
            $env = file_get_contents($root.'/'.$relative);
            self::assertNotFalse($env, $relative);
            self::assertStringContainsString('FP_DB_APP_ROLE=', $env, $relative);
        }
    }

    public function test_annual_sql_artifact_is_tracked_with_expected_hash(): void
    {
        $file = dirname(__DIR__, 2).'/database/sql/2026_10_13_fp_annual_email_role.sql';
        self::assertFileExists($file);
        self::assertSame(
            'f810c44870bad16c3d217458f82e6b15d4177f77d3372dc59913cc299b3ba413',
            strtolower((string) hash_file('sha256', $file))
        );
    }
}
