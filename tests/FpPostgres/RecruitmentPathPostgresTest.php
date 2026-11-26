<?php

declare(strict_types=1);

namespace Tests\FpPostgres;

use App\Application\FP\FpEngine;
use App\Application\FP\RecruitmentPaths;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

final class RecruitmentPathPostgresTest extends TestCase
{
    private FpEngine $engine;

    private TestActor $actor;

    private int $team;

    private int $path;

    public static function setUpBeforeClass(): void
    {
        if (! PostgresHarness::available()) {
            self::markTestSkipped('Disposable authorization absent');
        }
        PostgresHarness::connect();
    }

    protected function setUp(): void
    {
        $this->engine = new FpEngine;
        $this->actor = PostgresHarness::actor();
        $this->team = PostgresHarness::team($this->actor);
        $this->path = (int) DB::table('fp_recruitment_paths')->where('code', 'A')->value('id');
    }

    private function reviewed(): int
    {
        $sid = PostgresHarness::pending($this->engine, $this->team, (string) random_int(1000000000000000, 9999999999999999));
        PostgresHarness::reviewed($this->engine, $this->actor, $sid);

        return $sid;
    }

    private function source(?int $path = null): array
    {
        return ['source_type' => 'HRD', 'recruitment_path_id' => $path ?? $this->path];
    }

    private function invalid(callable $work, string $state): void
    {
        try {
            $work();
            self::fail('Invalid operation allowed');
        } catch (\Illuminate\Database\QueryException $e) {
            self::assertSame($state, $e->errorInfo[0]);
        }
    }

    public function test_jalur_a_is_independent_of_team_and_fp_typed_sources(): void
    {
        $teamMember = $this->engine->approveFpSubmission($this->actor, $this->reviewed(), ['source_type' => 'TEAM', 'source_team_id' => $this->team, 'recruitment_path_id' => $this->path]);
        $fpMember = $this->engine->approveFpSubmission($this->actor, $this->reviewed(), ['source_type' => 'FP', 'source_fp_id' => $teamMember, 'recruitment_path_id' => $this->path]);
        $team = DB::table('financial_planners')->where('id', $teamMember)->first();
        $fp = DB::table('financial_planners')->where('id', $fpMember)->first();
        self::assertSame('A', $team->recruitment_path_code_snapshot);
        self::assertSame('TEAM', $team->source_type);
        self::assertSame($this->team, (int) $team->source_team_id);
        self::assertSame('A', $fp->recruitment_path_code_snapshot);
        self::assertSame('FP', $fp->source_type);
        self::assertSame($teamMember, (int) $fp->source_fp_id);
    }

    public function test_master_a_active_and_unique_code(): void
    {
        $a = DB::table('fp_recruitment_paths')->where('id', $this->path)->first();
        self::assertTrue($a->is_active);
        self::assertSame('Reguler / Umum', $a->name);
        self::assertSame('Rekrutmen standar melalui proses umum atau walk-in interview', $a->description);
        $this->invalid(fn () => DB::transaction(fn () => DB::table('fp_recruitment_paths')->insert(['code' => 'A', 'name' => 'Duplicate'])), '23505');
    }

    public function test_missing_and_inactive_jalur_preserve_pending_counter(): void
    {
        $sid = $this->reviewed();
        $before = DB::table('nip_sequences')->value('next_value');
        $off = (new RecruitmentPaths)->save($this->actor, null, ['code' => 'T'.strtoupper(bin2hex(random_bytes(4))), 'name' => 'Inactive synthetic', 'is_active' => false]);
        foreach ([['source_type' => 'HRD'], $this->source($off->id)] as $source) {
            try {
                $this->engine->approveFpSubmission($this->actor, $sid, $source);
                self::fail('Approval allowed');
            } catch (\Illuminate\Validation\ValidationException $e) {
                self::assertArrayHasKey('recruitment_path_id', $e->errors());
            }
        }
        self::assertSame('PENDING', DB::table('fp_submissions')->where('id', $sid)->value('status'));
        self::assertSame($before, DB::table('nip_sequences')->value('next_value'));
        self::assertSame(0, DB::table('financial_planners')->where('submission_id', $sid)->count());
        self::assertSame(0, DB::table('fp_audit_events')->where('submission_id', $sid)->where('event', 'APPROVED')->count());
    }

    public function test_active_jalur_approval_snapshot_and_double_approval(): void
    {
        $sid = $this->reviewed();
        $before = (int) DB::table('nip_sequences')->value('next_value');
        $mid = $this->engine->approveFpSubmission($this->actor, $sid, $this->source());
        self::assertSame($mid, $this->engine->approveFpSubmission($this->actor, $sid, $this->source()));
        $m = DB::table('financial_planners')->where('id', $mid)->first();
        self::assertSame($before, (int) $m->nip);
        self::assertSame($before + 1, (int) DB::table('nip_sequences')->value('next_value'));
        self::assertSame($this->path, (int) $m->recruitment_path_id);
        self::assertSame('A', $m->recruitment_path_code_snapshot);
        self::assertSame('Reguler / Umum', $m->recruitment_path_name_snapshot);
        self::assertSame('HRD', $m->source_type);
        self::assertSame(1, DB::table('fp_identity_claims')->where('membership_id', $mid)->where('kind', 'ACTIVE')->count());
        self::assertSame(1, DB::table('fp_audit_events')->where('membership_id', $mid)->where('event', 'APPROVED')->count());
    }

