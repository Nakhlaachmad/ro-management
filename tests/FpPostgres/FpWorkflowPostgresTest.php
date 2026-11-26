<?php

declare(strict_types=1);

namespace Tests\FpPostgres;

use App\Application\FP\FpEngine;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

/** Executable real-PG tests. NOT RUN under F2-FAST source-only authorization. */
final class FpWorkflowPostgresTest extends TestCase
{
    private static int $serial = 0;

    private static string $fixturePrefix = '';

    private FpEngine $engine;

    private TestActor $actor;

    private int $team;

    public static function setUpBeforeClass(): void
    {
        if (! PostgresHarness::available()) {
            self::markTestSkipped('No disposable PostgreSQL write authorization');
        }
        if (getenv('FP_TEST_CONTINUE_EXISTING') === '1') {
            self::verifyContinuation();
        } else {
            PostgresHarness::installEmpty();
        }
    }

    private static function verifyContinuation(): void
    {
        PostgresHarness::connect();
        self::$fixturePrefix = (string) getenv('FP_TEST_FIXTURE_NAMESPACE');
        if (! preg_match('/\A[1-9][0-9]{7}\z/', self::$fixturePrefix)) {
            throw new \RuntimeException('Unique synthetic fixture namespace required');
        }
        DB::transaction(function (): void {
            DB::statement('SET TRANSACTION READ ONLY');
            $id = DB::selectOne('SELECT current_database() AS db, current_user AS role, host(inet_server_addr()) AS host, inet_server_port() AS port');
            self::assertSame('fp_f2_test_20261009', $id->db);
            self::assertSame('fp_dev_migrator', $id->role);
            self::assertSame('127.0.0.1', $id->host);
            self::assertSame(55432, (int) $id->port);
            $names = array_map(fn ($file) => basename($file, '.php'), glob(dirname(__DIR__, 2).'/database/migrations/*.php'));
            sort($names);
            self::assertSame($names, DB::table('migrations')->orderBy('migration')->pluck('migration')->all());
            self::assertSame([1, 2, 3], DB::table('migrations')->distinct()->orderBy('batch')->pluck('batch')->map(fn ($batch) => (int) $batch)->all());
            self::assertSame(23, (int) DB::selectOne("SELECT count(*) AS n FROM pg_tables WHERE schemaname='public'")->n);
            self::assertSame(17, (int) DB::selectOne("SELECT count(*) AS n FROM pg_proc p JOIN pg_namespace n ON p.pronamespace=n.oid WHERE n.nspname='public'")->n);
            self::assertSame(25, (int) DB::selectOne("SELECT count(*) AS n FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND NOT t.tgisinternal")->n);
            $hashes = [];
            for ($i = 1; $i <= 64; $i++) {
                $hashes[] = \App\Domain\FP\NikNormalizer::lookup(self::$fixturePrefix.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'synthetic-test-only-key-0000000000000000');
            }
            self::assertSame(0, DB::table('person_identities')->whereIn('nik_lookup_hash', $hashes)->count(), 'Fixture namespace must not overlap retained data');
        }, 1);
    }

    protected function setUp(): void
    {
        $this->engine = new FpEngine;
        $this->actor = PostgresHarness::actor();
        $this->team = PostgresHarness::team($this->actor);
    }

    private function nik(): string
    {
        if (self::$fixturePrefix !== '') {
            return self::$fixturePrefix.str_pad((string) ++self::$serial, 8, '0', STR_PAD_LEFT);
        }

        return str_pad((string) ++self::$serial, 16, '0', STR_PAD_LEFT);
    }

    private function pending(?string $nik = null): int
    {
        return PostgresHarness::pending($this->engine, $this->team, $nik ?? $this->nik());
    }

    private function approved(?string $nik = null): int
    {
        $sid = $this->pending($nik);
        PostgresHarness::reviewed($this->engine, $this->actor, $sid);

        return $this->engine->approveFpSubmission($this->actor, $sid, ['source_type' => 'HRD', 'recruitment_path_id' => (int) \Illuminate\Support\Facades\DB::table('fp_recruitment_paths')->where('code', 'A')->value('id')]);
    }

