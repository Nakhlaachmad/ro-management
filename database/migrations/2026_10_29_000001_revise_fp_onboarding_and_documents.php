<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public const SQL = <<<'SQL'
-- Forward-only F3.7H. Historical rows, document bytes and audit evidence are retained.
ALTER TABLE public.fp_submissions ADD COLUMN onboarding_contract_version smallint NOT NULL DEFAULT 1 CHECK(onboarding_contract_version IN(1,2));
ALTER TABLE public.fp_submissions ALTER COLUMN onboarding_contract_version SET DEFAULT 2;
ALTER TABLE public.fp_submissions ALTER COLUMN bank_account_encrypted DROP NOT NULL;
ALTER TABLE public.financial_planners ADD COLUMN approval_mm_name_snapshot varchar(255);
ALTER TABLE public.financial_planners ADD CONSTRAINT f37h_mm_shape CHECK(approval_mm_name_snapshot IS NULL OR (length(btrim(approval_mm_name_snapshot)) BETWEEN 1 AND 255 AND approval_mm_name_snapshot=btrim(approval_mm_name_snapshot) AND approval_mm_name_snapshot !~ '[[:cntrl:]<>]'));
ALTER TABLE public.fp_submissions DROP CONSTRAINT f2_fp_submissions_terminal_evidence_ck;
ALTER TABLE public.fp_submissions ADD CONSTRAINT f2_fp_submissions_terminal_evidence_ck CHECK (((((status)::text = 'PENDING'::text) AND (approved_at IS NULL) AND (approved_actor_id IS NULL) AND (approved_by IS NULL) AND (rejected_at IS NULL) AND (rejected_actor_id IS NULL) AND (rejected_by IS NULL) AND (rejection_reason IS NULL)) OR (((status)::text = 'APPROVED'::text) AND (approved_at IS NOT NULL) AND (approved_actor_id IS NOT NULL) AND (approved_actor_id > 0) AND (rejected_at IS NULL) AND (rejected_actor_id IS NULL) AND (rejected_by IS NULL) AND (rejection_reason IS NULL) AND ((onboarding_contract_version = 2) OR ((biodata_verified_revision IS NOT NULL) AND (documents_verified_revision IS NOT NULL)))) OR (((status)::text = 'REJECTED'::text) AND (rejected_at IS NOT NULL) AND (rejected_actor_id IS NOT NULL) AND (rejected_actor_id > 0) AND (rejection_reason IS NOT NULL) AND (length(TRIM(BOTH FROM rejection_reason)) > 0) AND (approved_at IS NULL) AND (approved_actor_id IS NULL) AND (approved_by IS NULL))));
ALTER TABLE public.financial_planners DROP CONSTRAINT f2_financial_planners_new_evidence_ck;
ALTER TABLE public.financial_planners ADD CONSTRAINT f2_financial_planners_new_evidence_ck CHECK ((((origin)::text = 'LEGACY'::text) OR ((identity_id IS NOT NULL) AND (nip IS NOT NULL) AND (nip >= 1001) AND (submission_id IS NOT NULL) AND (lifecycle_state IS NOT NULL) AND (team_id IS NOT NULL) AND (reference_date IS NOT NULL) AND (business_timezone IS NOT NULL) AND (approved_at IS NOT NULL) AND (approved_actor_id IS NOT NULL) AND (approved_actor_id > 0) AND (full_name IS NOT NULL) AND (phone IS NOT NULL) AND (phone_normalized IS NOT NULL) AND (address_ktp IS NOT NULL) AND (address_current IS NOT NULL) AND (birth_place IS NOT NULL) AND (birth_date IS NOT NULL) AND (education_level IS NOT NULL) AND (education_institution IS NOT NULL) AND (graduation_year IS NOT NULL) AND (source_type IS NOT NULL))));
CREATE OR REPLACE FUNCTION public.f2_core_ready_documents_final()
 RETURNS trigger
 LANGUAGE plpgsql
 SECURITY DEFINER
 SET search_path TO 'pg_catalog'
