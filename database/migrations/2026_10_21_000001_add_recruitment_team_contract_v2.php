<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public const SQL = <<<'SQL'
ALTER TABLE public.financial_planners
 ADD COLUMN recruitment_contract_version smallint NOT NULL DEFAULT 1,
 ADD COLUMN recruitment_team_id bigint,
 ADD COLUMN recruitment_team_name_snapshot varchar(255),
 ADD CONSTRAINT f37_recruitment_version_ck CHECK (recruitment_contract_version IN (1,2)),
 ADD CONSTRAINT f37_recruitment_team_fk FOREIGN KEY (recruitment_team_id) REFERENCES public.team_profiles(team_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
 ADD CONSTRAINT f37_recruitment_contract_ck CHECK (
  (recruitment_contract_version=1 AND recruitment_team_id IS NULL AND recruitment_team_name_snapshot IS NULL)
  OR (recruitment_contract_version=2 AND origin='NEW' AND recruitment_team_id IS NOT NULL AND recruitment_team_name_snapshot IS NOT NULL AND length(btrim(recruitment_team_name_snapshot))>0
   AND recruitment_path_id IS NULL AND recruitment_path_code_snapshot IS NULL AND recruitment_path_name_snapshot IS NULL));
CREATE INDEX f37_recruitment_team_idx ON public.financial_planners(recruitment_team_id);
CREATE OR REPLACE FUNCTION public.f3_membership_path_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $f37$
DECLARE path public.fp_recruitment_paths%ROWTYPE; profile public.team_profiles%ROWTYPE;
BEGIN
 IF TG_OP='UPDATE' THEN
  IF ROW(NEW.recruitment_contract_version,NEW.recruitment_team_id,NEW.recruitment_team_name_snapshot,
    NEW.recruitment_path_id,NEW.recruitment_path_code_snapshot,NEW.recruitment_path_name_snapshot)
   IS DISTINCT FROM ROW(OLD.recruitment_contract_version,OLD.recruitment_team_id,OLD.recruitment_team_name_snapshot,
    OLD.recruitment_path_id,OLD.recruitment_path_code_snapshot,OLD.recruitment_path_name_snapshot)
  THEN RAISE EXCEPTION 'F37 approval recruitment snapshot immutable' USING ERRCODE='23514'; END IF;
 ELSIF NEW.recruitment_contract_version=2 THEN
  SELECT * INTO profile FROM public.team_profiles WHERE team_id=NEW.recruitment_team_id AND is_active FOR SHARE;
  IF NOT FOUND OR NEW.recruitment_team_name_snapshot IS DISTINCT FROM profile.display_name THEN
   RAISE EXCEPTION 'F37 exact active recruitment Team required' USING ERRCODE='23514'; END IF;
  IF NOT ((NEW.source_type='TEAM' AND NEW.source_team_id=NEW.recruitment_team_id AND NEW.source_fp_id IS NULL)
      OR (NEW.source_type='FP' AND NEW.source_fp_id IS NOT NULL AND NEW.source_team_id IS NULL)) THEN
   RAISE EXCEPTION 'F37 derived source binding required' USING ERRCODE='23514'; END IF;
 ELSIF NEW.origin='NEW' THEN
  SELECT * INTO path FROM public.fp_recruitment_paths WHERE id=NEW.recruitment_path_id AND is_active FOR SHARE;
  IF NOT FOUND THEN RAISE EXCEPTION 'F3 active Jalur required for approval' USING ERRCODE='23514'; END IF;
  IF ROW(NEW.recruitment_path_code_snapshot,NEW.recruitment_path_name_snapshot) IS DISTINCT FROM ROW(path.code,path.name)
  THEN RAISE EXCEPTION 'F3 exact Jalur snapshot required' USING ERRCODE='23514'; END IF;
 ELSIF NEW.recruitment_path_id IS NOT NULL THEN
  RAISE EXCEPTION 'F3 no fabricated legacy Jalur' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END;
$f37$;
REVOKE ALL ON FUNCTION public.f3_membership_path_guard() FROM PUBLIC;
SQL;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('F37 requires PostgreSQL');
        }
        DB::unprepared(self::SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reviewed forward recovery only; preserve historical source contracts.');
    }
};
