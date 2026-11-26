-- PROPOSED ONLY. NOT EXECUTED. Requires explicit schema/function approval.
-- Apply first ONLY to fp_f2_test_f36_complete_20261012 after identity preflight.
-- The forward migration must include this and its exact guard replacements in
-- ONE transaction with ONE reviewed migration ledger row. Not a standalone script.
ALTER TABLE public.fp_submissions ADD COLUMN email varchar(254);
ALTER TABLE public.financial_planners ADD COLUMN email varchar(254);
ALTER TABLE public.financial_planners ADD COLUMN nip_year integer;
ALTER TABLE public.financial_planners ADD COLUMN nip_serial integer;

CREATE TABLE public.fp_nip_year_counters (
    year integer PRIMARY KEY CHECK (year BETWEEN 2010 AND 9999),
    next_serial integer NOT NULL DEFAULT 1001 CHECK (next_serial BETWEEN 1001 AND 10000)
);

ALTER TABLE public.fp_submissions ADD CONSTRAINT fp_submission_email_shape_ck
CHECK (email IS NULL OR (email = pg_catalog.lower(email) AND email ~ '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$'));
ALTER TABLE public.financial_planners ADD CONSTRAINT fp_membership_email_shape_ck
CHECK (email IS NULL OR (email = pg_catalog.lower(email) AND email ~ '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$'));
ALTER TABLE public.financial_planners ADD CONSTRAINT fp_membership_annual_nip_ck CHECK (
    (nip_year IS NULL AND nip_serial IS NULL)
    OR (nip_year IS NOT NULL AND nip_serial IS NOT NULL AND origin='NEW'
        AND nip_serial BETWEEN 1001 AND 9999
        AND approved_at IS NOT NULL
        AND nip_year = EXTRACT(YEAR FROM approved_at AT TIME ZONE 'Asia/Jakarta')
        AND nip = (nip_year % 100) * 10000 + nip_serial
        AND nip BETWEEN 100000 AND 999999)
);
ALTER TABLE public.financial_planners ADD CONSTRAINT fp_membership_nip_year_fk
FOREIGN KEY (nip_year) REFERENCES public.fp_nip_year_counters(year) ON DELETE RESTRICT ON UPDATE RESTRICT;
CREATE UNIQUE INDEX fp_membership_year_serial_uq ON public.financial_planners(nip_year,nip_serial);

-- No UPDATE/backfill, type change, counter reset, or old NIP renumbering.
-- Existing nip BIGINT UNIQUE, historical rows and singleton nip_sequences stay intact.
-- REQUIRED companion replacements, generated separately:
-- f2_core_submission_guard: new INSERT email required; email in revision comparison.
-- f2_core_membership_guard: new INSERT annual key required; snapshots immutable.
-- f2_core_final_relationship: exact email snapshot and annual counter advance.
-- fp_nip_year_counter_guard (new): initial 1001 only, immutable year, +1 only
-- after matching NEW membership insertion, no reset/deletion.

-- Runtime grants are SEPARATE proposals, not part of silent activation:
-- SELECT, INSERT on fp_nip_year_counters; UPDATE(next_serial) only.
-- Review column-level grants for new email/nip_year/nip_serial against current ACL.


-- PROPOSED ONLY. Companion to annual-email-schema-proposal.sql.
CREATE FUNCTION public.fp_nip_year_counter_guard() RETURNS trigger
LANGUAGE plpgsql SET search_path=pg_catalog AS $annual$
BEGIN
 IF TG_OP='INSERT' THEN
  IF NEW.next_serial<>1001 THEN
   RAISE EXCEPTION 'NIP annual counter must start at 1001' USING ERRCODE='23514';
  END IF;
 ELSIF TG_OP='UPDATE' THEN
  IF NEW.year<>OLD.year OR NEW.next_serial<>OLD.next_serial+1
   OR NOT EXISTS(SELECT 1 FROM public.financial_planners
     WHERE origin='NEW' AND nip_year=OLD.year AND nip_serial=OLD.next_serial)
  THEN RAISE EXCEPTION 'NIP annual counter requires matching issued membership' USING ERRCODE='23514'; END IF;
 ELSE
  RAISE EXCEPTION 'NIP annual counter cannot be deleted' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END;