    private function denied(callable $work, ?string $expectedCommitInvariant = null): void
    {
        try {
            $work();
            self::fail('Invariant permitted');
        } catch (\Illuminate\Validation\ValidationException|\Illuminate\Auth\Access\AuthorizationException|\Illuminate\Database\QueryException|\App\Domain\FP\FpPersistenceException $error) {
            if ($expectedCommitInvariant !== null) {
                throw $error;
            }
            self::assertTrue(true);
        } catch (\PDOException $error) {
            $pattern = '/\AERROR:\s+'.preg_quote((string) $expectedCommitInvariant, '/').'(?:\r?\n|$)/';
            if ($expectedCommitInvariant === null || ($error->errorInfo[0] ?? null) !== 'P0001' || ! preg_match($pattern, (string) ($error->errorInfo[2] ?? ''))) {
                throw $error;
            }
            self::assertSame('P0001', $error->errorInfo[0]);
            self::assertMatchesRegularExpression($pattern, $error->errorInfo[2]);
        }
    }

    public function test_first_nip_and_double_approval_are_atomic(): void
    {
        $sid = $this->pending();
        PostgresHarness::reviewed($this->engine, $this->actor, $sid);
        $before = DB::table('nip_sequences')->value('next_value');
        $mid = $this->engine->approveFpSubmission($this->actor, $sid, ['source_type' => 'HRD', 'recruitment_path_id' => (int) \Illuminate\Support\Facades\DB::table('fp_recruitment_paths')->where('code', 'A')->value('id')]);
        self::assertSame(1001, (int) DB::table('financial_planners')->where('id', $mid)->value('nip'));
        self::assertSame($mid, $this->engine->approveFpSubmission($this->actor, $sid, ['source_type' => 'HRD', 'recruitment_path_id' => (int) \Illuminate\Support\Facades\DB::table('fp_recruitment_paths')->where('code', 'A')->value('id')]));
        self::assertSame((int) $before + 1, (int) DB::table('nip_sequences')->value('next_value'));
        self::assertSame(3, DB::table('fp_documents')->where('membership_id', $mid)->count());
    }

    public function test_same_identity_duplicate_pending_and_active_are_blocked(): void
    {
        $nik = $this->nik();
        $sid = $this->pending($nik);
        $this->denied(fn () => $this->pending($nik));
        PostgresHarness::reviewed($this->engine, $this->actor, $sid);
        $this->engine->approveFpSubmission($this->actor, $sid, ['source_type' => 'HRD', 'recruitment_path_id' => (int) \Illuminate\Support\Facades\DB::table('fp_recruitment_paths')->where('code', 'A')->value('id')]);
        $this->denied(fn () => $this->pending($nik));
        self::assertSame(1, DB::table('person_identities')->where('nik_lookup_hash', \App\Domain\FP\NikNormalizer::lookup($nik, 'synthetic-test-only-key-0000000000000000'))->count());
    }

    public function test_unreviewed_approval_rolls_back_without_nip(): void
    {
        $sid = $this->pending();
        $counter = DB::table('nip_sequences')->value('next_value');
        $this->denied(fn () => $this->engine->approveFpSubmission($this->actor, $sid, ['source_type' => 'HRD', 'recruitment_path_id' => (int) \Illuminate\Support\Facades\DB::table('fp_recruitment_paths')->where('code', 'A')->value('id')]));
        self::assertSame('PENDING', DB::table('fp_submissions')->where('id', $sid)->value('status'));
        self::assertSame($counter, DB::table('nip_sequences')->value('next_value'));
        self::assertSame(0, DB::table('financial_planners')->where('submission_id', $sid)->count());
    }

    public function test_direct_sql_approval_without_membership_or_audit_cannot_commit(): void
    {
        $sid = $this->pending();
        PostgresHarness::reviewed($this->engine, $this->actor, $sid);
        $counter = DB::table('nip_sequences')->value('next_value');
        $this->denied(fn () => DB::transaction(fn () => DB::table('fp_submissions')->where('id', $sid)->update(['status' => 'APPROVED', 'approved_at' => now('UTC'), 'approved_by' => $this->actor->id, 'approved_actor_id' => $this->actor->id])), 'F2 approval audit required');
        self::assertFalse(DB::connection()->getPdo()->inTransaction());
        self::assertSame(0, DB::transactionLevel());
        self::assertNull(DB::table('fp_submissions')->where('id', $sid)->value('approved_at'));
        self::assertSame(0, DB::table('financial_planners')->where('submission_id', $sid)->count());
        self::assertSame(0, DB::table('fp_audit_events')->where('submission_id', $sid)->where('event', 'APPROVED')->count());
        self::assertSame($counter, DB::table('nip_sequences')->value('next_value'));
        self::assertSame('PENDING', DB::table('fp_submissions')->where('id', $sid)->value('status'));
    }

