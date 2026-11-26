<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public const SQL = <<<'SQL'
CREATE TABLE public.team_profiles (
 team_id bigint CONSTRAINT f2_team_profiles_pk PRIMARY KEY,
 uuid uuid NOT NULL DEFAULT pg_catalog.gen_random_uuid() CONSTRAINT f2_team_profiles_uuid_uq UNIQUE,
 display_name varchar(255) NOT NULL,
 code varchar(64) CONSTRAINT f2_team_profiles_code_uq UNIQUE,
 is_active boolean NOT NULL DEFAULT false,
 leader_fp_id bigint,
 created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT f2_team_profiles_team_fk FOREIGN KEY (team_id) REFERENCES public.teams(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT f2_team_profiles_leader_fk FOREIGN KEY (team_id,leader_fp_id) REFERENCES public.financial_planners(team_id,active_leader_key) ON DELETE RESTRICT ON UPDATE NO ACTION DEFERRABLE INITIALLY DEFERRED,
 CONSTRAINT f2_team_profiles_name_ck CHECK (length(trim(display_name)) > 0)
);
CREATE INDEX f2_team_profiles_active_ix ON public.team_profiles(is_active,display_name);
CREATE INDEX f2_team_profiles_leader_ix ON public.team_profiles(leader_fp_id);
-- A NEW membership's Team must have an explicitly created profile. No profiles are backfilled.
ALTER TABLE public.financial_planners ADD CONSTRAINT f2_financial_planners_team_profile_fk
 FOREIGN KEY (team_id) REFERENCES public.team_profiles(team_id) ON DELETE RESTRICT ON UPDATE RESTRICT;
SQL;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('F2 core requires PostgreSQL; no SQL executed.');
        }

        DB::unprepared(self::SQL);
    }

    public function down(): void
    {
        throw new LogicException('F2 core rollback is disabled; use a separately reviewed forward migration.');
    }
};