$annual$;
CREATE TRIGGER fp_nip_year_counter_guard_tr BEFORE INSERT OR UPDATE OR DELETE
ON public.fp_nip_year_counters FOR EACH ROW EXECUTE FUNCTION public.fp_nip_year_counter_guard();


-- PROPOSED ONLY. Preserves original guards; no ALTER/DDL executed.
CREATE OR REPLACE FUNCTION public.f2_core_submission_guard()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'pg_catalog'
AS $function$
DECLARE changed boolean;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'F2 submission history deletion prohibited' USING ERRCODE = '23514'; END IF;
 IF TG_OP = 'INSERT' THEN
  IF NEW.email IS NULL OR btrim(NEW.email)='' THEN
   RAISE EXCEPTION 'Email wajib diisi untuk submission baru' USING ERRCODE='23514';
  END IF;
  IF NEW.status <> 'PENDING' THEN RAISE EXCEPTION 'F2 submission must begin PENDING' USING ERRCODE = '23514'; END IF;
 ELSE
  IF OLD.email IS NOT NULL AND (NEW.email IS NULL OR btrim(NEW.email)='') THEN
   RAISE EXCEPTION 'Email submission tidak boleh dikosongkan' USING ERRCODE='23514';
  END IF;
  IF ROW(NEW.id,NEW.uuid,NEW.idempotency_key,NEW.submitted_at,NEW.business_timezone,NEW.reference_date,NEW.created_at)
   IS DISTINCT FROM ROW(OLD.id,OLD.uuid,OLD.idempotency_key,OLD.submitted_at,OLD.business_timezone,OLD.reference_date,OLD.created_at)
  THEN RAISE EXCEPTION 'F2 submission reference immutable' USING ERRCODE = '23514'; END IF;
  -- Actor FK SET NULL may clear live links without erasing stable actor evidence.
  IF OLD.status <> 'PENDING' THEN
   IF (to_jsonb(NEW) - ARRAY['biodata_verified_by','documents_verified_by','approved_by','rejected_by'])
    IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['biodata_verified_by','documents_verified_by','approved_by','rejected_by'])
    OR (NEW.biodata_verified_by IS DISTINCT FROM OLD.biodata_verified_by AND NEW.biodata_verified_by IS NOT NULL)
    OR (NEW.documents_verified_by IS DISTINCT FROM OLD.documents_verified_by AND NEW.documents_verified_by IS NOT NULL)
    OR (NEW.approved_by IS DISTINCT FROM OLD.approved_by AND NEW.approved_by IS NOT NULL)
    OR (NEW.rejected_by IS DISTINCT FROM OLD.rejected_by AND NEW.rejected_by IS NOT NULL)
   THEN RAISE EXCEPTION 'F2 terminal submission immutable' USING ERRCODE = '23514'; END IF;
   RETURN NEW;
  END IF;
  changed := ROW(NEW.identity_id,NEW.team_id,NEW.email,NEW.full_name,NEW.phone,NEW.phone_normalized,NEW.bank_account_encrypted,NEW.address_ktp,NEW.address_current,NEW.birth_place,NEW.birth_date,NEW.education_level,NEW.education_institution,NEW.graduation_year)
   IS DISTINCT FROM ROW(OLD.identity_id,OLD.team_id,OLD.email,OLD.full_name,OLD.phone,OLD.phone_normalized,OLD.bank_account_encrypted,OLD.address_ktp,OLD.address_current,OLD.birth_place,OLD.birth_date,OLD.education_level,OLD.education_institution,OLD.graduation_year);
  IF changed AND NEW.status <> 'PENDING' THEN RAISE EXCEPTION 'F2 correct before terminal transition' USING ERRCODE = '23514'; END IF;
  IF changed THEN
   NEW.biodata_revision := OLD.biodata_revision + 1;
   NEW.biodata_verified_revision := NULL; NEW.biodata_verified_at := NULL; NEW.biodata_verified_by := NULL; NEW.biodata_verified_actor_id := NULL;
  ELSIF NEW.biodata_revision <> OLD.biodata_revision THEN
   RAISE EXCEPTION 'F2 unsupported biodata revision' USING ERRCODE = '23514';
  END IF;
  IF NEW.identity_id IS DISTINCT FROM OLD.identity_id THEN NEW.document_revision := OLD.document_revision + 1; END IF;
  IF NEW.document_revision <> OLD.document_revision THEN
   IF NEW.document_revision <> OLD.document_revision + 1 THEN RAISE EXCEPTION 'F2 document revision must increase one' USING ERRCODE = '23514'; END IF;
   NEW.documents_verified_revision := NULL; NEW.documents_verified_at := NULL; NEW.documents_verified_by := NULL; NEW.documents_verified_actor_id := NULL;
  END IF;
 END IF;
 -- Application actions also pre-lock all identities in this order.
 IF TG_OP = 'UPDATE' THEN
  PERFORM id FROM public.person_identities WHERE id IN (OLD.identity_id,NEW.identity_id) ORDER BY id FOR UPDATE;
 ELSE
  IF OLD.email IS NOT NULL AND (NEW.email IS NULL OR btrim(NEW.email)='') THEN
   RAISE EXCEPTION 'Email submission tidak boleh dikosongkan' USING ERRCODE='23514';
  END IF;
  PERFORM id FROM public.person_identities WHERE id = NEW.identity_id FOR UPDATE;
 END IF;
 IF TG_OP = 'INSERT' OR NEW.identity_id IS DISTINCT FROM OLD.identity_id OR NEW.status = 'APPROVED' THEN
  IF NOT EXISTS (SELECT 1 FROM public.person_identities WHERE id = NEW.identity_id AND reconciliation_state = 'RESOLVED')
  THEN RAISE EXCEPTION 'F2 unresolved identity cannot acquire or approve' USING ERRCODE = '23514'; END IF;
 END IF;
 IF TG_OP = 'INSERT' OR NEW.team_id IS DISTINCT FROM OLD.team_id OR NEW.status = 'APPROVED' THEN
  PERFORM team_id FROM public.team_profiles WHERE team_id = NEW.team_id AND is_active FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'F2 Team must be active' USING ERRCODE = '23514'; END IF;
 END IF;
 RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION public.f2_core_membership_guard()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'pg_catalog'