AS $function$
DECLARE sid bigint; parent public.fp_submissions%ROWTYPE; mid bigint; total integer;
BEGIN
 IF TG_TABLE_NAME='fp_submissions' THEN sid:=NEW.id; ELSE sid:=NEW.submission_id; END IF;
 IF sid IS NULL THEN RETURN NULL; END IF;
 SELECT * INTO parent FROM public.fp_submissions WHERE id=sid;
 IF parent.onboarding_contract_version=2 THEN
  IF parent.status='APPROVED' THEN
   SELECT id INTO mid FROM public.financial_planners WHERE submission_id=sid;
   IF mid IS NULL OR EXISTS(SELECT 1 FROM public.fp_documents WHERE submission_id=sid AND type IN('KTP','IJAZAH') AND (membership_id IS DISTINCT FROM mid OR revision<>parent.document_revision OR processing_state<>'READY' OR deletion_state<>'NONE'))
   THEN RAISE EXCEPTION 'F37H uploaded documents must be READY and owned by approved membership'; END IF;
  ELSIF parent.status='REJECTED' AND EXISTS(SELECT 1 FROM public.fp_documents WHERE submission_id=sid AND deletion_state='NONE') THEN RAISE EXCEPTION 'F2 rejection requires deletion intent'; END IF;
  RETURN NULL;
 END IF;
 SELECT count(*) INTO total FROM public.fp_documents WHERE submission_id=sid AND revision=parent.document_revision;
 IF total<>3 THEN RAISE EXCEPTION 'F2 three current documents required'; END IF;
 IF parent.status='APPROVED' THEN
  SELECT id INTO mid FROM public.financial_planners WHERE submission_id=sid;
  IF mid IS NULL OR (SELECT count(*) FROM public.fp_documents WHERE submission_id=sid AND membership_id=mid AND revision=parent.document_revision AND processing_state='READY' AND deletion_state='NONE')<>3 THEN RAISE EXCEPTION 'F2 approval requires three READY linked documents'; END IF;
 ELSIF parent.status='REJECTED' AND EXISTS(SELECT 1 FROM public.fp_documents WHERE submission_id=sid AND deletion_state='NONE') THEN RAISE EXCEPTION 'F2 rejection requires deletion intent'; END IF;
 RETURN NULL;
END;$function$
;
CREATE OR REPLACE FUNCTION public.fp_organization_command(actor bigint, action text, input jsonb)
 RETURNS bigint
 LANGUAGE plpgsql
 SECURITY DEFINER
 SET search_path TO 'pg_catalog'
AS $function$
DECLARE mid bigint; tid bigint; mm bigint; revision_no integer; current_revision integer; did bigint; destination bigint;
 member public.financial_planners%ROWTYPE; item jsonb; moves jsonb; known jsonb; next_data jsonb; previous_data jsonb;
 at_time timestamptz:=transaction_timestamp(); label text; new_teams jsonb:='{}'; fields text[]:=ARRAY['full_name','email','phone','phone_normalized','bank_account_encrypted','address_ktp','address_current','birth_place','birth_date','education_level','education_institution','graduation_year'];
BEGIN
 IF NOT public.f37_operational_actor(actor) THEN RAISE EXCEPTION 'F37 operational actor required' USING ERRCODE='42501'; END IF;
 IF action='AMEND' THEN
  mid:=(input->>'membership_id')::bigint;
  SELECT * INTO member FROM public.financial_planners WHERE id=mid FOR UPDATE;
  IF NOT FOUND OR (member.lifecycle_state IS DISTINCT FROM 'ACTIVE' AND NOT EXISTS(SELECT 1 FROM public.users u JOIN public.model_has_roles mr ON mr.model_id=u.id AND mr.model_type='App\Models\User' JOIN public.roles r ON r.id=mr.role_id WHERE u.id=actor AND u.is_active AND r.name='admin' AND r.guard_name='web')) THEN RAISE EXCEPTION 'F37 only ACTIVE biodata may be amended' USING ERRCODE='23514'; END IF;
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
END;$function$
;

