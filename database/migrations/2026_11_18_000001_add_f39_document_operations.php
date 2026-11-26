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
            throw new LogicException('F39 verified disposable transaction required');
        }
        DB::unprepared(<<<'SQL'
CREATE TABLE public.fp_document_operations (
 uuid uuid PRIMARY KEY,
 operation_type text NOT NULL CHECK(operation_type='ADMIN_DOCUMENT_AMENDMENT'),
 membership_id bigint NOT NULL REFERENCES public.financial_planners(id) ON DELETE RESTRICT,
 type text NOT NULL CHECK(type IN ('KTP','IJAZAH')),
 actor_id bigint NOT NULL REFERENCES public.users(id) ON DELETE RESTRICT,
 idempotency_hash char(64) NOT NULL CHECK(idempotency_hash ~ '^[0-9a-f]{64}$'),
 request_hash char(64) NOT NULL CHECK(request_hash ~ '^[0-9a-f]{64}$'),
 expected_revision bigint NOT NULL CHECK(expected_revision>=0),
 target_revision bigint NOT NULL CHECK(target_revision=expected_revision+1),
 revision_uuid uuid NOT NULL UNIQUE,
 storage_adapter text NOT NULL DEFAULT 'nextcloud' CHECK(storage_adapter='nextcloud'),
 private_path text NOT NULL UNIQUE CHECK(private_path ~ '^documents/[0-9a-f-]{36}/[0-9a-f-]{36}/revision-[0-9a-f-]{36}/document[.]bin$'),
 mime text NOT NULL CHECK(mime IN ('image/jpeg','image/png')),
 bytes bigint NOT NULL CHECK(bytes BETWEEN 1 AND 8388608),
 sha256 char(64) NOT NULL CHECK(sha256 ~ '^[0-9a-f]{64}$'),
 state text NOT NULL DEFAULT 'PENDING' CHECK(state IN ('PENDING','UPLOADING','UPLOADED','VERIFYING','COMMITTED','FAILED','QUARANTINED')),
 attempts integer NOT NULL DEFAULT 0 CHECK(attempts>=0),
 fence bigint NOT NULL DEFAULT 0 CHECK(fence>=0),
 lease_owner uuid,
 lease_until timestamptz,
 error_code text CHECK(error_code IN ('REMOTE_MISSING','REMOTE_UNKNOWN','AUTHENTICATION_FAILED','INTEGRITY_MISMATCH','REVISION_CONFLICT','STAGING_INVALID','AUTHORIZATION_DENIED','COMMIT_UNCERTAIN')),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 verified_at timestamptz,
 committed_at timestamptz,
 UNIQUE(actor_id,membership_id,type,operation_type,idempotency_hash),
 CHECK((lease_owner IS NULL)=(lease_until IS NULL)),
 CHECK((state='COMMITTED')=(committed_at IS NOT NULL)),
 CHECK(state<>'COMMITTED' OR verified_at IS NOT NULL)
);
CREATE INDEX f39_pending_operations_ix ON public.fp_document_operations(state,lease_until,created_at);
CREATE TABLE public.fp_document_operation_events (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 operation_uuid uuid NOT NULL REFERENCES public.fp_document_operations(uuid) ON DELETE RESTRICT,
 recovery_actor_id bigint NOT NULL REFERENCES public.users(id) ON DELETE RESTRICT,
 source_state text NOT NULL,
 target_state text NOT NULL,
 action text NOT NULL CHECK(action IN ('CREATED','CLAIMED','TRANSITION','DEFERRED')),
 reason text,
 fence bigint NOT NULL,
 transaction_id bigint NOT NULL DEFAULT txid_current(),
 occurred_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 CHECK(reason IS NULL OR reason IN ('REMOTE_MISSING','REMOTE_UNKNOWN','AUTHENTICATION_FAILED','INTEGRITY_MISMATCH','REVISION_CONFLICT','STAGING_INVALID','AUTHORIZATION_DENIED','COMMIT_UNCERTAIN'))
);
CREATE TRIGGER f39_event_immutable BEFORE UPDATE OR DELETE ON public.fp_document_operation_events FOR EACH ROW EXECUTE FUNCTION public.f37_append_only();
ALTER TABLE public.fp_document_amendments ADD COLUMN operation_uuid uuid UNIQUE REFERENCES public.fp_document_operations(uuid) ON DELETE RESTRICT;
ALTER TABLE public.fp_document_amendments ADD COLUMN integrity_verified_at timestamptz;
DO $f$
DECLARE item record; n integer:=0;
BEGIN
 FOR item IN SELECT conname FROM pg_constraint WHERE conrelid='public.fp_document_amendments'::regclass AND contype='c' AND (pg_get_constraintdef(oid) LIKE '%storage_adapter%' OR pg_get_constraintdef(oid) LIKE '%private_path%') LOOP
  EXECUTE format('ALTER TABLE public.fp_document_amendments DROP CONSTRAINT %I',item.conname); n:=n+1;
 END LOOP;
 IF n<>2 THEN RAISE EXCEPTION 'F39 unexpected amendment constraints'; END IF;
END;$f$;
ALTER TABLE public.fp_document_amendments ADD CONSTRAINT f39_revision_provider_ck CHECK(
 (storage_adapter='local' AND private_path ~ '^fp/[0-9a-f-]{36}/[0-9a-f-]{36}[.](jpg|png)$' AND operation_uuid IS NULL AND integrity_verified_at IS NULL)
 OR (storage_adapter='nextcloud' AND private_path ~ '^documents/[0-9a-f-]{36}/[0-9a-f-]{36}/revision-[0-9a-f-]{36}/document[.]bin$' AND operation_uuid IS NOT NULL AND integrity_verified_at IS NOT NULL)
);
CREATE UNIQUE INDEX f39_revision_object_uq ON public.fp_document_amendments(private_path) WHERE storage_adapter='nextcloud';
CREATE FUNCTION public.f39_operation_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $f$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'F39 operation evidence immutable'; END IF;
 IF TG_OP='UPDATE' THEN
  IF (to_jsonb(NEW)-ARRAY['state','attempts','fence','lease_owner','lease_until','error_code','updated_at','verified_at','committed_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','attempts','fence','lease_owner','lease_until','error_code','updated_at','verified_at','committed_at']) THEN RAISE EXCEPTION 'F39 operation content immutable'; END IF;
  IF OLD.state='COMMITTED' THEN RAISE EXCEPTION 'F39 committed operation immutable'; END IF;
  IF NEW.fence<OLD.fence OR NEW.attempts<OLD.attempts THEN RAISE EXCEPTION 'F39 monotonic lease required'; END IF;
  IF NEW.state<>OLD.state AND NOT (
   (OLD.state='PENDING' AND NEW.state IN ('UPLOADING','VERIFYING','FAILED','QUARANTINED')) OR
   (OLD.state='UPLOADING' AND NEW.state IN ('UPLOADED','VERIFYING','QUARANTINED')) OR
   (OLD.state='UPLOADED' AND NEW.state IN ('VERIFYING','QUARANTINED')) OR
   (OLD.state='VERIFYING' AND NEW.state IN ('COMMITTED','PENDING','FAILED','QUARANTINED')) OR
   (OLD.state='QUARANTINED' AND NEW.state='VERIFYING')
  ) THEN RAISE EXCEPTION 'F39 invalid operation transition'; END IF;
 END IF;
 RETURN NEW;
END;$f$;
CREATE TRIGGER f39_operation_guard_tr BEFORE UPDATE OR DELETE ON public.fp_document_operations FOR EACH ROW EXECUTE FUNCTION public.f39_operation_guard();
CREATE FUNCTION public.f39_revision_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $f$
DECLARE op public.fp_document_operations%ROWTYPE;
BEGIN
 IF NEW.operation_uuid IS NULL THEN RETURN NEW; END IF;
 SELECT * INTO op FROM public.fp_document_operations WHERE uuid=NEW.operation_uuid FOR UPDATE;
 IF NOT FOUND OR op.state<>'VERIFYING' OR op.verified_at IS NULL OR op.lease_until<=clock_timestamp()
  OR ROW(NEW.uuid,NEW.membership_id,NEW.type,NEW.revision,NEW.actor_id,NEW.storage_adapter,NEW.private_path,NEW.mime,NEW.bytes,NEW.sha256,NEW.integrity_verified_at)
   IS DISTINCT FROM ROW(op.revision_uuid,op.membership_id,op.type,op.target_revision,op.actor_id,op.storage_adapter,op.private_path,op.mime,op.bytes,op.sha256,op.verified_at)
 THEN RAISE EXCEPTION 'F39 verified operation tuple required'; END IF;
 RETURN NEW;
END;$f$;
CREATE TRIGGER f39_revision_guard_tr BEFORE INSERT ON public.fp_document_amendments FOR EACH ROW EXECUTE FUNCTION public.f39_revision_guard();
CREATE FUNCTION public.f39_final_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $f$
DECLARE op public.fp_document_operations%ROWTYPE; oid uuid;
BEGIN
 IF TG_TABLE_NAME='fp_document_operations' THEN oid:=NEW.uuid; ELSE oid:=NEW.operation_uuid; END IF;
 IF oid IS NULL THEN RETURN NULL; END IF;
 SELECT * INTO op FROM public.fp_document_operations WHERE uuid=oid;
 IF NOT EXISTS(SELECT 1 FROM public.fp_document_operation_events WHERE operation_uuid=oid AND fence=op.fence AND target_state=op.state AND transaction_id=txid_current()) THEN RAISE EXCEPTION 'F39 recovery audit required'; END IF;
 IF (op.state='COMMITTED') IS DISTINCT FROM EXISTS(SELECT 1 FROM public.fp_document_amendments WHERE operation_uuid=oid) THEN RAISE EXCEPTION 'F39 atomic revision commit required'; END IF;
 RETURN NULL;
END;$f$;
CREATE CONSTRAINT TRIGGER f39_final_operation AFTER INSERT OR UPDATE ON public.fp_document_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.f39_final_guard();
CREATE CONSTRAINT TRIGGER f39_final_revision AFTER INSERT ON public.fp_document_amendments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.f39_final_guard();
REVOKE ALL ON public.fp_document_operations,public.fp_document_operation_events FROM PUBLIC;
REVOKE ALL ON FUNCTION public.f39_operation_guard(),public.f39_revision_guard(),public.f39_final_guard() FROM PUBLIC;
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Forward-only operation evidence; test installation rollback inside disposable transaction');
    }
};
