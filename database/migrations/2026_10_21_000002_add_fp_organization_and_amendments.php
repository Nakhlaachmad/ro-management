<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public const SQL = <<<'SQL'
ALTER TABLE public.team_profiles ADD COLUMN mm_fp_id bigint REFERENCES public.financial_planners(id) ON DELETE RESTRICT;
CREATE TABLE public.fp_membership_amendments (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 membership_id bigint NOT NULL REFERENCES public.financial_planners(id) ON DELETE RESTRICT,
 revision integer NOT NULL CHECK(revision>0), actor_id bigint NOT NULL REFERENCES public.users(id),
 occurred_at timestamptz NOT NULL DEFAULT transaction_timestamp(),
 before_encrypted text NOT NULL, after_encrypted text NOT NULL,
 fields jsonb NOT NULL CHECK(jsonb_typeof(fields)='array'),
 UNIQUE(membership_id,revision)
);
CREATE TABLE public.fp_membership_profiles (
 membership_id bigint PRIMARY KEY REFERENCES public.financial_planners(id) ON DELETE RESTRICT,
 revision integer NOT NULL, data jsonb NOT NULL CHECK(jsonb_typeof(data)='object'),
 FOREIGN KEY(membership_id,revision) REFERENCES public.fp_membership_amendments(membership_id,revision) DEFERRABLE INITIALLY DEFERRED
);
CREATE TABLE public.fp_team_assignments (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 membership_id bigint NOT NULL REFERENCES public.financial_planners(id) ON DELETE RESTRICT,
 team_id bigint NOT NULL REFERENCES public.team_profiles(team_id) ON DELETE RESTRICT,
 starts_at timestamptz, ends_at timestamptz, actor_id bigint REFERENCES public.users(id),
 evidence_kind text NOT NULL CHECK(evidence_kind IN('INITIAL_SNAPSHOT','TRANSFER','APPROVAL')),
 CHECK(ends_at IS NULL OR starts_at IS NULL OR ends_at>=starts_at)
);
CREATE UNIQUE INDEX f37_assignment_current_uq ON public.fp_team_assignments(membership_id) WHERE ends_at IS NULL;
CREATE INDEX f37_assignment_team_idx ON public.fp_team_assignments(team_id,membership_id);
CREATE INDEX f37_assignment_member_history_idx ON public.fp_team_assignments(membership_id,id DESC);
-- This records known current provenance; NULL start explicitly means unknown historical start.
INSERT INTO public.fp_team_assignments(membership_id,team_id,starts_at,evidence_kind)
 SELECT id,team_id,NULL,'INITIAL_SNAPSHOT' FROM public.financial_planners WHERE lifecycle_state='ACTIVE' AND team_id IS NOT NULL;
CREATE TABLE public.fp_team_offices (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 team_id bigint NOT NULL REFERENCES public.team_profiles(team_id),
 membership_id bigint NOT NULL REFERENCES public.financial_planners(id),
 office text NOT NULL CHECK(office IN('MM','LEADER')),
 starts_at timestamptz,ends_at timestamptz,actor_id bigint REFERENCES public.users(id),
 CHECK(ends_at IS NULL OR starts_at IS NULL OR ends_at>=starts_at)
);
CREATE UNIQUE INDEX f37_team_office_current_uq ON public.fp_team_offices(team_id,office) WHERE ends_at IS NULL;
CREATE UNIQUE INDEX f37_leader_one_team_uq ON public.fp_team_offices(membership_id) WHERE office='LEADER' AND ends_at IS NULL;
INSERT INTO public.fp_team_offices(team_id,membership_id,office)
 SELECT team_id,leader_fp_id,'LEADER' FROM public.team_profiles WHERE is_active AND leader_fp_id IS NOT NULL;