CREATE FUNCTION public.f37h_submission_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $h$
BEGIN
 IF TG_OP='INSERT' AND NEW.onboarding_contract_version<>2 THEN RAISE EXCEPTION 'F37H new submission contract required'; END IF;
 IF TG_OP='UPDATE' AND NEW.onboarding_contract_version IS DISTINCT FROM OLD.onboarding_contract_version AND (OLD.status<>'PENDING' OR OLD.onboarding_contract_version<>1 OR NEW.onboarding_contract_version<>2) THEN RAISE EXCEPTION 'F37H immutable submission contract'; END IF;
 IF NEW.onboarding_contract_version=2 AND ((TG_OP='INSERT' AND (NEW.biodata_verified_revision IS NOT NULL OR NEW.documents_verified_revision IS NOT NULL)) OR (TG_OP='UPDATE' AND ((NEW.biodata_verified_revision IS NOT NULL AND NEW.biodata_verified_revision IS DISTINCT FROM OLD.biodata_verified_revision) OR (NEW.documents_verified_revision IS NOT NULL AND NEW.documents_verified_revision IS DISTINCT FROM OLD.documents_verified_revision)))) THEN RAISE EXCEPTION 'F37H manual verification retired'; END IF;
 RETURN NEW;
END;$h$;
CREATE TRIGGER f37h_submission_before BEFORE INSERT OR UPDATE ON public.fp_submissions FOR EACH ROW EXECUTE FUNCTION public.f37h_submission_guard();
CREATE FUNCTION public.f37h_membership_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $h$
BEGIN
 IF TG_OP='INSERT' AND NEW.origin='NEW' AND NOT EXISTS(SELECT 1 FROM public.fp_submissions WHERE id=NEW.submission_id AND onboarding_contract_version=2) THEN RAISE EXCEPTION 'F37H approval contract required'; END IF;
 IF TG_OP='INSERT' AND NEW.origin='NEW' AND (NEW.approval_mm_name_snapshot IS NULL OR length(btrim(NEW.approval_mm_name_snapshot))=0) THEN RAISE EXCEPTION 'F37H approval MM snapshot required'; END IF;
 IF TG_OP='UPDATE' AND NEW.approval_mm_name_snapshot IS DISTINCT FROM OLD.approval_mm_name_snapshot THEN RAISE EXCEPTION 'F37H approval MM snapshot immutable'; END IF;
 RETURN NEW;
END;$h$;
CREATE TRIGGER f37h_membership_before BEFORE INSERT OR UPDATE ON public.financial_planners FOR EACH ROW EXECUTE FUNCTION public.f37h_membership_guard();
CREATE FUNCTION public.f37h_document_archive_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $h$
BEGIN
 IF TG_OP='INSERT' AND NEW.type='PROFILE_PHOTO' THEN RAISE EXCEPTION 'F37H profile photos retired; historical evidence is retained privately'; END IF;
 RETURN NEW;
END;$h$;
CREATE TRIGGER f37h_document_before BEFORE INSERT ON public.fp_documents FOR EACH ROW EXECUTE FUNCTION public.f37h_document_archive_guard();
-- No new profile-photo column or active type. Existing PROFILE_PHOTO rows are private archive evidence, protected by their original immutable guards/FKs.
CREATE TABLE public.fp_document_amendments (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
 membership_id bigint NOT NULL REFERENCES public.financial_planners(id) ON DELETE RESTRICT,
 submission_id bigint REFERENCES public.fp_submissions(id) ON DELETE RESTRICT,
 type varchar(32) NOT NULL CHECK(type IN('KTP','IJAZAH')),
 revision bigint NOT NULL CHECK(revision>0), actor_id bigint NOT NULL REFERENCES public.users(id) ON DELETE RESTRICT,
 previous_uuid uuid, occurred_at timestamptz NOT NULL DEFAULT transaction_timestamp(),
 storage_adapter varchar(32) NOT NULL CHECK(storage_adapter='local'), private_path text NOT NULL,
 mime varchar(64) NOT NULL CHECK(mime IN('image/jpeg','image/png')), bytes bigint NOT NULL CHECK(bytes BETWEEN 1 AND 8388608),
 sha256 varchar(64) NOT NULL CHECK(sha256 ~ '^[0-9a-f]{64}$'),
 CHECK(private_path ~ '^fp/[0-9a-f-]{36}/[0-9a-f-]{36}\.(jpg|png)$'),
 UNIQUE(membership_id,type,revision),
 FOREIGN KEY(submission_id,membership_id) REFERENCES public.financial_planners(submission_id,id) ON DELETE RESTRICT
);
CREATE TRIGGER f37h_document_history_immutable BEFORE UPDATE OR DELETE ON public.fp_document_amendments FOR EACH ROW EXECUTE FUNCTION public.f37_append_only();
CREATE FUNCTION public.fp_amend_membership_document(actor bigint,mid bigint,kind text,expected_revision bigint,metadata jsonb) RETURNS bigint
 LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog AS $h$