    public function test_master_edit_keeps_snapshot_and_snapshot_is_immutable(): void
    {
        $path = (new RecruitmentPaths)->save($this->actor, null, ['code' => 'T'.strtoupper(bin2hex(random_bytes(4))), 'name' => 'Original name', 'is_active' => true]);
        $mid = $this->engine->approveFpSubmission($this->actor, $this->reviewed(), $this->source($path->id));
        (new RecruitmentPaths)->save($this->actor, $path->id, ['code' => 'IGNORED', 'name' => 'Changed name', 'description' => 'Edited', 'is_active' => false]);
        self::assertSame('Original name', DB::table('financial_planners')->where('id', $mid)->value('recruitment_path_name_snapshot'));
        self::assertSame($path->code, DB::table('fp_recruitment_paths')->where('id', $path->id)->value('code'));
        $this->invalid(fn () => DB::transaction(fn () => DB::table('financial_planners')->where('id', $mid)->update(['recruitment_path_name_snapshot' => 'Changed name'])), '23514');
        $this->invalid(fn () => DB::transaction(fn () => DB::table('fp_recruitment_paths')->where('id', $path->id)->update(['code' => 'CHANGED'])), '23514');
        $this->invalid(fn () => DB::transaction(fn () => DB::table('fp_recruitment_paths')->where('id', $path->id)->delete()), '23514');
    }

    public function test_existing_null_path_memberships_remain_readable_and_cannot_be_backfilled(): void
    {
        $m = DB::table('financial_planners')->whereNull('recruitment_path_id')->first();
        self::assertNotNull($m);
        self::assertNull(\App\Models\FpMembership::findOrFail($m->id)->recruitmentPath);
        $this->invalid(fn () => DB::transaction(fn () => DB::table('financial_planners')->where('id', $m->id)->update(['recruitment_path_id' => $this->path, 'recruitment_path_code_snapshot' => 'A', 'recruitment_path_name_snapshot' => 'Reguler / Umum'])), '23514');
    }

    public function test_bm_cannot_manage_master_or_approve_even_with_optional_permissions(): void
    {
        $this->actor->testRoles = ['bm'];
        $this->actor->testPermissions = ['f2.approve', 'f3.jalur.manage'];
        try {
            (new RecruitmentPaths)->save($this->actor, null, ['code' => 'NO', 'name' => 'Not permitted', 'is_active' => true]);
            self::fail('Unauthorized master mutation');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertTrue(true);
        }
        $this->actor->testPermissions = ['f3.jalur.manage'];
        try {
            $this->engine->approveFpSubmission($this->actor, 0, $this->source());
            self::fail('Master manager may approve');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertTrue(true);
        }
    }

    public function test_direct_sql_missing_jalur_rejected_without_counter_or_claim_leak(): void
    {
        $sid = $this->reviewed();
        $before = DB::table('nip_sequences')->value('next_value');
        $this->invalid(function () use ($sid, $before) {
            DB::transaction(function () use ($sid, $before) {
                $sub = DB::table('fp_submissions')->where('id', $sid)->first();
                $time = now('UTC');
                DB::table('fp_submissions')->where('id', $sid)->update(['status' => 'APPROVED', 'approved_at' => $time, 'approved_by' => $this->actor->id, 'approved_actor_id' => $this->actor->id]);
                $fields = ['identity_id', 'full_name', 'phone', 'phone_normalized', 'bank_account_encrypted', 'address_ktp', 'address_current', 'birth_place', 'birth_date', 'education_level', 'education_institution', 'graduation_year', 'team_id', 'reference_date', 'business_timezone'];
                DB::table('financial_planners')->insert([...array_intersect_key((array) $sub, array_flip($fields)), 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'origin' => 'NEW', 'submission_id' => $sid, 'nip' => $before, 'lifecycle_state' => 'ACTIVE', 'source_type' => 'HRD', 'source_name_snapshot' => 'HRD', 'approved_at' => $time, 'approved_by' => $this->actor->id, 'approved_actor_id' => $this->actor->id, 'created_at' => $time, 'updated_at' => $time]);
            }, 1);
        }, '23514');
        self::assertSame('PENDING', DB::table('fp_submissions')->where('id', $sid)->value('status'));
        self::assertSame($before, DB::table('nip_sequences')->value('next_value'));
        self::assertSame(1, DB::table('fp_identity_claims')->where('submission_id', $sid)->where('kind', 'PENDING')->count());
    }
}