CREATE TABLE public.fp_team_dissolutions (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 source_team_id bigint NOT NULL UNIQUE REFERENCES public.team_profiles(team_id),
 actor_id bigint NOT NULL REFERENCES public.users(id),effective_at timestamptz NOT NULL,
 roster jsonb NOT NULL CHECK(jsonb_typeof(roster)='array')
);
CREATE TABLE public.fp_team_moves (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 dissolution_id bigint NOT NULL REFERENCES public.fp_team_dissolutions(id),
 membership_id bigint NOT NULL REFERENCES public.financial_planners(id),
 source_team_id bigint NOT NULL REFERENCES public.team_profiles(team_id),
 destination_team_id bigint NOT NULL REFERENCES public.team_profiles(team_id),
 actor_id bigint NOT NULL REFERENCES public.users(id),effective_at timestamptz NOT NULL,
 CHECK(source_team_id<>destination_team_id),UNIQUE(dissolution_id,membership_id)
);
CREATE TABLE public.fp_organization_events (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 actor_id bigint NOT NULL REFERENCES public.users(id),
 action text NOT NULL CHECK(action IN('AMENDED','TEAM_CREATED','LEADER_APPOINTED','DISSOLVED','EXIT_OFFICES_ENDED')),
 team_id bigint REFERENCES public.team_profiles(team_id),membership_id bigint REFERENCES public.financial_planners(id),
 occurred_at timestamptz NOT NULL DEFAULT transaction_timestamp(),context jsonb NOT NULL DEFAULT '{}'
);
CREATE FUNCTION public.f37_append_only() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $f$
BEGIN RAISE EXCEPTION 'F37 append-only evidence' USING ERRCODE='23514'; END;$f$;
CREATE TRIGGER f37_amendment_immutable BEFORE UPDATE OR DELETE ON public.fp_membership_amendments FOR EACH ROW EXECUTE FUNCTION public.f37_append_only();
CREATE TRIGGER f37_dissolution_immutable BEFORE UPDATE OR DELETE ON public.fp_team_dissolutions FOR EACH ROW EXECUTE FUNCTION public.f37_append_only();
CREATE TRIGGER f37_moves_immutable BEFORE UPDATE OR DELETE ON public.fp_team_moves FOR EACH ROW EXECUTE FUNCTION public.f37_append_only();
CREATE TRIGGER f37_org_events_immutable BEFORE UPDATE OR DELETE ON public.fp_organization_events FOR EACH ROW EXECUTE FUNCTION public.f37_append_only();
CREATE FUNCTION public.f37_period_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $f$
BEGIN
 IF TG_OP='DELETE' OR OLD.ends_at IS NOT NULL OR NEW.ends_at IS NULL
 OR (to_jsonb(NEW)-'ends_at') IS DISTINCT FROM (to_jsonb(OLD)-'ends_at')
 THEN RAISE EXCEPTION 'F37 organization history can only be ended once' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END;$f$;
CREATE TRIGGER f37_assignment_history BEFORE UPDATE OR DELETE ON public.fp_team_assignments FOR EACH ROW EXECUTE FUNCTION public.f37_period_guard();
CREATE TRIGGER f37_office_history BEFORE UPDATE OR DELETE ON public.fp_team_offices FOR EACH ROW EXECUTE FUNCTION public.f37_period_guard();
CREATE UNIQUE INDEX f37_active_fp_key_uq ON public.financial_planners(active_leader_key);
ALTER TABLE public.team_profiles ADD CONSTRAINT f37_leader_active_fk FOREIGN KEY(leader_fp_id) REFERENCES public.financial_planners(active_leader_key) ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED;
-- Add/validate the replacement before releasing the same-Team constraint, in this transaction.
DO $f$
DECLARE c record;
BEGIN
 IF (SELECT count(*) FROM pg_constraint WHERE conrelid='public.team_profiles'::regclass AND confrelid='public.financial_planners'::regclass AND contype='f' AND pg_get_constraintdef(oid) LIKE 'FOREIGN KEY (team_id, leader_fp_id)%')<>1
 THEN RAISE EXCEPTION 'F37 expected same-Team leader FK required'; END IF;
 FOR c IN SELECT conname FROM pg_constraint WHERE conrelid='public.team_profiles'::regclass AND confrelid='public.financial_planners'::regclass AND contype='f' AND pg_get_constraintdef(oid) LIKE 'FOREIGN KEY (team_id, leader_fp_id)%'
 LOOP EXECUTE format('ALTER TABLE public.team_profiles DROP CONSTRAINT %I',c.conname); END LOOP;
END;$f$;
CREATE FUNCTION public.f37_operational_actor(actor bigint) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog AS $f$
 SELECT EXISTS(SELECT 1 FROM public.users u JOIN public.model_has_roles m ON m.model_id=u.id AND m.model_type='App\Models\User'
 JOIN public.roles r ON r.id=m.role_id WHERE u.id=actor AND u.is_active AND r.guard_name='web' AND r.name IN('admin','hrd','ro'));
$f$;
CREATE FUNCTION public.fp_organization_command(actor bigint,action text,input jsonb) RETURNS bigint
 LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $f$
