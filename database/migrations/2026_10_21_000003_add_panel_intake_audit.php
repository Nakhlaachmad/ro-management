<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public const SQL = <<<'SQL'
CREATE TABLE public.fp_panel_intakes (
 submission_id bigint PRIMARY KEY REFERENCES public.fp_submissions(id) ON DELETE RESTRICT,
 actor_id bigint NOT NULL REFERENCES public.users(id),created_at timestamptz NOT NULL DEFAULT transaction_timestamp()
);
CREATE TRIGGER f37_panel_intake_immutable BEFORE UPDATE OR DELETE ON public.fp_panel_intakes FOR EACH ROW EXECUTE FUNCTION public.f37_append_only();
CREATE FUNCTION public.fp_record_panel_intake(actor bigint,submission bigint) RETURNS bigint LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $f$
BEGIN
 IF NOT public.f37_operational_actor(actor) THEN RAISE EXCEPTION 'F37 operational actor required' USING ERRCODE='42501'; END IF;
 IF EXISTS(SELECT 1 FROM public.fp_panel_intakes WHERE submission_id=submission AND actor_id=actor) THEN RETURN submission; END IF;
 PERFORM id FROM public.fp_submissions WHERE id=submission AND status='PENDING' FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'F37 panel intake requires PENDING submission' USING ERRCODE='23514'; END IF;
 INSERT INTO public.fp_panel_intakes(submission_id,actor_id) VALUES(submission,actor);
 RETURN submission;
END;$f$;
REVOKE ALL ON TABLE public.fp_panel_intakes FROM PUBLIC;
REVOKE ALL ON FUNCTION public.fp_record_panel_intake(bigint,bigint) FROM PUBLIC;
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
        throw new LogicException('Preserve append-only panel intake audit.');
    }
};