AS $function$
DECLARE source_identity bigint; counter_value bigint;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'F2 membership history deletion prohibited' USING ERRCODE = '23514'; END IF;
 IF TG_OP = 'UPDATE' THEN
  -- Generated columns are computed after BEFORE triggers: compare explicit base columns only.
  IF ROW(NEW.id,NEW.uuid,NEW.origin,NEW.identity_id,NEW.submission_id,NEW.legacy_fp_id,NEW.nip,NEW.nip_year,NEW.nip_serial,NEW.created_at)
   IS DISTINCT FROM ROW(OLD.id,OLD.uuid,OLD.origin,OLD.identity_id,OLD.submission_id,OLD.legacy_fp_id,OLD.nip,OLD.nip_year,OLD.nip_serial,OLD.created_at)
  THEN RAISE EXCEPTION 'F2 membership ownership/NIP immutable' USING ERRCODE = '23514'; END IF;
  IF OLD.lifecycle_state = 'EXITED' THEN
   IF ROW(NEW.id,NEW.uuid,NEW.origin,NEW.identity_id,NEW.legacy_fp_id,NEW.submission_id,NEW.nip,NEW.nip_year,NEW.nip_serial,NEW.lifecycle_state,NEW.email,NEW.full_name,NEW.phone,NEW.phone_normalized,NEW.bank_account_encrypted,NEW.address_ktp,NEW.address_current,NEW.birth_place,NEW.birth_date,NEW.education_level,NEW.education_institution,NEW.graduation_year,NEW.team_id,NEW.reference_date,NEW.business_timezone,NEW.approved_at,NEW.approved_actor_id,NEW.source_type,NEW.source_team_id,NEW.source_fp_id,NEW.source_name_snapshot,NEW.legacy_status_raw,NEW.legacy_source_raw,NEW.legacy_reference_date,NEW.created_at,NEW.updated_at) IS DISTINCT FROM ROW(OLD.id,OLD.uuid,OLD.origin,OLD.identity_id,OLD.legacy_fp_id,OLD.submission_id,OLD.nip,OLD.nip_year,OLD.nip_serial,OLD.lifecycle_state,OLD.email,OLD.full_name,OLD.phone,OLD.phone_normalized,OLD.bank_account_encrypted,OLD.address_ktp,OLD.address_current,OLD.birth_place,OLD.birth_date,OLD.education_level,OLD.education_institution,OLD.graduation_year,OLD.team_id,OLD.reference_date,OLD.business_timezone,OLD.approved_at,OLD.approved_actor_id,OLD.source_type,OLD.source_team_id,OLD.source_fp_id,OLD.source_name_snapshot,OLD.legacy_status_raw,OLD.legacy_source_raw,OLD.legacy_reference_date,OLD.created_at,OLD.updated_at) OR (NEW.approved_by IS DISTINCT FROM OLD.approved_by AND NEW.approved_by IS NOT NULL)
   THEN RAISE EXCEPTION 'F2 EXITED membership immutable' USING ERRCODE = '23514'; END IF;
   RETURN NEW;
  END IF;
  IF ROW(NEW.id,NEW.uuid,NEW.origin,NEW.identity_id,NEW.legacy_fp_id,NEW.submission_id,NEW.nip,NEW.nip_year,NEW.nip_serial,NEW.email,NEW.full_name,NEW.phone,NEW.phone_normalized,NEW.bank_account_encrypted,NEW.address_ktp,NEW.address_current,NEW.birth_place,NEW.birth_date,NEW.education_level,NEW.education_institution,NEW.graduation_year,NEW.team_id,NEW.reference_date,NEW.business_timezone,NEW.approved_at,NEW.approved_actor_id,NEW.source_type,NEW.source_team_id,NEW.source_fp_id,NEW.source_name_snapshot,NEW.legacy_status_raw,NEW.legacy_source_raw,NEW.legacy_reference_date,NEW.created_at) IS DISTINCT FROM ROW(OLD.id,OLD.uuid,OLD.origin,OLD.identity_id,OLD.legacy_fp_id,OLD.submission_id,OLD.nip,OLD.nip_year,OLD.nip_serial,OLD.email,OLD.full_name,OLD.phone,OLD.phone_normalized,OLD.bank_account_encrypted,OLD.address_ktp,OLD.address_current,OLD.birth_place,OLD.birth_date,OLD.education_level,OLD.education_institution,OLD.graduation_year,OLD.team_id,OLD.reference_date,OLD.business_timezone,OLD.approved_at,OLD.approved_actor_id,OLD.source_type,OLD.source_team_id,OLD.source_fp_id,OLD.source_name_snapshot,OLD.legacy_status_raw,OLD.legacy_source_raw,OLD.legacy_reference_date,OLD.created_at)
   OR (NEW.approved_by IS DISTINCT FROM OLD.approved_by AND NEW.approved_by IS NOT NULL)
  THEN RAISE EXCEPTION 'F2 membership snapshots immutable' USING ERRCODE = '23514'; END IF;
  IF OLD.lifecycle_state = 'ACTIVE' AND NEW.lifecycle_state IS DISTINCT FROM 'ACTIVE' AND NEW.lifecycle_state IS DISTINCT FROM 'EXITED'
  THEN RAISE EXCEPTION 'F2 ACTIVE only exits' USING ERRCODE = '23514'; END IF;
 ELSE
  IF NEW.origin = 'NEW' AND NEW.lifecycle_state IS DISTINCT FROM 'ACTIVE'
  THEN RAISE EXCEPTION 'F2 NEW membership must begin ACTIVE' USING ERRCODE = '23514'; END IF;
 END IF;
 IF NEW.lifecycle_state = 'ACTIVE' AND (TG_OP = 'INSERT' OR OLD.lifecycle_state IS DISTINCT FROM 'ACTIVE') THEN
  SELECT identity_id INTO source_identity FROM public.financial_planners WHERE id = NEW.source_fp_id;
  PERFORM id FROM public.person_identities WHERE id IN (NEW.identity_id,source_identity) ORDER BY id FOR UPDATE;
  IF NOT EXISTS (SELECT 1 FROM public.person_identities WHERE id = NEW.identity_id AND reconciliation_state = 'RESOLVED')
  THEN RAISE EXCEPTION 'F2 unresolved identity cannot become ACTIVE' USING ERRCODE = '23514'; END IF;
 END IF;
 IF TG_OP = 'INSERT' AND NEW.origin = 'NEW' THEN
  PERFORM team_id FROM public.team_profiles WHERE team_id IN (NEW.team_id,NEW.source_team_id) ORDER BY team_id FOR UPDATE;
  IF NOT EXISTS (SELECT 1 FROM public.team_profiles WHERE team_id = NEW.team_id AND is_active)
   OR (NEW.source_type = 'TEAM' AND NOT EXISTS (SELECT 1 FROM public.team_profiles WHERE team_id = NEW.source_team_id AND is_active))
  THEN RAISE EXCEPTION 'F2 approval Team invalid' USING ERRCODE = '23514'; END IF;
  IF NEW.source_type = 'FP' THEN
   PERFORM id FROM public.financial_planners WHERE id = NEW.source_fp_id AND lifecycle_state = 'ACTIVE' FOR UPDATE;
   IF NOT FOUND THEN RAISE EXCEPTION 'F2 source FP must be ACTIVE at approval' USING ERRCODE = '23514'; END IF;
  END IF;
  PERFORM id FROM public.fp_submissions WHERE id = NEW.submission_id AND identity_id = NEW.identity_id AND status = 'APPROVED' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'F2 membership requires approved matching submission' USING ERRCODE = '23514'; END IF;
  IF NEW.nip_year IS NULL OR NEW.nip_serial IS NULL THEN
   RAISE EXCEPTION 'Annual NIP year and serial required for new approval' USING ERRCODE='23514';
  END IF;
  SELECT next_serial INTO counter_value FROM public.fp_nip_year_counters WHERE year=NEW.nip_year FOR UPDATE;
  IF NOT FOUND OR NEW.nip_serial IS DISTINCT FROM counter_value THEN
   RAISE EXCEPTION 'NIP must match locked annual counter' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END;
