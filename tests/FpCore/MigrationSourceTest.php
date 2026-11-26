<?php

declare(strict_types=1);

namespace Tests\FpCore;

use PHPUnit\Framework\TestCase;

/** Static source-contract inspection only; this does not prove PostgreSQL semantics. */
final class MigrationSourceTest extends TestCase
{
    private static function migrations(): array
    {
        $files = glob(dirname(__DIR__, 2).'/database/migrations/2026_10_09_00000[1-5]_*.php');
        sort($files);
        $result = [];
        foreach ($files as $file) {
            // Loading the declaration is safe; NEVER invoke up(), down() or a database facade.
            $migration = require $file;
            $result[] = (new \ReflectionClass($migration))->getConstant('SQL');
        }

        return $result;
    }

    public function test_five_additive_dependencies_and_no_other_phase_tables(): void
    {
        $m = self::migrations();
        self::assertCount(5, $m);
        $expected = [['person_identities', 'nip_sequences'], ['fp_submissions'], ['financial_planners'], ['team_profiles'], ['fp_identity_claims']];
        foreach ($expected as $i => $tables) {
            preg_match_all('/CREATE TABLE public\.([a-z_]+)\s*\(/', $m[$i], $matches);
            self::assertSame($tables, $matches[1]);
        }
        $sql = implode("\n", $m);
        self::assertDoesNotMatchRegularExpression('/\b(?:DROP|TRUNCATE)\b/i', $sql);
        self::assertDoesNotMatchRegularExpression('/ALTER TABLE public\.(fps|teams|users)\b/i', $sql);
        self::assertStringNotContainsString('CREATE TABLE public.fp_documents', $sql);
        self::assertStringNotContainsString('CREATE TABLE public.fp_exits', $sql);
        self::assertStringContainsString('ALTER TABLE public.financial_planners ADD CONSTRAINT', $m[3]);
        foreach (glob(dirname(__DIR__, 2).'/database/migrations/2026_10_09_00000[1-5]_*.php') as $file) {
            $php = file_get_contents($file);
            self::assertStringContainsString("getDriverName() !== 'pgsql'", $php);
            self::assertStringContainsString('rollback is disabled', $php);
        }
        self::assertSame(0, \FpCoreNoDatabase::$attempts);
    }

    public function test_global_identity_uniqueness_ciphertext_and_initial_counter_contract(): void
    {
        $sql = self::migrations()[0];
        self::assertStringContainsString('nik_encrypted text NOT NULL', $sql);
        self::assertStringContainsString('nik_lookup_hash varchar(64) NOT NULL CONSTRAINT f2_person_identities_nik_uq UNIQUE', $sql);
        self::assertStringNotContainsString('UNIQUE (lookup_key_id', $sql);
        self::assertStringNotContainsString('nik_plain', $sql);
        self::assertStringContainsString('CHECK (id = 1)', $sql);
        self::assertStringContainsString('VALUES (1,1001)', $sql);
        self::assertSame(1, preg_match_all('/INSERT INTO public\./', $sql));
    }

    public function test_pending_active_claim_and_deferred_ownership_contracts(): void
    {
        $m = self::migrations();
        self::assertStringContainsString("WHERE status = 'PENDING'", $m[1]);
        self::assertStringContainsString("WHERE lifecycle_state = 'ACTIVE'", $m[2]);
        self::assertStringContainsString('identity_id bigint CONSTRAINT f2_fp_identity_claims_pk PRIMARY KEY', $m[4]);
        self::assertStringContainsString('FOREIGN KEY (identity_id,submission_id) REFERENCES public.fp_submissions(identity_id,id)', $m[4]);
        self::assertStringContainsString('FOREIGN KEY (identity_id,membership_id) REFERENCES public.financial_planners(identity_id,id)', $m[4]);
        self::assertSame(2, substr_count($m[4], 'DEFERRABLE INITIALLY DEFERRED FOR EACH ROW'));
        foreach (['matching pending claim missing', 'matching active claim missing', 'approval requires exact matching membership snapshot', 'terminal submission immutable', 'EXITED membership immutable', 'REVIEW_REQUIRED cannot acquire claim'] as $guard) {
            self::assertStringContainsString($guard, $m[4]);
        }
        self::assertStringContainsString("IF TG_TABLE_NAME = 'fp_submissions' THEN sid := NEW.id; ELSE sid := NEW.submission_id; END IF;", $m[4]);
        self::assertStringContainsString('SECURITY DEFINER SET search_path = pg_catalog', $m[4]);
        self::assertStringContainsString('REVOKE ALL ON TABLE public.fp_identity_claims FROM PUBLIC', $m[4]);
    }

