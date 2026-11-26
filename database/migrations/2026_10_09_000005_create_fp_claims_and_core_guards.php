<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public const SQL = <<<'SQL'
CREATE TABLE public.fp_identity_claims (
 identity_id bigint CONSTRAINT f2_fp_identity_claims_pk PRIMARY KEY,
 kind varchar(8) NOT NULL,
 submission_id bigint CONSTRAINT f2_fp_identity_claims_submission_uq UNIQUE,
 membership_id bigint CONSTRAINT f2_fp_identity_claims_membership_uq UNIQUE,
 CONSTRAINT f2_fp_identity_claims_identity_fk FOREIGN KEY (identity_id) REFERENCES public.person_identities(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT f2_fp_identity_claims_submission_fk FOREIGN KEY (identity_id,submission_id) REFERENCES public.fp_submissions(identity_id,id) ON DELETE RESTRICT ON UPDATE NO ACTION DEFERRABLE INITIALLY DEFERRED,
 CONSTRAINT f2_fp_identity_claims_membership_fk FOREIGN KEY (identity_id,membership_id) REFERENCES public.financial_planners(identity_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT f2_fp_identity_claims_kind_ck CHECK (
  (kind = 'PENDING' AND submission_id IS NOT NULL AND membership_id IS NULL)
  OR (kind = 'ACTIVE' AND submission_id IS NULL AND membership_id IS NOT NULL)
 )
);
-- Default grants must never provide direct claim writes to the application role.
REVOKE ALL ON TABLE public.fp_identity_claims FROM PUBLIC;

CREATE FUNCTION public.f2_core_identity_guard() RETURNS trigger
LANGUAGE plpgsql SET search_path = pg_catalog AS $f2$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'F2 identity deletion prohibited' USING ERRCODE = '23514'; END IF;
 IF ROW(NEW.id,NEW.uuid,NEW.nik_encrypted,NEW.nik_lookup_hash,NEW.lookup_key_id,NEW.created_at)
  IS DISTINCT FROM ROW(OLD.id,OLD.uuid,OLD.nik_encrypted,OLD.nik_lookup_hash,OLD.lookup_key_id,OLD.created_at)
 THEN RAISE EXCEPTION 'F2 identity ownership/key immutable' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END;
$f2$;
CREATE TRIGGER f2_core_identity_guard_tr BEFORE UPDATE OR DELETE ON public.person_identities FOR EACH ROW EXECUTE FUNCTION public.f2_core_identity_guard();

CREATE FUNCTION public.f2_core_submission_guard() RETURNS trigger
LANGUAGE plpgsql SET search_path = pg_catalog AS $f2$
DECLARE changed boolean;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'F2 submission history deletion prohibited' USING ERRCODE = '23514'; END IF;
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'PENDING' THEN RAISE EXCEPTION 'F2 submission must begin PENDING' USING ERRCODE = '23514'; END IF;
 ELSE
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
  changed := ROW(NEW.identity_id,NEW.team_id,NEW.full_name,NEW.phone,NEW.phone_normalized,NEW.bank_account_encrypted,NEW.address_ktp,NEW.address_current,NEW.birth_place,NEW.birth_date,NEW.education_level,NEW.education_institution,NEW.graduation_year)
   IS DISTINCT FROM ROW(OLD.identity_id,OLD.team_id,OLD.full_name,OLD.phone,OLD.phone_normalized,OLD.bank_account_encrypted,OLD.address_ktp,OLD.address_current,OLD.birth_place,OLD.birth_date,OLD.education_level,OLD.education_institution,OLD.graduation_year);
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
$f2$;
CREATE TRIGGER f2_core_submission_guard_tr BEFORE INSERT OR UPDATE OR DELETE ON public.fp_submissions FOR EACH ROW EXECUTE FUNCTION public.f2_core_submission_guard();

CREATE FUNCTION public.f2_core_membership_guard() RETURNS trigger
LANGUAGE plpgsql SET search_path = pg_catalog AS $f2$
DECLARE source_identity bigint; counter_value bigint;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'F2 membership history deletion prohibited' USING ERRCODE = '23514'; END IF;
 IF TG_OP = 'UPDATE' THEN
  -- Generated columns are computed after BEFORE triggers: compare explicit base columns only.
  IF ROW(NEW.id,NEW.uuid,NEW.origin,NEW.identity_id,NEW.submission_id,NEW.legacy_fp_id,NEW.nip,NEW.created_at)
   IS DISTINCT FROM ROW(OLD.id,OLD.uuid,OLD.origin,OLD.identity_id,OLD.submission_id,OLD.legacy_fp_id,OLD.nip,OLD.created_at)
  THEN RAISE EXCEPTION 'F2 membership ownership/NIP immutable' USING ERRCODE = '23514'; END IF;
  IF OLD.lifecycle_state = 'EXITED' THEN
   IF ROW(NEW.id,NEW.uuid,NEW.origin,NEW.identity_id,NEW.legacy_fp_id,NEW.submission_id,NEW.nip,NEW.lifecycle_state,NEW.full_name,NEW.phone,NEW.phone_normalized,NEW.bank_account_encrypted,NEW.address_ktp,NEW.address_current,NEW.birth_place,NEW.birth_date,NEW.education_level,NEW.education_institution,NEW.graduation_year,NEW.team_id,NEW.reference_date,NEW.business_timezone,NEW.approved_at,NEW.approved_actor_id,NEW.source_type,NEW.source_team_id,NEW.source_fp_id,NEW.source_name_snapshot,NEW.legacy_status_raw,NEW.legacy_source_raw,NEW.legacy_reference_date,NEW.created_at,NEW.updated_at) IS DISTINCT FROM ROW(OLD.id,OLD.uuid,OLD.origin,OLD.identity_id,OLD.legacy_fp_id,OLD.submission_id,OLD.nip,OLD.lifecycle_state,OLD.full_name,OLD.phone,OLD.phone_normalized,OLD.bank_account_encrypted,OLD.address_ktp,OLD.address_current,OLD.birth_place,OLD.birth_date,OLD.education_level,OLD.education_institution,OLD.graduation_year,OLD.team_id,OLD.reference_date,OLD.business_timezone,OLD.approved_at,OLD.approved_actor_id,OLD.source_type,OLD.source_team_id,OLD.source_fp_id,OLD.source_name_snapshot,OLD.legacy_status_raw,OLD.legacy_source_raw,OLD.legacy_reference_date,OLD.created_at,OLD.updated_at) OR (NEW.approved_by IS DISTINCT FROM OLD.approved_by AND NEW.approved_by IS NOT NULL)
   THEN RAISE EXCEPTION 'F2 EXITED membership immutable' USING ERRCODE = '23514'; END IF;
   RETURN NEW;
  END IF;
  IF ROW(NEW.id,NEW.uuid,NEW.origin,NEW.identity_id,NEW.legacy_fp_id,NEW.submission_id,NEW.nip,NEW.full_name,NEW.phone,NEW.phone_normalized,NEW.bank_account_encrypted,NEW.address_ktp,NEW.address_current,NEW.birth_place,NEW.birth_date,NEW.education_level,NEW.education_institution,NEW.graduation_year,NEW.team_id,NEW.reference_date,NEW.business_timezone,NEW.approved_at,NEW.approved_actor_id,NEW.source_type,NEW.source_team_id,NEW.source_fp_id,NEW.source_name_snapshot,NEW.legacy_status_raw,NEW.legacy_source_raw,NEW.legacy_reference_date,NEW.created_at) IS DISTINCT FROM ROW(OLD.id,OLD.uuid,OLD.origin,OLD.identity_id,OLD.legacy_fp_id,OLD.submission_id,OLD.nip,OLD.full_name,OLD.phone,OLD.phone_normalized,OLD.bank_account_encrypted,OLD.address_ktp,OLD.address_current,OLD.birth_place,OLD.birth_date,OLD.education_level,OLD.education_institution,OLD.graduation_year,OLD.team_id,OLD.reference_date,OLD.business_timezone,OLD.approved_at,OLD.approved_actor_id,OLD.source_type,OLD.source_team_id,OLD.source_fp_id,OLD.source_name_snapshot,OLD.legacy_status_raw,OLD.legacy_source_raw,OLD.legacy_reference_date,OLD.created_at)
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
  SELECT next_value INTO counter_value FROM public.nip_sequences WHERE id = 1 FOR UPDATE;
  IF NOT FOUND OR NEW.nip IS DISTINCT FROM counter_value THEN RAISE EXCEPTION 'F2 NIP must match locked counter' USING ERRCODE = '23514'; END IF;
 END IF;
 RETURN NEW;
END;
$f2$;
CREATE TRIGGER f2_core_membership_guard_tr BEFORE INSERT OR UPDATE OR DELETE ON public.financial_planners FOR EACH ROW EXECUTE FUNCTION public.f2_core_membership_guard();

CREATE FUNCTION public.f2_core_counter_guard() RETURNS trigger
LANGUAGE plpgsql SET search_path = pg_catalog AS $f2$
BEGIN
 IF TG_OP <> 'UPDATE' THEN RAISE EXCEPTION 'F2 singleton counter cannot be inserted/deleted/reset' USING ERRCODE = '23514'; END IF;
 IF NEW.id <> OLD.id OR NEW.next_value <> OLD.next_value + 1
  OR NOT EXISTS (SELECT 1 FROM public.financial_planners WHERE origin = 'NEW' AND nip = OLD.next_value)
 THEN RAISE EXCEPTION 'F2 counter requires matching allocation and increment one' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END;
$f2$;
CREATE TRIGGER f2_core_counter_guard_tr BEFORE INSERT OR UPDATE OR DELETE ON public.nip_sequences FOR EACH ROW EXECUTE FUNCTION public.f2_core_counter_guard();

CREATE FUNCTION public.f2_core_maintain_claim() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS $f2$
DECLARE affected integer; acquire_claim boolean := false; identity_key bigint;
BEGIN
 IF TG_TABLE_NAME = 'fp_submissions' THEN
  IF TG_OP = 'UPDATE' AND OLD.status = 'PENDING' AND (NEW.status <> 'PENDING' OR NEW.identity_id <> OLD.identity_id) THEN
   DELETE FROM public.fp_identity_claims WHERE identity_id = OLD.identity_id AND kind = 'PENDING' AND submission_id = OLD.id;
   GET DIAGNOSTICS affected = ROW_COUNT;
   IF affected <> 1 THEN RAISE EXCEPTION 'F2 matching pending claim missing' USING ERRCODE = '23514'; END IF;
  END IF;
  acquire_claim := NEW.status = 'PENDING' AND (TG_OP = 'INSERT' OR NEW.identity_id <> OLD.identity_id);
  identity_key := NEW.identity_id;
 ELSE
  IF TG_OP = 'UPDATE' AND OLD.lifecycle_state = 'ACTIVE' AND NEW.lifecycle_state IS DISTINCT FROM 'ACTIVE' THEN
   DELETE FROM public.fp_identity_claims WHERE identity_id = OLD.identity_id AND kind = 'ACTIVE' AND membership_id = OLD.id;
   GET DIAGNOSTICS affected = ROW_COUNT;
   IF affected <> 1 THEN RAISE EXCEPTION 'F2 matching active claim missing' USING ERRCODE = '23514'; END IF;
  END IF;
  acquire_claim := NEW.lifecycle_state = 'ACTIVE' AND (TG_OP = 'INSERT' OR OLD.lifecycle_state IS DISTINCT FROM 'ACTIVE');
  identity_key := NEW.identity_id;
 END IF;
 IF acquire_claim THEN
  PERFORM id FROM public.person_identities WHERE id = identity_key AND reconciliation_state = 'RESOLVED' FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'F2 REVIEW_REQUIRED cannot acquire claim' USING ERRCODE = '23514'; END IF;
  IF TG_TABLE_NAME = 'fp_submissions' THEN
   INSERT INTO public.fp_identity_claims(identity_id,kind,submission_id) VALUES (NEW.identity_id,'PENDING',NEW.id);
  ELSE
   INSERT INTO public.fp_identity_claims(identity_id,kind,membership_id) VALUES (NEW.identity_id,'ACTIVE',NEW.id);
  END IF;
 END IF;
 RETURN NEW;
END;
$f2$;
CREATE TRIGGER f2_core_submission_claim_tr AFTER INSERT OR UPDATE ON public.fp_submissions FOR EACH ROW EXECUTE FUNCTION public.f2_core_maintain_claim();
CREATE TRIGGER f2_core_membership_claim_tr AFTER INSERT OR UPDATE ON public.financial_planners FOR EACH ROW EXECUTE FUNCTION public.f2_core_maintain_claim();

CREATE FUNCTION public.f2_core_final_relationship() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS $f2$
DECLARE sid bigint; sub public.fp_submissions%ROWTYPE; member public.financial_planners%ROWTYPE;
BEGIN
 IF TG_TABLE_NAME = 'fp_submissions' THEN sid := NEW.id; ELSE sid := NEW.submission_id; END IF;
 IF TG_TABLE_NAME = 'financial_planners' THEN
  SELECT * INTO member FROM public.financial_planners WHERE id = NEW.id;
  IF member.lifecycle_state = 'ACTIVE' AND NOT EXISTS (SELECT 1 FROM public.fp_identity_claims WHERE identity_id = member.identity_id AND kind = 'ACTIVE' AND membership_id = member.id)
  THEN RAISE EXCEPTION 'F2 ACTIVE needs matching claim' USING ERRCODE = '23514'; END IF;
  IF member.origin = 'NEW' AND NOT EXISTS (SELECT 1 FROM public.nip_sequences WHERE id = 1 AND next_value > member.nip)
  THEN RAISE EXCEPTION 'F2 allocation requires committed counter advance' USING ERRCODE = '23514'; END IF;
 END IF;
 IF sid IS NULL THEN RETURN NULL; END IF;
 SELECT * INTO sub FROM public.fp_submissions WHERE id = sid;
 SELECT * INTO member FROM public.financial_planners WHERE submission_id = sid;
 IF sub.status = 'APPROVED' THEN
  IF member.id IS NULL OR member.origin <> 'NEW'
   OR ROW(member.identity_id,member.team_id,member.reference_date,member.business_timezone,member.approved_at,member.approved_actor_id,
    member.full_name,member.phone,member.phone_normalized,member.bank_account_encrypted,member.address_ktp,member.address_current,member.birth_place,member.birth_date,member.education_level,member.education_institution,member.graduation_year)
   IS DISTINCT FROM ROW(sub.identity_id,sub.team_id,sub.reference_date,sub.business_timezone,sub.approved_at,sub.approved_actor_id,
    sub.full_name,sub.phone,sub.phone_normalized,sub.bank_account_encrypted,sub.address_ktp,sub.address_current,sub.birth_place,sub.birth_date,sub.education_level,sub.education_institution,sub.graduation_year)
  THEN RAISE EXCEPTION 'F2 approval requires exact matching membership snapshot' USING ERRCODE = '23514'; END IF;
 ELSIF member.id IS NOT NULL THEN
  RAISE EXCEPTION 'F2 unapproved submission cannot own membership' USING ERRCODE = '23514';
 END IF;
 IF sub.status = 'PENDING' AND NOT EXISTS (SELECT 1 FROM public.fp_identity_claims WHERE identity_id = sub.identity_id AND kind = 'PENDING' AND submission_id = sub.id)
 THEN RAISE EXCEPTION 'F2 PENDING needs matching claim' USING ERRCODE = '23514'; END IF;
 RETURN NULL;
END;
$f2$;
CREATE CONSTRAINT TRIGGER f2_core_submission_final_tr AFTER INSERT OR UPDATE ON public.fp_submissions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.f2_core_final_relationship();
CREATE CONSTRAINT TRIGGER f2_core_membership_final_tr AFTER INSERT OR UPDATE ON public.financial_planners DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.f2_core_final_relationship();

REVOKE ALL ON FUNCTION public.f2_core_identity_guard() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.f2_core_submission_guard() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.f2_core_membership_guard() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.f2_core_counter_guard() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.f2_core_maintain_claim() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.f2_core_final_relationship() FROM PUBLIC;
-- F2.2 must add actual READY-document, immutable exit completeness and audit invariants before activation.
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
