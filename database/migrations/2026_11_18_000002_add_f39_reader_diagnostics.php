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

        $identity = DB::selectOne('SELECT current_database() db,(SELECT system_identifier::text FROM pg_control_system()) cluster');
        if (DB::connection()->getDriverName() !== 'pgsql' || DB::transactionLevel() < 1
            || ! preg_match('/\Afp_f39_stage68_[0-9]+\z/', $identity->db)
            || $identity->cluster !== '7694154787604477244') {
            throw new LogicException('Verified F39 disposable transaction required');
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE public.fp_document_operation_events DROP CONSTRAINT fp_document_operation_events_action_check;
ALTER TABLE public.fp_document_operation_events ADD CONSTRAINT fp_document_operation_events_action_check
 CHECK(action IN ('CREATED','CLAIMED','TRANSITION','DEFERRED','RECONCILED'));
-- Application role receives read access only on the additive journal objects.
-- Existing business role contracts and existing object privileges are unchanged.
GRANT SELECT ON public.fp_document_operations,public.fp_document_operation_events TO fp_dev_app;
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Preserve append-only reconciliation evidence');
    }
};
