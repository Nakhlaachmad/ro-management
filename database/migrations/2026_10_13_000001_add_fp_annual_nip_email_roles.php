<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // FP_SQLITE_TEST_SKIP: SQLite is only for isolated HTTP characterization tests.
        if (DB::connection()->getDriverName() !== 'pgsql' && app()->environment('testing')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('FP annual NIP requires PostgreSQL');
        }
        DB::unprepared(file_get_contents(database_path('sql/2026_10_13_fp_annual_email_role.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('Non-destructive forward migration only; issued NIPs and audit history must be preserved.');
    }
};