DECLARE member public.financial_planners%ROWTYPE; previous public.fp_document_amendments%ROWTYPE; current_revision bigint; prior_uuid uuid; result bigint;
BEGIN
 IF NOT EXISTS(SELECT 1 FROM public.users u JOIN public.model_has_roles mr ON mr.model_id=u.id AND mr.model_type='App\Models\User' JOIN public.roles r ON r.id=mr.role_id WHERE u.id=actor AND u.is_active AND r.name='admin' AND r.guard_name='web') THEN RAISE EXCEPTION 'F37H active admin required' USING ERRCODE='42501'; END IF;
 IF kind IS NULL OR kind NOT IN('KTP','IJAZAH') OR expected_revision IS NULL OR expected_revision<0 OR jsonb_typeof(metadata) IS DISTINCT FROM 'object' OR metadata-ARRAY['storage_adapter','private_path','mime','bytes','sha256']<>'{}'::jsonb OR NOT metadata ?& ARRAY['storage_adapter','private_path','mime','bytes','sha256'] THEN RAISE EXCEPTION 'F37H document allowlist required'; END IF;
 SELECT * INTO member FROM public.financial_planners WHERE id=mid FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'F37H membership unavailable'; END IF;
 SELECT * INTO previous FROM public.fp_document_amendments WHERE membership_id=mid AND type=kind ORDER BY revision DESC LIMIT 1;
 current_revision:=COALESCE(previous.revision,0);
 IF current_revision<>expected_revision THEN RAISE EXCEPTION 'F37H stale document revision' USING ERRCODE='40001'; END IF;
 prior_uuid:=previous.uuid;
 IF prior_uuid IS NULL THEN SELECT uuid INTO prior_uuid FROM public.fp_documents WHERE membership_id=mid AND type=kind; END IF;
 INSERT INTO public.fp_document_amendments(membership_id,submission_id,type,revision,actor_id,previous_uuid,storage_adapter,private_path,mime,bytes,sha256)
 VALUES(mid,member.submission_id,kind,current_revision+1,actor,prior_uuid,metadata->>'storage_adapter',metadata->>'private_path',metadata->>'mime',(metadata->>'bytes')::bigint,metadata->>'sha256') RETURNING id INTO result;
 RETURN result;
END;$h$;
REVOKE ALL ON FUNCTION public.fp_amend_membership_document(bigint,bigint,text,bigint,jsonb) FROM PUBLIC;
REVOKE ALL ON TABLE public.fp_document_amendments FROM PUBLIC;
REVOKE ALL ON FUNCTION public.f37h_submission_guard(),public.f37h_membership_guard(),public.f37h_document_archive_guard() FROM PUBLIC;
CREATE VIEW public.fp_current_documents WITH(security_invoker=true) AS
 SELECT d.* FROM public.fp_documents d WHERE d.type IN('KTP','IJAZAH') AND NOT EXISTS(SELECT 1 FROM public.fp_document_amendments a WHERE a.membership_id=d.membership_id AND a.type=d.type)
 UNION ALL
 SELECT -a.id,a.uuid,a.submission_id,a.membership_id,a.type,COALESCE(s.document_revision,1),'READY'::varchar(16),a.storage_adapter,a.private_path,a.mime,a.bytes,a.sha256,'NONE'::varchar(16),NULL::varchar(32),a.occurred_at,a.occurred_at
 FROM public.fp_document_amendments a LEFT JOIN public.fp_submissions s ON s.id=a.submission_id
 WHERE NOT EXISTS(SELECT 1 FROM public.fp_document_amendments newer WHERE newer.membership_id=a.membership_id AND newer.type=a.type AND newer.revision>a.revision);