DECLARE mid bigint; tid bigint; mm bigint; revision_no integer; current_revision integer; did bigint; destination bigint;
 member public.financial_planners%ROWTYPE; item jsonb; moves jsonb; known jsonb; next_data jsonb; previous_data jsonb;
 at_time timestamptz:=transaction_timestamp(); label text; new_teams jsonb:='{}'; fields text[]:=ARRAY['full_name','email','phone','phone_normalized','bank_account_encrypted','address_ktp','address_current','birth_place','birth_date','education_level','education_institution','graduation_year'];
BEGIN
 IF NOT public.f37_operational_actor(actor) THEN RAISE EXCEPTION 'F37 operational actor required' USING ERRCODE='42501'; END IF;
 IF action='AMEND' THEN
  mid:=(input->>'membership_id')::bigint;
  SELECT * INTO member FROM public.financial_planners WHERE id=mid FOR UPDATE;
  IF NOT FOUND OR member.lifecycle_state IS DISTINCT FROM 'ACTIVE' THEN RAISE EXCEPTION 'F37 only ACTIVE biodata may be amended' USING ERRCODE='23514'; END IF;
  SELECT revision,data INTO current_revision,previous_data FROM public.fp_membership_profiles WHERE membership_id=mid FOR UPDATE;
  current_revision:=COALESCE(current_revision,0);
  IF current_revision IS DISTINCT FROM (input->>'expected_revision')::integer THEN RAISE EXCEPTION 'F37 stale biodata revision' USING ERRCODE='40001'; END IF;
  next_data:=input->'data';
  IF next_data IS NULL OR jsonb_typeof(next_data)<>'object' OR (next_data-fields)<>'{}'::jsonb OR NOT next_data ?& fields
   OR length(COALESCE(input->>'before_encrypted',''))<40 OR length(COALESCE(input->>'after_encrypted',''))<40
  THEN RAISE EXCEPTION 'F37 amendment allowlist/encrypted evidence required' USING ERRCODE='23514'; END IF;
  revision_no:=current_revision+1;
  INSERT INTO public.fp_membership_amendments(membership_id,revision,actor_id,before_encrypted,after_encrypted,fields)
   VALUES(mid,revision_no,actor,input->>'before_encrypted',input->>'after_encrypted',to_jsonb(fields));
  INSERT INTO public.fp_membership_profiles(membership_id,revision,data) VALUES(mid,revision_no,next_data)
   ON CONFLICT(membership_id) DO UPDATE SET revision=EXCLUDED.revision,data=EXCLUDED.data;
  INSERT INTO public.fp_organization_events(actor_id,action,membership_id,context) VALUES(actor,'AMENDED',mid,jsonb_build_object('revision',revision_no));
  RETURN mid;
 ELSIF action='CREATE_TEAM' THEN
  mm:=(input->>'mm_fp_id')::bigint;label:=btrim(input->>'display_name');
  IF mm IS NULL OR label IS NULL OR length(label) NOT BETWEEN 1 AND 255 THEN RAISE EXCEPTION 'F37 MM and Team required' USING ERRCODE='23514'; END IF;
  SELECT * INTO member FROM public.financial_planners WHERE id=mm AND lifecycle_state='ACTIVE' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'F37 MM must be ACTIVE' USING ERRCODE='23514'; END IF;
  INSERT INTO public.teams(name,team,created_at,updated_at) VALUES(member.full_name,label,at_time,at_time) RETURNING id INTO tid;
  INSERT INTO public.team_profiles(team_id,display_name,is_active,mm_fp_id,created_at,updated_at) VALUES(tid,label,true,mm,at_time,at_time);
  INSERT INTO public.fp_team_offices(team_id,membership_id,office,starts_at,actor_id) VALUES(tid,mm,'MM',at_time,actor);
  INSERT INTO public.fp_audit_events(uuid,event,team_id,actor_id,occurred_at,context) VALUES(gen_random_uuid(),'TEAM_CHANGED',tid,actor,at_time,jsonb_build_object('is_active',true,'leader_fp_id',NULL));
  INSERT INTO public.fp_organization_events(actor_id,action,team_id,context) VALUES(actor,'TEAM_CREATED',tid,jsonb_build_object('mm_fp_id',mm));
  RETURN tid;
 ELSIF action='LEADER' THEN
  tid:=(input->>'team_id')::bigint;mid:=NULLIF(input->>'membership_id','')::bigint;
  PERFORM team_id FROM public.team_profiles WHERE team_id=tid AND is_active FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'F37 active Team required' USING ERRCODE='23514'; END IF;
  IF mid IS NOT NULL THEN
   PERFORM id FROM public.financial_planners WHERE id=mid AND lifecycle_state='ACTIVE' FOR UPDATE;
   IF NOT FOUND THEN RAISE EXCEPTION 'F37 leader must be ACTIVE' USING ERRCODE='23514'; END IF;
   IF EXISTS(SELECT 1 FROM public.fp_team_offices WHERE membership_id=mid AND office='LEADER' AND ends_at IS NULL AND team_id<>tid) THEN RAISE EXCEPTION 'F37 leader already appointed elsewhere' USING ERRCODE='23514'; END IF;
  END IF;
  UPDATE public.fp_team_offices SET ends_at=at_time WHERE team_id=tid AND office='LEADER' AND ends_at IS NULL;
  IF mid IS NOT NULL THEN INSERT INTO public.fp_team_offices(team_id,membership_id,office,starts_at,actor_id) VALUES(tid,mid,'LEADER',at_time,actor); END IF;
  UPDATE public.team_profiles SET leader_fp_id=mid,updated_at=at_time WHERE team_id=tid;
  INSERT INTO public.fp_audit_events(uuid,event,team_id,actor_id,occurred_at,context) VALUES(gen_random_uuid(),'TEAM_CHANGED',tid,actor,at_time,jsonb_build_object('is_active',true,'leader_fp_id',mid));
  INSERT INTO public.fp_organization_events(actor_id,action,team_id,membership_id) VALUES(actor,'LEADER_APPOINTED',tid,mid);
  RETURN tid;
 ELSIF action='DISSOLVE' THEN
  tid:=(input->>'team_id')::bigint;moves:=input->'moves';
  IF jsonb_typeof(moves) IS DISTINCT FROM 'array' THEN RAISE EXCEPTION 'F37 complete roster required' USING ERRCODE='23514'; END IF;
  -- Lock all existing source/destination profiles in stable order before any membership locks.
  PERFORM team_id FROM public.team_profiles WHERE team_id=tid OR team_id IN(SELECT (x->>'destination_team_id')::bigint FROM jsonb_array_elements(moves)x WHERE x ? 'destination_team_id') ORDER BY team_id FOR UPDATE;
  IF NOT EXISTS(SELECT 1 FROM public.team_profiles WHERE team_id=tid AND is_active) THEN RAISE EXCEPTION 'F37 source Team inactive' USING ERRCODE='23514'; END IF;
  SELECT COALESCE(jsonb_agg(m.id ORDER BY m.id),'[]') INTO known FROM public.financial_planners m JOIN public.fp_team_assignments a ON a.membership_id=m.id AND a.ends_at IS NULL WHERE a.team_id=tid AND m.lifecycle_state='ACTIVE';
  IF known IS DISTINCT FROM (SELECT COALESCE(jsonb_agg((x->>'membership_id')::bigint ORDER BY (x->>'membership_id')::bigint),'[]') FROM jsonb_array_elements(moves)x)
  THEN RAISE EXCEPTION 'F37 every ACTIVE member needs exactly one destination' USING ERRCODE='23514'; END IF;
  PERFORM id FROM public.financial_planners WHERE id IN(
   SELECT (x->>'membership_id')::bigint FROM jsonb_array_elements(moves)x
   UNION SELECT (x->>'mm_fp_id')::bigint FROM jsonb_array_elements(COALESCE(input->'new_teams','[]'))x
  ) ORDER BY id FOR UPDATE;
  FOR item IN SELECT value FROM jsonb_array_elements(COALESCE(input->'new_teams','[]')) LOOP
   IF item->>'key' IS NULL OR new_teams ? (item->>'key') THEN RAISE EXCEPTION 'F37 unique new Team key required' USING ERRCODE='23514'; END IF;
   destination:=public.fp_organization_command(actor,'CREATE_TEAM',item);
   new_teams:=new_teams||jsonb_build_object(item->>'key',destination);
  END LOOP;
  INSERT INTO public.fp_team_dissolutions(source_team_id,actor_id,effective_at,roster) VALUES(tid,actor,at_time,moves) RETURNING id INTO did;
  FOR item IN SELECT value FROM jsonb_array_elements(moves) ORDER BY (value->>'membership_id')::bigint LOOP
   mid:=(item->>'membership_id')::bigint;
   destination:=CASE WHEN item ? 'new_team_key' THEN (new_teams->>(item->>'new_team_key'))::bigint ELSE (item->>'destination_team_id')::bigint END;
   IF destination IS NULL OR destination=tid OR NOT EXISTS(SELECT 1 FROM public.team_profiles WHERE team_id=destination AND is_active)
   THEN RAISE EXCEPTION 'F37 active distinct destination required' USING ERRCODE='23514'; END IF;
   UPDATE public.fp_team_assignments SET ends_at=at_time WHERE membership_id=mid AND ends_at IS NULL AND team_id=tid;
   IF NOT FOUND THEN RAISE EXCEPTION 'F37 roster changed' USING ERRCODE='40001'; END IF;
   INSERT INTO public.fp_team_assignments(membership_id,team_id,starts_at,actor_id,evidence_kind) VALUES(mid,destination,at_time,actor,'TRANSFER');
   INSERT INTO public.fp_team_moves(dissolution_id,membership_id,source_team_id,destination_team_id,actor_id,effective_at) VALUES(did,mid,tid,destination,actor,at_time);
  END LOOP;
  UPDATE public.fp_team_offices SET ends_at=at_time WHERE team_id=tid AND ends_at IS NULL;
  UPDATE public.team_profiles SET is_active=false,leader_fp_id=NULL,mm_fp_id=NULL,updated_at=at_time WHERE team_id=tid;
  INSERT INTO public.fp_audit_events(uuid,event,team_id,actor_id,occurred_at,context) VALUES(gen_random_uuid(),'TEAM_CHANGED',tid,actor,at_time,jsonb_build_object('is_active',false,'leader_fp_id',NULL));
  INSERT INTO public.fp_organization_events(actor_id,action,team_id,context) VALUES(actor,'DISSOLVED',tid,jsonb_build_object('dissolution_id',did));
  RETURN did;
 END IF;
 RAISE EXCEPTION 'F37 command not allowed' USING ERRCODE='42501';