$function$;

CREATE OR REPLACE FUNCTION public.f2_core_final_relationship()
 RETURNS trigger
 LANGUAGE plpgsql
 SECURITY DEFINER
 SET search_path TO 'pg_catalog'
AS $function$
DECLARE sid bigint; sub public.fp_submissions%ROWTYPE; member public.financial_planners%ROWTYPE;
BEGIN
 IF TG_TABLE_NAME = 'fp_submissions' THEN sid := NEW.id; ELSE sid := NEW.submission_id; END IF;
 IF TG_TABLE_NAME = 'financial_planners' THEN
  SELECT * INTO member FROM public.financial_planners WHERE id = NEW.id;
  IF member.lifecycle_state = 'ACTIVE' AND NOT EXISTS (SELECT 1 FROM public.fp_identity_claims WHERE identity_id = member.identity_id AND kind = 'ACTIVE' AND membership_id = member.id)
  THEN RAISE EXCEPTION 'F2 ACTIVE needs matching claim' USING ERRCODE = '23514'; END IF;
  IF member.origin='NEW' THEN
   IF member.nip_year IS NULL THEN
    IF NOT EXISTS(SELECT 1 FROM public.nip_sequences WHERE id=1 AND next_value>member.nip) THEN
     RAISE EXCEPTION 'Historical NIP requires original counter evidence' USING ERRCODE='23514';
    END IF;
   ELSE
    IF NOT EXISTS(SELECT 1 FROM public.fp_nip_year_counters WHERE year=member.nip_year AND next_serial>member.nip_serial) THEN
     RAISE EXCEPTION 'Annual NIP requires committed counter advance' USING ERRCODE='23514';
    END IF;
   END IF;
  END IF;
 END IF;
 IF sid IS NULL THEN RETURN NULL; END IF;
 SELECT * INTO sub FROM public.fp_submissions WHERE id = sid;
 SELECT * INTO member FROM public.financial_planners WHERE submission_id = sid;
 IF sub.status = 'APPROVED' THEN
  IF member.id IS NULL OR member.origin <> 'NEW'
   OR ROW(member.identity_id,member.team_id,member.reference_date,member.business_timezone,member.approved_at,member.approved_actor_id,
    member.email,member.full_name,member.phone,member.phone_normalized,member.bank_account_encrypted,member.address_ktp,member.address_current,member.birth_place,member.birth_date,member.education_level,member.education_institution,member.graduation_year)
   IS DISTINCT FROM ROW(sub.identity_id,sub.team_id,sub.reference_date,sub.business_timezone,sub.approved_at,sub.approved_actor_id,
    sub.email,sub.full_name,sub.phone,sub.phone_normalized,sub.bank_account_encrypted,sub.address_ktp,sub.address_current,sub.birth_place,sub.birth_date,sub.education_level,sub.education_institution,sub.graduation_year)
  THEN RAISE EXCEPTION 'F2 approval requires exact matching membership snapshot' USING ERRCODE = '23514'; END IF;
 ELSIF member.id IS NOT NULL THEN
  RAISE EXCEPTION 'F2 unapproved submission cannot own membership' USING ERRCODE = '23514';
 END IF;
 IF sub.status = 'PENDING' AND NOT EXISTS (SELECT 1 FROM public.fp_identity_claims WHERE identity_id = sub.identity_id AND kind = 'PENDING' AND submission_id = sub.id)
 THEN RAISE EXCEPTION 'F2 PENDING needs matching claim' USING ERRCODE = '23514'; END IF;
 RETURN NULL;
