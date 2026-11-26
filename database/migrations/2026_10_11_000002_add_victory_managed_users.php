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
            throw new LogicException('Victory User core requires PostgreSQL');
        }
        DB::unprepared(self::SQL);
    }

    public function down(): void
    {
        throw new LogicException('No automatic destructive rollback for Victory User core');
    }

    public const SQL = <<<'SQL'
DO $$
BEGIN
 IF EXISTS(SELECT 1 FROM pg_catalog.pg_roles WHERE rolname=current_user AND (rolsuper OR rolbypassrls))
 THEN RAISE EXCEPTION 'Victory command owner must be non-superuser without BYPASSRLS'; END IF;
END $$;
ALTER TABLE public.users ADD COLUMN fp_app_managed boolean NOT NULL DEFAULT false;
CREATE TABLE public.fp_user_events(
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 actor_id bigint NOT NULL REFERENCES public.users(id) ON DELETE RESTRICT,
 target_id bigint NOT NULL REFERENCES public.users(id) ON DELETE RESTRICT,
 occurred_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 action text NOT NULL CHECK(action IN ('CREATED','EDITED','PERMISSION_GRANTED','PERMISSION_REVOKED')),
 fields text[] NOT NULL DEFAULT '{}'::text[] CHECK(fields <@ ARRAY['name','email']::text[]),
 permission_name text,
 CHECK((action IN ('PERMISSION_GRANTED','PERMISSION_REVOKED'))=(permission_name IS NOT NULL))
);
CREATE INDEX fp_user_events_target_time_idx ON public.fp_user_events(target_id,occurred_at,id);
CREATE FUNCTION public.fp_user_events_immutable() RETURNS trigger
LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
BEGIN RAISE EXCEPTION 'Victory User audit is append-only'; END $$;
CREATE TRIGGER fp_user_events_immutable BEFORE UPDATE OR DELETE ON public.fp_user_events FOR EACH ROW EXECUTE FUNCTION public.fp_user_events_immutable();

CREATE FUNCTION public.fp_victory_user_command(p_actor bigint,p_action text,p_target bigint,p_data jsonb)
RETURNS bigint LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp AS $$
DECLARE
 v_role text; v_target public.users%ROWTYPE; v_id bigint; v_hrd bigint;
 v_fields text[] := '{}'::text[]; v_permission text;
 v_allow text[] := ARRAY['f2.view','f2.pii','f2.biodata','f2.documents','f2.approve','f2.reject','f2.exit','f2.teams','f3.jalur.view','f3.jalur.manage','f3.reports.view','f3.reports.export'];
 v_permissions text[]; v_old text[];
BEGIN
 IF p_action IS NULL OR p_action NOT IN ('CREATE','EDIT','PERMISSIONS') OR p_data IS NULL OR pg_catalog.jsonb_typeof(p_data)<>'object'
 THEN RAISE EXCEPTION USING ERRCODE='22023',MESSAGE='Invalid Victory command'; END IF;
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
  IF p_target IS NOT NULL OR (p_data - ARRAY['name','email','password_hash']) <> '{}'::jsonb
  OR NOT (p_data ?& ARRAY['name','email','password_hash'])
  OR pg_catalog.jsonb_typeof(p_data->'name')<>'string' OR pg_catalog.jsonb_typeof(p_data->'email')<>'string'
  OR pg_catalog.jsonb_typeof(p_data->'password_hash')<>'string'
  OR length(btrim(p_data->>'name')) NOT BETWEEN 1 AND 255 OR length(p_data->>'email') NOT BETWEEN 3 AND 255
  OR (p_data->>'email') !~ '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$'
  OR (p_data->>'password_hash') !~ '^\$2[aby]\$[0-9]{2}\$[./A-Za-z0-9]{53}$'
  THEN RAISE EXCEPTION USING ERRCODE='22023',MESSAGE='Invalid Victory create fields'; END IF;
  SELECT id INTO v_hrd FROM public.roles WHERE name='hrd' AND guard_name='web';
  IF v_hrd IS NULL THEN RAISE EXCEPTION USING ERRCODE='22023',MESSAGE='Existing HRD role required'; END IF;
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
END $$;
REVOKE ALL ON FUNCTION public.fp_victory_user_command(bigint,text,bigint,jsonb) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.fp_user_events_immutable() FROM PUBLIC;
SQL;
};