END;$f$;
-- Effective reads do not overwrite immutable original membership/submission snapshots.
DO $f$
DECLARE columns_sql text;
BEGIN
 SELECT string_agg(CASE
  WHEN a.attname=ANY(ARRAY['full_name','email','phone','phone_normalized','bank_account_encrypted','address_ktp','address_current','birth_place','birth_date','education_level','education_institution','graduation_year'])
   THEN format('CASE WHEN p.membership_id IS NOT NULL THEN (p.data->>%L)::%s ELSE f.%I END AS %I',a.attname,format_type(a.atttypid,a.atttypmod),a.attname,a.attname)
  WHEN a.attname='team_id' THEN 'COALESCE(assignment.team_id,f.team_id) AS team_id'
  ELSE format('f.%I',a.attname) END,',' ORDER BY a.attnum) INTO columns_sql
 FROM pg_attribute a WHERE a.attrelid='public.financial_planners'::regclass AND a.attnum>0 AND NOT a.attisdropped;
 EXECUTE 'CREATE VIEW public.fp_current_memberships WITH (security_invoker=true) AS SELECT '||columns_sql||' FROM public.financial_planners f LEFT JOIN public.fp_membership_profiles p ON p.membership_id=f.id LEFT JOIN LATERAL(SELECT team_id FROM public.fp_team_assignments WHERE membership_id=f.id ORDER BY id DESC LIMIT 1) assignment ON true';