    public function test_pending_nik_correction_moves_claim_and_invalidates_reviews(): void
    {
        $sid = $this->pending();
        PostgresHarness::reviewed($this->engine, $this->actor, $sid);
        $old = DB::table('fp_submissions')->where('id', $sid)->first();
        $this->engine->updateSubmissionBiodata($this->actor, $sid, PostgresHarness::input($this->team, $this->nik()));
        $new = DB::table('fp_submissions')->where('id', $sid)->first();
        self::assertNotSame($old->identity_id, $new->identity_id);
        self::assertNull($new->biodata_verified_revision);
        self::assertNull($new->documents_verified_revision);
        self::assertSame($sid, (int) DB::table('fp_identity_claims')->where('identity_id', $new->identity_id)->value('submission_id'));
        self::assertFalse(DB::table('fp_identity_claims')->where('identity_id', $old->identity_id)->exists());
    }

    public function test_exit_leader_and_rejoin_preserve_previous_membership_nip(): void
    {
        $nik = $this->nik();
        $mid = $this->approved($nik);
        $member = DB::table('financial_planners')->where('id', $mid)->first();
        $this->engine->assignTeamLeader($this->actor, $this->team, $mid);
        $exit = \Carbon\CarbonImmutable::parse($member->reference_date)->addDays(59)->format('Y-m-d');
        $this->engine->exitFp($this->actor, $mid, $exit, 'Synthetic reason');
        self::assertNull(DB::table('team_profiles')->where('team_id', $this->team)->value('leader_fp_id'));
        self::assertSame('ABNORMAL', DB::table('fp_exits')->where('membership_id', $mid)->value('type'));
        $next = $this->approved($nik);
        self::assertNotSame($mid, $next);
        self::assertGreaterThan($member->nip, DB::table('financial_planners')->where('id', $next)->value('nip'));
        self::assertSame('EXITED', DB::table('financial_planners')->where('id', $mid)->value('lifecycle_state'));
        $this->denied(fn () => DB::transaction(fn () => DB::table('financial_planners')->where('id', $mid)->update(['lifecycle_state' => 'ACTIVE'])));
    }

    public function test_nip_counter_ownership_and_audit_are_immutable(): void
    {
        $mid = $this->approved();
        $this->denied(fn () => DB::transaction(fn () => DB::table('financial_planners')->where('id', $mid)->update(['nip' => 9999])));
        $this->denied(fn () => DB::transaction(fn () => DB::table('nip_sequences')->where('id', 1)->update(['next_value' => 1001])));
        $this->denied(fn () => DB::transaction(fn () => DB::table('fp_audit_events')->delete()));
    }

    public function test_direct_ready_loss_and_cross_identity_claim_are_rejected(): void
    {
        $mid = $this->approved();
        $doc = DB::table('fp_documents')->where('membership_id', $mid)->first();
        $this->denied(fn () => DB::transaction(fn () => DB::table('fp_documents')->where('id', $doc->id)->update(['processing_state' => 'FAILED'])));
        $sid = $this->pending();
        $sub = DB::table('fp_submissions')->where('id', $sid)->first();
        $this->denied(fn () => DB::transaction(fn () => DB::table('fp_identity_claims')->where('identity_id', $sub->identity_id)->update(['membership_id' => $mid, 'submission_id' => null, 'kind' => 'ACTIVE'])));
    }

    public function test_inactive_source_wrong_team_and_unauthorized_actions_fail(): void
    {
        $sid = $this->pending();
        PostgresHarness::reviewed($this->engine, $this->actor, $sid);
        $this->denied(fn () => $this->engine->approveFpSubmission($this->actor, $sid, ['source_type' => 'MGM']));
        $mid = $this->approved();
        $other = PostgresHarness::team($this->actor);
        $this->denied(fn () => $this->engine->assignTeamLeader($this->actor, $other, $mid));
        $this->actor->testRoles = ['bm'];
        $this->denied(fn () => $this->engine->verifySubmissionBiodata($this->actor, $sid));
        self::assertSame(0, DB::table('fps')->count(), 'Canonical workflows did not create legacy FP');
    }

    public function test_rejection_keeps_history_and_commits_deletion_intent(): void
    {
        $sid = $this->pending();
        $identity = DB::table('fp_submissions')->where('id', $sid)->value('identity_id');
        $this->engine->rejectFpSubmission($this->actor, $sid, 'Synthetic rejection');
        self::assertSame('REJECTED', DB::table('fp_submissions')->where('id', $sid)->value('status'));
        self::assertSame(3, DB::table('fp_documents')->where('submission_id', $sid)->where('deletion_state', 'REQUESTED')->count());
        self::assertFalse(DB::table('fp_identity_claims')->where('identity_id', $identity)->exists());
    }

