<?php

declare(strict_types=1);

namespace Tests\FpPostgres;

use App\Application\FP\FeatureAccess;
use App\Application\FP\VictoryAccess;
use App\Application\FP\VictoryUsers;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\Group('victory-users')]
final class VictoryUsersPostgresTest extends TestCase
{
    private static array $actors = [];

    private static string $namespace;

    public static function setUpBeforeClass(): void
    {
        if (! PostgresHarness::available()) {
            self::markTestSkipped('Explicit disposable authorization required');
        }
        PostgresHarness::connect();
        config(['fp.user_management_enabled' => true]);
        self::$namespace = (string) \Illuminate\Support\Str::uuid();
        foreach (['admin', 'hrd', 'ro', 'bm'] as $role) {
            $rid = DB::table('roles')->where('name', $role)->where('guard_name', 'web')->value('id');
            if (! $rid) {
                $rid = DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            }
            $id = DB::table('users')->insertGetId(['name' => 'Disposable '.$role, 'email' => self::$namespace.'-'.$role.'@example.invalid', 'password' => password_hash('test-fixture-only', PASSWORD_BCRYPT), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('model_has_roles')->insert(['role_id' => $rid, 'model_type' => User::class, 'model_id' => $id]);
            self::$actors[$role] = $id;
        }
        foreach (FeatureAccess::PERMISSIONS as $name) {
            if (! DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->exists()) {
                DB::table('permissions')->insert(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table('model_has_permissions')->insert(['model_id' => self::$actors['bm'], 'model_type' => User::class, 'permission_id' => DB::table('permissions')->where('name', 'f3.reports.export')->where('guard_name', 'web')->value('id')]);
    }

    protected function setUp(): void
    {
        DB::statement('SET ROLE fp_dev_app');
    }

    protected function tearDown(): void
    {
        DB::statement('RESET ROLE');
    }

    private function actor(string $role): User
    {
        return User::findOrFail(self::$actors[$role]);
    }

    private function create(string $actor = 'hrd'): int
    {
        $secret = bin2hex(random_bytes(16));

        return (new VictoryUsers)->command($this->actor($actor), 'CREATE', null, ['name' => 'Disposable managed', 'email' => \Illuminate\Support\Str::uuid().'@example.invalid', 'password' => $secret, 'password_confirmation' => $secret]);
    }

    private function raw(int $actor, string $action, ?int $target, array $data): int
    {
        $s = DB::connection()->getPdo()->prepare('SELECT public.fp_victory_user_command(?,?,?,?::jsonb)');
        $s->execute([$actor, $action, $target, json_encode((object) $data, JSON_THROW_ON_ERROR)]);

        return (int) $s->fetchColumn();
    }

    private function denied(callable $work, string $state = '42501'): void
    {
        try {
            $work();
            self::fail('Forbidden command succeeded');
        } catch (\PDOException $e) {
            self::assertSame($state, $e->errorInfo[0]);
        }
    }

    public function test_four_role_matrix_and_bm_optional_permission_cannot_authorize_mutation(): void
    {
        foreach (['admin', 'hrd', 'ro', 'bm'] as $role) {
            $actor = $this->actor($role);
            foreach (['f2.view', 'f2.pii', 'f2.documents.view', 'f2.teams.view', 'f3.jalur.view', 'f3.reports.view', 'f3.users.view'] as $op) {
                self::assertTrue(VictoryAccess::allowed($actor, $op));
            }
            foreach (['f2.biodata', 'f2.documents', 'f2.approve', 'f2.reject', 'f2.exit', 'f2.teams', 'f3.jalur.manage', 'f3.reports.export', 'f3.users.create', 'f3.users.edit'] as $op) {
                self::assertSame($role !== 'bm', VictoryAccess::allowed($actor, $op));
            }
            self::assertSame($role === 'admin', VictoryAccess::allowed($actor, 'f3.users.permissions'));
            self::assertFalse((bool) $actor->fp_app_managed, 'Actor authorization must not depend on marker');
        }
    }

    public function test_admin_hrd_ro_create_edit_equal_rights_and_hashed_credentials(): void
    {
        foreach (['admin', 'hrd', 'ro'] as $role) {
            $id = $this->create($role);
            $user = User::findOrFail($id);
            self::assertTrue((bool) $user->fp_app_managed);
            self::assertNull($user->sso_subject);
            self::assertTrue($user->hasRole('hrd', 'web'));
            self::assertSame('bcrypt', password_get_info($user->getRawOriginal('password'))['algoName']);
            self::assertArrayNotHasKey('password', $user->toArray());
            (new VictoryUsers)->command($this->actor($role), 'EDIT', $id, ['name' => 'Changed '.$role, 'email' => $user->email]);
            self::assertSame('Changed '.$role, User::findOrFail($id)->name);
            $events = DB::table('fp_user_events')->where('target_id', $id)->orderBy('id')->get();
            self::assertSame(['CREATED', 'EDITED'], $events->pluck('action')->all());
            self::assertSame('{name}', $events[1]->fields);
            self::assertStringNotContainsString('password', json_encode($events));
        }
    }

    public function test_bm_direct_command_denied_and_service_mutations_denied(): void
    {
        $target = $this->create();
        foreach (['EDIT', 'PERMISSIONS', 'CREATE'] as $action) {
            $this->denied(fn () => $this->raw(self::$actors['bm'], $action, $action === 'CREATE' ? null : $target, []));
            try {
                (new VictoryUsers)->command($this->actor('bm'), $action, $target, []);
                self::fail('BM service mutation');
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(1, DB::table('fp_user_events')->where('target_id', $target)->count());
    }

    public function test_only_admin_manages_allowlisted_permissions_with_atomic_audit(): void
    {
        $target = $this->create();
        foreach (['hrd', 'ro', 'bm'] as $role) {
            $this->denied(fn () => $this->raw(self::$actors[$role], 'PERMISSIONS', $target, ['permissions' => ['f2.view']]));
        }
        (new VictoryUsers)->command($this->actor('admin'), 'PERMISSIONS', $target, ['permissions' => ['f2.view', 'f3.reports.view']]);
        self::assertSame(2, DB::table('model_has_permissions')->where('model_id', $target)->where('model_type', User::class)->count());
        (new VictoryUsers)->command($this->actor('admin'), 'PERMISSIONS', $target, ['permissions' => ['f2.view']]);
        self::assertSame(['CREATED', 'PERMISSION_GRANTED', 'PERMISSION_GRANTED', 'PERMISSION_REVOKED'], DB::table('fp_user_events')->where('target_id', $target)->orderBy('id')->pluck('action')->all());
        $this->denied(fn () => $this->raw(self::$actors['admin'], 'PERMISSIONS', $target, ['permissions' => ['legacy.admin']]));
        self::assertSame(1, DB::table('model_has_permissions')->where('model_id', $target)->where('model_type', User::class)->count());
    }

    public function test_unmanaged_and_sso_targets_are_denied_without_mutation(): void
    {
        $this->denied(fn () => $this->raw(self::$actors['hrd'], 'EDIT', self::$actors['ro'], ['name' => 'Forbidden', 'email' => 'forbidden@example.invalid']));
        $target = $this->create();
        DB::statement('RESET ROLE');
        DB::table('users')->where('id', $target)->update(['sso_subject' => 'disposable-sso']);
        DB::statement('SET ROLE fp_dev_app');
        $this->denied(fn () => $this->raw(self::$actors['admin'], 'EDIT', $target, ['name' => 'Forbidden', 'email' => 'forbidden@example.invalid']));
        $this->denied(fn () => $this->raw(self::$actors['admin'], 'PERMISSIONS', $target, ['permissions' => []]));
        self::assertSame('Disposable managed', User::findOrFail($target)->name);
        self::assertFalse(VictoryUsers::managed(User::findOrFail($target)));
    }

    public function test_role_system_fields_and_plaintext_password_are_rejected(): void
    {
        $target = $this->create();
        foreach (['role', 'is_active', 'fp_app_managed', 'sso_subject', 'id', 'password_hash'] as $field) {
            $this->denied(fn () => $this->raw(self::$actors['admin'], 'EDIT', $target, ['name' => 'N', 'email' => 'n@example.invalid', $field => 'admin']), '22023');
        }
        $this->denied(fn () => $this->raw(self::$actors['admin'], 'CREATE', null, ['name' => 'N', 'email' => 'n@example.invalid', 'password_hash' => 'plaintext']), '22023');
        self::assertSame('Disposable managed', User::findOrFail($target)->name);
        self::assertSame(['hrd'], User::findOrFail($target)->roles->pluck('name')->all());
    }

    public function test_transaction_rollback_removes_user_role_and_audit_atomically(): void
    {
        $before = DB::table('fp_user_events')->count();
        DB::beginTransaction();
        $target = $this->create('ro');
        self::assertTrue(DB::table('users')->where('id', $target)->exists());
        DB::rollBack();
        self::assertFalse(DB::table('users')->where('id', $target)->exists());
        self::assertSame(0, DB::table('model_has_roles')->where('model_id', $target)->where('model_type', User::class)->count());
        self::assertSame($before, DB::table('fp_user_events')->count());
    }

    public function test_application_has_no_broad_direct_sql_or_sequence_mutation_privileges(): void
    {
        foreach (['users', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'roles', 'permissions', 'fp_user_events', 'fp_identity_claims'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $op) {
                self::assertFalse(DB::selectOne('SELECT has_table_privilege(current_user,?,?) ok', ['public.'.$table, $op])->ok, $table.' '.$op);
            }
        }
        self::assertFalse(DB::selectOne("SELECT has_sequence_privilege(current_user,'public.users_id_seq','USAGE') ok")->ok);
        self::assertFalse(DB::selectOne("SELECT has_schema_privilege(current_user,'public','CREATE') ok")->ok);
        self::assertTrue(DB::selectOne("SELECT has_function_privilege(current_user,'public.fp_victory_user_command(bigint,text,bigint,jsonb)','EXECUTE') ok")->ok);
        self::assertFalse(DB::selectOne("SELECT has_function_privilege(current_user,'public.fp_user_events_immutable()','EXECUTE') ok")->ok);
        $this->denied(fn () => DB::connection()->getPdo()->exec("UPDATE public.users SET name='Forbidden' WHERE id=".self::$actors['hrd']));
        $this->denied(fn () => DB::connection()->getPdo()->exec('DELETE FROM public.model_has_permissions WHERE model_id='.self::$actors['bm']));
    }

    public function test_non_superuser_owner_fixed_path_no_public_execute_and_append_only_audit(): void
    {
        $p = DB::selectOne("SELECT p.prosecdef,p.proconfig,r.rolname,r.rolsuper,r.rolbypassrls,EXISTS(SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee=0 AND a.privilege_type='EXECUTE') pub FROM pg_proc p JOIN pg_roles r ON r.oid=p.proowner WHERE p.oid='public.fp_victory_user_command(bigint,text,bigint,jsonb)'::regprocedure");
        self::assertTrue($p->prosecdef);
        self::assertSame('fp_dev_migrator', $p->rolname);
        self::assertFalse($p->rolsuper);
        self::assertFalse($p->rolbypassrls);
        self::assertFalse($p->pub);
        self::assertStringContainsString('search_path=pg_catalog, pg_temp', $p->proconfig);
        $target = $this->create();
        DB::statement('RESET ROLE');
        try {
            DB::table('fp_user_events')->where('target_id',$target)->update(['fields' => '{}']);
            self::fail('Audit mutation allowed');
        } catch (\Illuminate\Database\QueryException $e) {
            self::assertSame('P0001',$e->errorInfo[0]);
        }
        DB::statement('SET ROLE fp_dev_app');
        self::assertSame(1,DB::table('fp_user_events')->where('target_id',$target)->count());
    }
}