DO $f$
DECLARE columns_sql text;
BEGIN
 SELECT string_agg(CASE
  WHEN a.attname=ANY(ARRAY['full_name','email','phone','phone_normalized','bank_account_encrypted','address_ktp','address_current','birth_place','birth_date','education_level','education_institution','graduation_year'])
   THEN format('CASE WHEN p.membership_id IS NOT NULL THEN (p.data->>%L)::%s ELSE f.%I END AS %I',a.attname,format_type(a.atttypid,a.atttypmod),a.attname,a.attname)
  WHEN a.attname='team_id' THEN 'COALESCE(assignment.team_id,f.team_id) AS team_id'
  ELSE format('f.%I',a.attname) END,',' ORDER BY a.attnum) INTO columns_sql
 FROM pg_attribute a WHERE a.attrelid='public.financial_planners'::regclass AND a.attnum>0 AND NOT a.attisdropped;
 EXECUTE 'CREATE OR REPLACE VIEW public.fp_current_memberships WITH (security_invoker=true) AS SELECT '||columns_sql||' FROM public.financial_planners f LEFT JOIN public.fp_membership_profiles p ON p.membership_id=f.id LEFT JOIN LATERAL(SELECT team_id FROM public.fp_team_assignments WHERE membership_id=f.id ORDER BY id DESC LIMIT 1) assignment ON true';
END;$f$;

-- Narrow privileges on NEW objects only. No direct document-history INSERT/UPDATE/DELETE or sequence privileges.
GRANT SELECT ON public.fp_document_amendments,public.fp_current_documents TO fp_dev_app;
GRANT EXECUTE ON FUNCTION public.fp_amend_membership_document(bigint,bigint,text,bigint,jsonb) TO fp_dev_app;
SQL;

    public function up(): void
    {
        // FP_SQLITE_TEST_SKIP: SQLite is only for isolated HTTP characterization tests.
        if (DB::connection()->getDriverName() !== 'pgsql' && app()->environment('testing')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql' || DB::transactionLevel() < 1) {
            throw new LogicException('F3.7H requires PostgreSQL and an enclosing migration/ledger transaction');
        }
        $owner = DB::selectOne("SELECT r.rolsuper,pg_get_userbyid(p.proowner)=current_user AS owns_command FROM pg_roles r CROSS JOIN pg_proc p WHERE r.rolname=current_user AND p.oid='public.fp_organization_command(bigint,text,jsonb)'::regprocedure");
        if (! $owner || $owner->rolsuper || ! $owner->owns_command) {
            throw new LogicException('F3.7H requires the existing non-superuser command owner');
        }
        $definition = DB::selectOne("SELECT pg_get_functiondef('public.f2_core_ready_documents_final()'::regprocedure) AS definition")->definition;
        if (! hash_equals('53518decfdda8d10d90b07d251538057fc3e96ae874e169fa9dcfa762eb180cc', hash('sha256', $definition))) {
            throw new LogicException('STOP F3.7H existing READY guard differs from reviewed source');
        }
        $organization = DB::selectOne("SELECT pg_get_functiondef('public.fp_organization_command(bigint,text,jsonb)'::regprocedure) AS definition")->definition;
        if (! hash_equals('191cb6a5c03a52427333b109b58166faab51e08c94b4e2cbf42c8019bf0487bf', hash('sha256', $organization))) {
            throw new LogicException('STOP F3.7H organization command differs from reviewed source');
        }
        DB::unprepared(self::SQL);
    }

    public function down(): void
    {
        throw new LogicException('Forward-only; preserve historical approvals and private document revisions');
    }
};