    private function race(array $submissions): array
    {
        if (! function_exists('proc_open')) {
            self::markTestSkipped('Process spawning required for real connection concurrency');
        }
        $workers = [];
        try {
            foreach ($submissions as $sid) {
                $process = proc_open([PHP_BINARY, __DIR__.'/worker.php', json_encode(['actor' => $this->actor->id, ...(is_array($sid) ? $sid : ['submission' => $sid])])], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (! is_resource($process)) {
                    throw new \RuntimeException('Worker unavailable');
                }stream_set_timeout($pipes[1], 30);
                $workers[] = [$process, $pipes];
                if (trim((string) fgets($pipes[1])) !== 'READY') {
                    throw new \RuntimeException('Worker readiness failed');
                }
            }
            foreach ($workers as [$process,$pipes]) {
                fwrite($pipes[0], "GO\n");
            }$results = [];
            foreach ($workers as [$process,$pipes]) {
                $line = stream_get_contents($pipes[1]);
                $meta = stream_get_meta_data($pipes[1]);
                if ($meta['timed_out']) {
                    throw new \RuntimeException('Concurrency test timed out');
                }$results[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($workers as [$process,$pipes]) {
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }proc_close($process);
            }
        }
    }

    public function test_two_connections_approve_same_submission_once(): void
    {
        $sid = $this->pending();
        PostgresHarness::reviewed($this->engine, $this->actor, $sid);
        $before = DB::table('nip_sequences')->value('next_value');
        $results = $this->race([$sid, $sid]);
        self::assertSame($results[0]['membership'], $results[1]['membership']);
        self::assertSame(1, DB::table('financial_planners')->where('submission_id', $sid)->count());
        self::assertSame((int) $before + 1, (int) DB::table('nip_sequences')->value('next_value'));
    }

    public function test_two_connections_allocate_distinct_monotonic_nips(): void
    {
        $a = $this->pending();
        $b = $this->pending();
        PostgresHarness::reviewed($this->engine, $this->actor, $a);
        PostgresHarness::reviewed($this->engine, $this->actor, $b);
        $result = $this->race([$a, $b]);
        $nips = DB::table('financial_planners')->whereIn('id', array_column($result, 'membership'))->orderBy('nip')->pluck('nip')->all();
        self::assertCount(2, $nips);
        self::assertSame((int) $nips[0] + 1, (int) $nips[1]);
    }

    public function test_approval_rejection_race_commits_only_one_terminal_result(): void
    {
        $sid = $this->pending();
        PostgresHarness::reviewed($this->engine, $this->actor, $sid);
        $this->race([['submission' => $sid, 'operation' => 'approve'], ['submission' => $sid, 'operation' => 'reject']]);
        $status = DB::table('fp_submissions')->where('id', $sid)->value('status');
        self::assertContains($status, ['APPROVED', 'REJECTED']);
        self::assertSame($status === 'APPROVED' ? 1 : 0, DB::table('financial_planners')->where('submission_id', $sid)->count());
        self::assertSame(1, DB::table('fp_audit_events')->where('submission_id', $sid)->whereIn('event', ['APPROVED', 'REJECTED'])->count());
    }

    public function test_rejected_processing_document_cannot_be_promoted_by_stale_worker(): void
    {
        $sid = $this->pending();
        $doc = DB::table('fp_documents')->where('submission_id', $sid)->first();
        DB::transaction(function () use ($sid, $doc) {
            $time = \Carbon\CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
            DB::table('fp_documents')->where('id', $doc->id)->update(['processing_state' => 'PROCESSING', 'updated_at' => $time]);
            $this->engine->documentAudit($doc->id, $sid, 1, $this->actor->id, $time);
        });
        $this->engine->rejectFpSubmission($this->actor, $sid, 'Synthetic stale worker case');
        $this->denied(fn () => DB::transaction(fn () => DB::table('fp_documents')->where('id', $doc->id)->update(['processing_state' => 'READY'])));
        self::assertSame('PROCESSING', DB::table('fp_documents')->where('id', $doc->id)->value('processing_state'));
    }

    public function test_installed_migration_catalog_has_all_eighteen_ledger_rows(): void
    {
        self::assertSame(18, DB::table('migrations')->count());
        foreach (['person_identities', 'nip_sequences', 'fp_submissions', 'financial_planners', 'team_profiles', 'fp_identity_claims', 'fp_documents', 'fp_exits', 'fp_audit_events'] as $table) {
            self::assertTrue(DB::connection()->getSchemaBuilder()->hasTable($table));
        }
    }
}