    public function test_origin_null_semantics_typed_sources_and_nip_guards(): void
    {
        $m = self::migrations();
        foreach (['identity_id IS NOT NULL', 'nip IS NOT NULL AND nip >= 1001', 'submission_id IS NOT NULL', 'source_type IS NOT NULL', "origin = 'LEGACY'"] as $contract) {
            self::assertStringContainsString($contract, $m[2]);
        }
        self::assertStringContainsString('legacy_fp_id bigint CONSTRAINT f2_financial_planners_legacy_uq UNIQUE', $m[2]);
        self::assertStringContainsString('REFERENCES public.fps(id)', $m[2]);
        self::assertStringContainsString('source_fp_id) REFERENCES public.financial_planners(id)', $m[2]);
        self::assertStringNotContainsString('source_fp_id) REFERENCES public.fps(id)', $m[2]);
        self::assertStringContainsString("source_type = 'HRD' AND source_team_id IS NULL AND source_fp_id IS NULL", $m[2]);
        self::assertStringContainsString("source_type = 'TEAM' AND source_team_id IS NOT NULL AND source_fp_id IS NULL", $m[2]);
        self::assertStringContainsString("source_type = 'FP' AND source_team_id IS NULL AND source_fp_id IS NOT NULL", $m[2]);
        self::assertStringNotContainsString("source_type = 'MGM'", $m[2]);
        foreach (['membership ownership/NIP immutable', 'NEW.next_value <> OLD.next_value + 1', "origin = 'NEW' AND nip = OLD.next_value", 'NIP must match locked counter', 'allocation requires committed counter advance'] as $guard) {
            self::assertStringContainsString($guard, $m[4]);
        }
        self::assertDoesNotMatchRegularExpression('/MAX\s*\(/i', implode("\n", $m));
    }

    public function test_leader_reference_requires_active_and_same_team_without_backfill(): void
    {
        $m = self::migrations();
        self::assertStringContainsString("CASE WHEN lifecycle_state = 'ACTIVE' THEN id END", $m[2]);
        self::assertStringContainsString('UNIQUE (team_id,active_leader_key)', $m[2]);
        self::assertStringContainsString('FOREIGN KEY (team_id,leader_fp_id) REFERENCES public.financial_planners(team_id,active_leader_key)', $m[3]);
        self::assertStringContainsString('DEFERRABLE INITIALLY DEFERRED', $m[3]);
        self::assertStringContainsString('is_active boolean NOT NULL DEFAULT false', $m[3]);
        self::assertStringNotContainsString('INSERT INTO', $m[3]);
        self::assertStringNotContainsString('SELECT name', $m[3]);
    }

    public function test_revision_and_actor_deletion_evidence_are_separate(): void
    {
        $m = self::migrations();
        self::assertStringContainsString('biodata_verified_revision = biodata_revision', $m[1]);
        self::assertStringContainsString('documents_verified_revision = document_revision', $m[1]);
        self::assertStringContainsString('biodata_verified_actor_id IS NOT NULL', $m[1]);
        self::assertStringContainsString('ON DELETE SET NULL', $m[1]);
        self::assertStringContainsString('NEW.biodata_revision := OLD.biodata_revision + 1', $m[4]);
        self::assertStringContainsString('NEW.documents_verified_revision := NULL', $m[4]);
        self::assertStringContainsString('reference_date = (submitted_at AT TIME ZONE business_timezone)::date', $m[1]);
    }