END;$f$;
CREATE FUNCTION public.f37_membership_organization() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $f$
DECLARE exit_actor bigint; ended_at timestamptz; profile record;
BEGIN
 IF TG_OP='INSERT' AND NEW.lifecycle_state='ACTIVE' THEN
  INSERT INTO public.fp_team_assignments(membership_id,team_id,starts_at,actor_id,evidence_kind)
   VALUES(NEW.id,NEW.team_id,NEW.approved_at,NEW.approved_actor_id,'APPROVAL');
 ELSIF TG_OP='UPDATE' AND OLD.lifecycle_state='ACTIVE' AND NEW.lifecycle_state='EXITED' THEN
  SELECT actor_id,processed_at INTO exit_actor,ended_at FROM public.fp_exits WHERE membership_id=NEW.id;
  IF exit_actor IS NULL THEN RAISE EXCEPTION 'F37 exit evidence required' USING ERRCODE='23514'; END IF;
  UPDATE public.fp_team_assignments SET ends_at=ended_at WHERE membership_id=NEW.id AND ends_at IS NULL;
  UPDATE public.fp_team_offices SET ends_at=ended_at WHERE membership_id=NEW.id AND ends_at IS NULL;
  FOR profile IN SELECT * FROM public.team_profiles WHERE leader_fp_id=NEW.id OR mm_fp_id=NEW.id ORDER BY team_id FOR UPDATE LOOP
   UPDATE public.team_profiles SET leader_fp_id=CASE WHEN leader_fp_id=NEW.id THEN NULL ELSE leader_fp_id END,
    mm_fp_id=CASE WHEN mm_fp_id=NEW.id THEN NULL ELSE mm_fp_id END,updated_at=ended_at WHERE team_id=profile.team_id;
   INSERT INTO public.fp_audit_events(uuid,event,team_id,actor_id,occurred_at,context)
    VALUES(gen_random_uuid(),'TEAM_CHANGED',profile.team_id,exit_actor,ended_at,jsonb_build_object('is_active',profile.is_active,'leader_fp_id',CASE WHEN profile.leader_fp_id=NEW.id THEN NULL ELSE profile.leader_fp_id END));
  END LOOP;
  INSERT INTO public.fp_organization_events(actor_id,action,membership_id) VALUES(exit_actor,'EXIT_OFFICES_ENDED',NEW.id);
 END IF;
 RETURN NULL;