END;
$function$;


-- PROPOSED, NOT EXECUTED. CREATE requires explicit role; no fallback admin.
CREATE OR REPLACE FUNCTION public.fp_victory_user_command(p_actor bigint, p_action text, p_target bigint, p_data jsonb)
 RETURNS bigint
 LANGUAGE plpgsql
 SECURITY DEFINER
 SET search_path TO 'pg_catalog', 'pg_temp'
AS $function$
DECLARE
 v_role text; v_target public.users%ROWTYPE; v_id bigint; v_hrd bigint; v_requested_role text;
 v_fields text[] := '{}'::text[]; v_permission text;
 v_allow text[] := ARRAY['f2.view','f2.pii','f2.biodata','f2.documents','f2.approve','f2.reject','f2.exit','f2.teams','f3.jalur.view','f3.jalur.manage','f3.reports.view','f3.reports.export'];
 v_permissions text[]; v_old text[];
BEGIN
 IF p_action IS NULL OR p_action NOT IN ('CREATE','EDIT','PERMISSIONS') OR p_data IS NULL OR pg_catalog.jsonb_typeof(p_data)<>'object'
 THEN RAISE EXCEPTION USING ERRCODE='22023',MESSAGE='Invalid Victory command'; END IF;
 IF p_action IN ('CREATE','EDIT') AND pg_catalog.jsonb_typeof(p_data->'email')='string' THEN
  p_data := pg_catalog.jsonb_set(p_data,'{email}',pg_catalog.to_jsonb(pg_catalog.lower(p_data->>'email')));
 END IF;
 -- Actor comes from Laravel authentication. Shared DB login is not an actor identity.
 -- Serialize bounded user-management transactions, never lock FP/NIP/claims.
 PERFORM pg_catalog.pg_advisory_xact_lock(736036,1);
 PERFORM 1 FROM public.users WHERE id=p_actor AND is_active FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION USING ERRCODE='42501',MESSAGE='Victory actor denied'; END IF;
 SELECT r.name INTO v_role FROM public.model_has_roles m JOIN public.roles r ON r.id=m.role_id
 WHERE m.model_id=p_actor AND m.model_type='App\Models\User' AND r.guard_name='web'
 AND r.name IN ('admin','hrd','ro','bm')
 ORDER BY CASE r.name WHEN 'admin' THEN 1 WHEN 'hrd' THEN 2 WHEN 'ro' THEN 3 ELSE 4 END LIMIT 1;
 IF v_role IS NULL OR v_role='bm' OR (p_action='PERMISSIONS' AND v_role<>'admin')
 THEN RAISE EXCEPTION USING ERRCODE='42501',MESSAGE='Victory actor denied'; END IF;

 IF p_action='CREATE' THEN
  IF p_target IS NOT NULL OR (p_data - ARRAY['name','email','password_hash','role']) <> '{}'::jsonb
  OR NOT (p_data ?& ARRAY['name','email','password_hash'])
  OR pg_catalog.jsonb_typeof(p_data->'name')<>'string' OR pg_catalog.jsonb_typeof(p_data->'email')<>'string'
  OR pg_catalog.jsonb_typeof(p_data->'password_hash')<>'string'
  OR length(btrim(p_data->>'name')) NOT BETWEEN 1 AND 255 OR length(p_data->>'email') NOT BETWEEN 3 AND 255
  OR (p_data->>'email') !~ '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$'
  OR (p_data->>'password_hash') !~ '^\$2[aby]\$[0-9]{2}\$[./A-Za-z0-9]{53}$'
  THEN RAISE EXCEPTION USING ERRCODE='22023',MESSAGE='Invalid Victory create fields'; END IF;
  IF pg_catalog.jsonb_typeof(p_data->'role') IS DISTINCT FROM 'string'
  THEN RAISE EXCEPTION USING ERRCODE='22023',MESSAGE='Explicit Victory role required'; END IF;
  v_requested_role := p_data->>'role';
  IF v_requested_role NOT IN ('admin','hrd','ro','bm') OR (v_requested_role='admin' AND v_role<>'admin')
  THEN RAISE EXCEPTION USING ERRCODE='42501',MESSAGE='Victory role assignment denied'; END IF;
  SELECT id INTO v_hrd FROM public.roles WHERE name=v_requested_role AND guard_name='web';
  IF v_hrd IS NULL THEN RAISE EXCEPTION USING ERRCODE='22023',MESSAGE='Existing selected web role required'; END IF;
  INSERT INTO public.users(name,email,password,fp_app_managed,created_at,updated_at)
  VALUES(btrim(p_data->>'name'),p_data->>'email',p_data->>'password_hash',true,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) RETURNING id INTO v_id;
  INSERT INTO public.model_has_roles(role_id,model_type,model_id) VALUES(v_hrd,'App\Models\User',v_id);
  INSERT INTO public.fp_user_events(actor_id,target_id,action) VALUES(p_actor,v_id,'CREATED');
  RETURN v_id;
 END IF;

 SELECT * INTO v_target FROM public.users WHERE id=p_target FOR UPDATE;
 IF NOT FOUND OR NOT v_target.fp_app_managed OR v_target.sso_subject IS NOT NULL
 OR NOT EXISTS(SELECT 1 FROM public.model_has_roles m JOIN public.roles r ON r.id=m.role_id
 WHERE m.model_type='App\Models\User' AND m.model_id=p_target AND r.guard_name='web' AND r.name IN ('admin','hrd','ro','bm'))
 OR EXISTS(SELECT 1 FROM public.model_has_roles m JOIN public.roles r ON r.id=m.role_id
 WHERE m.model_type='App\Models\User' AND m.model_id=p_target AND (r.guard_name<>'web' OR r.name NOT IN ('admin','hrd','ro','bm')))
 THEN RAISE EXCEPTION USING ERRCODE='42501',MESSAGE='Victory target denied'; END IF;

 IF p_action='EDIT' THEN
  IF (p_data - ARRAY['name','email'])<>'{}'::jsonb OR NOT (p_data ?& ARRAY['name','email'])
  OR pg_catalog.jsonb_typeof(p_data->'name')<>'string' OR pg_catalog.jsonb_typeof(p_data->'email')<>'string'
  OR length(btrim(p_data->>'name')) NOT BETWEEN 1 AND 255 OR length(p_data->>'email') NOT BETWEEN 3 AND 255
  OR (p_data->>'email') !~ '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$'
  THEN RAISE EXCEPTION USING ERRCODE='22023',MESSAGE='Invalid Victory edit fields'; END IF;
  IF v_target.name IS DISTINCT FROM btrim(p_data->>'name') THEN v_fields:=array_append(v_fields,'name'); END IF;
  IF v_target.email IS DISTINCT FROM p_data->>'email' THEN v_fields:=array_append(v_fields,'email'); END IF;
  UPDATE public.users SET name=btrim(p_data->>'name'),email=p_data->>'email',updated_at=CURRENT_TIMESTAMP WHERE id=p_target;
  INSERT INTO public.fp_user_events(actor_id,target_id,action,fields) VALUES(p_actor,p_target,'EDITED',v_fields);
 ELSE
  IF (p_data - ARRAY['permissions'])<>'{}'::jsonb OR NOT (p_data ? 'permissions')
  OR pg_catalog.jsonb_typeof(p_data->'permissions')<>'array'
  OR EXISTS(SELECT 1 FROM pg_catalog.jsonb_array_elements(p_data->'permissions') x WHERE pg_catalog.jsonb_typeof(x)<>'string')
  THEN RAISE EXCEPTION USING ERRCODE='22023',MESSAGE='Invalid Victory permission fields'; END IF;
  SELECT coalesce(array_agg(DISTINCT x),'{}'::text[]) INTO v_permissions FROM pg_catalog.jsonb_array_elements_text(p_data->'permissions') x;
  IF NOT (v_permissions <@ v_allow) OR EXISTS(SELECT 1 FROM unnest(v_permissions) x WHERE NOT EXISTS(SELECT 1 FROM public.permissions p WHERE p.name=x AND p.guard_name='web'))
  THEN RAISE EXCEPTION USING ERRCODE='42501',MESSAGE='Victory permission denied'; END IF;
  -- Never erase non-FP direct or inherited permissions from any account.
  IF EXISTS(SELECT 1 FROM public.model_has_permissions m JOIN public.permissions p ON p.id=m.permission_id
    WHERE m.model_id=p_target AND m.model_type='App\Models\User' AND (p.guard_name<>'web' OR NOT(p.name=ANY(v_allow))))
  OR EXISTS(SELECT 1 FROM public.model_has_roles m JOIN public.role_has_permissions rp ON rp.role_id=m.role_id JOIN public.permissions p ON p.id=rp.permission_id
    WHERE m.model_id=p_target AND m.model_type='App\Models\User' AND (p.guard_name<>'web' OR NOT(p.name=ANY(v_allow))))
  THEN RAISE EXCEPTION USING ERRCODE='42501',MESSAGE='Victory target permissions outside scope'; END IF;
  SELECT coalesce(array_agg(p.name),'{}'::text[]) INTO v_old FROM public.model_has_permissions m JOIN public.permissions p ON p.id=m.permission_id
   WHERE m.model_id=p_target AND m.model_type='App\Models\User' AND p.guard_name='web' AND p.name=ANY(v_allow);
  FOREACH v_permission IN ARRAY v_old LOOP
   IF NOT(v_permission=ANY(v_permissions)) THEN
    DELETE FROM public.model_has_permissions WHERE model_id=p_target AND model_type='App\Models\User'
      AND permission_id=(SELECT id FROM public.permissions WHERE name=v_permission AND guard_name='web');
    INSERT INTO public.fp_user_events(actor_id,target_id,action,permission_name) VALUES(p_actor,p_target,'PERMISSION_REVOKED',v_permission);
   END IF;
  END LOOP;
  FOREACH v_permission IN ARRAY v_permissions LOOP
   IF NOT(v_permission=ANY(v_old)) THEN
    INSERT INTO public.model_has_permissions(permission_id,model_type,model_id)
      SELECT id,'App\Models\User',p_target FROM public.permissions WHERE name=v_permission AND guard_name='web';
    INSERT INTO public.fp_user_events(actor_id,target_id,action,permission_name) VALUES(p_actor,p_target,'PERMISSION_GRANTED',v_permission);
   END IF;
  END LOOP;
 END IF;
 RETURN p_target;
END $function$;