    public function test_before_membership_guard_compares_only_base_columns(): void
    {
        $m = self::migrations();
        $guard = explode('CREATE FUNCTION public.f2_core_counter_guard()', explode('CREATE FUNCTION public.f2_core_membership_guard()', $m[4])[1])[0];
        self::assertStringNotContainsString('to_jsonb(NEW)', $guard);
        self::assertStringNotContainsString('NEW.active_leader_key', $guard);
        self::assertStringNotContainsString('OLD.active_leader_key', $guard);
        preg_match_all('/^ ([a-z_]+) (?:bigint|uuid|varchar|text|date|smallint|timestamptz)/m', $m[2], $columns);
        $base = array_values(array_diff($columns[1], ['active_leader_key']));
        preg_match_all('/ROW\((NEW\.[^)]+)\) IS DISTINCT FROM ROW\(OLD\.[^)]+\)/', $guard, $comparisons);
        self::assertCount(2, $comparisons[1]);
        foreach ([['approved_by'], ['lifecycle_state', 'updated_at', 'approved_by']] as $i => $allowed) {
            $actual = array_map(static fn (string $column): string => substr($column, 4), explode(',', $comparisons[1][$i]));
            self::assertSame(array_values(array_diff($base, $allowed)), $actual);
        }
    }

    public function test_pending_identity_correction_defers_claim_owner_fk_until_final_state(): void
    {
        $sql = self::migrations()[4];
        self::assertStringContainsString('CONSTRAINT f2_fp_identity_claims_submission_fk FOREIGN KEY (identity_id,submission_id) REFERENCES public.fp_submissions(identity_id,id) ON DELETE RESTRICT ON UPDATE NO ACTION DEFERRABLE INITIALLY DEFERRED', $sql);
        self::assertStringContainsString('NEW.identity_id <> OLD.identity_id', $sql);
        self::assertStringContainsString("DELETE FROM public.fp_identity_claims WHERE identity_id = OLD.identity_id AND kind = 'PENDING'", $sql);
        self::assertStringContainsString('AFTER INSERT OR UPDATE ON public.fp_submissions', $sql);
        self::assertStringContainsString('FOREIGN KEY (identity_id,membership_id) REFERENCES public.financial_planners(identity_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT', $sql);
    }

    public function test_source_team_relation_matches_the_actual_legacy_team_fk(): void
    {
        $root = dirname(__DIR__, 2);
        $model = file_get_contents($root.'/app/Models/FpMembership.php');
        self::assertStringContainsString("belongsTo(Team::class, 'source_team_id', 'id')", $model);
        self::assertStringContainsString('FOREIGN KEY (source_team_id) REFERENCES public.teams(id)', self::migrations()[2]);
        $submission = file_get_contents($root.'/app/Models/FpSubmission.php');
        self::assertStringContainsString("belongsTo(Team::class, 'team_id', 'id')", $submission);
        self::assertStringContainsString('FOREIGN KEY (team_id) REFERENCES public.teams(id)', self::migrations()[1]);
    }

    public function test_accepted_eight_historical_files_and_protected_fixture_are_unchanged(): void
    {
        $root = dirname(__DIR__, 2);
        $expected = json_decode(file_get_contents(__DIR__.'/accepted-historical-migrations.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(8, $expected);
        foreach ($expected as $path => $hash) {
            self::assertSame($hash, hash_file('sha256', $root.'/'.$path), $path);
        }
        $fixture = $root.'/tests/Fixtures/u0-source-hashes.json';
        self::assertSame('7834762741dc0ab30958aff23a4da47ba76d777dbff9ffa5715d1b2ac3159000', hash_file('sha256', $fixture));
        $original = json_decode(file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
        $platform = ['composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'Dockerfile', 'docker-compose.dokploy.yml'];
        foreach ($original as $path => $hash) {
            if (! in_array($path, $platform, true)) {
                self::assertSame($hash, hash_file('sha256', $root.'/'.$path), $path);
            }
        }
    }
}