END;$f$;
CREATE TRIGGER f37_membership_organization_after AFTER INSERT OR UPDATE OF lifecycle_state ON public.financial_planners FOR EACH ROW EXECUTE FUNCTION public.f37_membership_organization();
CREATE FUNCTION public.f37_org_consistency() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $f$
BEGIN
 IF EXISTS(SELECT 1 FROM public.fp_team_dissolutions d JOIN public.team_profiles t ON t.team_id=d.source_team_id WHERE t.is_active)
 THEN RAISE EXCEPTION 'F37 dissolved Team remains historical' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM public.financial_planners m WHERE m.lifecycle_state='ACTIVE' AND
  (SELECT count(*) FROM public.fp_team_assignments a JOIN public.team_profiles t ON t.team_id=a.team_id WHERE a.membership_id=m.id AND a.ends_at IS NULL AND t.is_active)<>1)
 THEN RAISE EXCEPTION 'F37 ACTIVE membership needs one active Team assignment' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM public.fp_team_offices o JOIN public.financial_planners m ON m.id=o.membership_id JOIN public.team_profiles t ON t.team_id=o.team_id
  WHERE o.ends_at IS NULL AND (NOT t.is_active OR m.lifecycle_state IS DISTINCT FROM 'ACTIVE'
   OR (o.office='LEADER' AND o.membership_id IS DISTINCT FROM t.leader_fp_id) OR (o.office='MM' AND o.membership_id IS DISTINCT FROM t.mm_fp_id)))
 OR EXISTS(SELECT 1 FROM public.team_profiles t WHERE
  (t.leader_fp_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.fp_team_offices o WHERE o.team_id=t.team_id AND o.office='LEADER' AND o.ends_at IS NULL AND o.membership_id=t.leader_fp_id))
  OR (t.mm_fp_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM public.fp_team_offices o WHERE o.team_id=t.team_id AND o.office='MM' AND o.ends_at IS NULL AND o.membership_id=t.mm_fp_id)))
 THEN RAISE EXCEPTION 'F37 office/profile consistency required' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END;$f$;
CREATE CONSTRAINT TRIGGER f37_profile_complete AFTER INSERT OR UPDATE ON public.team_profiles DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.f37_org_consistency();
CREATE CONSTRAINT TRIGGER f37_assignments_complete AFTER INSERT OR UPDATE ON public.fp_team_assignments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.f37_org_consistency();
CREATE CONSTRAINT TRIGGER f37_offices_complete AFTER INSERT OR UPDATE ON public.fp_team_offices DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.f37_org_consistency();
CREATE CONSTRAINT TRIGGER f37_membership_complete AFTER INSERT OR UPDATE ON public.financial_planners DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.f37_org_consistency();
REVOKE ALL ON TABLE public.fp_membership_amendments,public.fp_membership_profiles,public.fp_team_assignments,public.fp_team_offices,public.fp_team_dissolutions,public.fp_team_moves,public.fp_organization_events,public.fp_current_memberships FROM PUBLIC;
REVOKE ALL ON FUNCTION public.fp_organization_command(bigint,text,jsonb),public.f37_operational_actor(bigint),public.f37_append_only(),public.f37_period_guard(),public.f37_membership_organization(),public.f37_org_consistency() FROM PUBLIC;
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
        throw new LogicException('Forward recovery only; preserve organization and amendment history.');
    }
};
