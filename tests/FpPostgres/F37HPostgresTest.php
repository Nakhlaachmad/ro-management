<?php

declare(strict_types=1);

namespace Tests\FpPostgres;

use App\Application\FP\AmendFpBiodata;
use App\Application\FP\AmendFpDocument;
use App\Application\FP\FillSubmissionDocument;
use App\Application\FP\FpEngine;
use App\Infrastructure\FP\DocumentStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Explicit opt-in only. Never installs schema, resets tables, or connects to main. */
final class F37HPostgresTest extends TestCase
{
    private const DATABASE = 'fp_f37g_phase3_20261021';

    private \Illuminate\Foundation\Application $app;

    protected function setUp(): void
    {
        $database = getenv('FP_H_TEST_DATABASE') ?: self::DATABASE;
        if ($database !== self::DATABASE && ! preg_match('/\Afp_f39_stage68_[0-9]+\z/', $database)) {
            throw new \RuntimeException('Verified disposable database name required');
        }
        foreach (['FP_H_TEST_PASSWORD', 'FP_H_TEST_APP_KEY', 'FP_H_TEST_HMAC'] as $key) {
            if (! getenv($key)) {
                throw new \RuntimeException('F37H disposable runtime credentials must be provided in process memory');
            }
        }
        $runtime = str_replace('\\', '/', sys_get_temp_dir()).'/f37h-postgres-'.getmypid();
        if (! is_dir($runtime)) {
            mkdir($runtime, 0700, true);
        }
        foreach (['views', 'sessions', 'cache'] as $directory) {
            if (! is_dir($runtime.'/storage/framework/'.$directory)) {
                mkdir($runtime.'/storage/framework/'.$directory, 0700, true);
            }
        }
        $env = ['APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_KEY' => getenv('FP_H_TEST_APP_KEY'),
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '55432', 'DB_DATABASE' => $database,
            'DB_USERNAME' => 'fp_dev_app', 'DB_PASSWORD' => getenv('FP_H_TEST_PASSWORD'), 'DB_URL' => '', 'DATABASE_URL' => '',
            'APP_CONFIG_CACHE' => $runtime.'/absent-config.php', 'APP_ROUTES_CACHE' => $runtime.'/absent-routes.php',
            'APP_SERVICES_CACHE' => $runtime.'/services.php', 'APP_PACKAGES_CACHE' => $runtime.'/packages.php',
            'VIEW_COMPILED_PATH' => $runtime.'/storage/framework/views',
            'FP_ENABLED' => 'true', 'FP_ANNUAL_NIP_EMAIL_ENABLED' => 'true', 'FP_RECRUITMENT_TEAM_ENABLED' => 'true',
            'FP_ORGANIZATION_ENABLED' => 'true', 'FP_OPTIONAL_ONBOARDING_ENABLED' => 'true',
            'FP_NIK_LOOKUP_KEY' => getenv('FP_H_TEST_HMAC'), 'FP_NIK_LOOKUP_KEY_ID' => 'f37h-disposable', 'FP_BUSINESS_TIMEZONE' => 'Asia/Jakarta',
            'SESSION_DRIVER' => 'array', 'CACHE_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'null', 'QUEUE_CONNECTION' => 'sync'];
        foreach ($env as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $this->app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $this->app->addAbsoluteCachePathPrefix(substr($runtime, 0, 3));
        $this->app->useEnvironmentPath($runtime)->loadEnvironmentFrom('absent.env');
        $this->app->useStoragePath($runtime.'/storage');
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        DB::beginTransaction();
        DB::statement('SET TRANSACTION READ ONLY');
        $identity = (array) DB::selectOne('SELECT current_database() AS db,current_user AS role,host(inet_server_addr()) AS host,inet_server_port() AS port,(SELECT system_identifier::text FROM pg_control_system()) AS cluster');
        self::assertSame(['db' => $database, 'role' => 'fp_dev_app', 'host' => '127.0.0.1', 'port' => 55432, 'cluster' => '7694154787604477244'], $identity);
        self::assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('fp_submissions', 'onboarding_contract_version'), 'Migration must already have passed controlled disposable installation');
        DB::rollBack();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $this->app->instance(DocumentStorage::class, new class implements DocumentStorage
        {
            private array $files = [];

            public function put(string $path, string $bytes): void
            {
                $this->files[$path] = $bytes;
            }

            public function read(string $path): string
            {
                return $this->files[$path];
            }

            public function delete(string $path): void
            {
                unset($this->files[$path]);
            }

            public function adapter(): string
            {
                return 'local';
            }
        });
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            try {
                if (DB::transactionLevel() > 0) {
                    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                }
            } finally {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                $this->app->flush();
                \Illuminate\Foundation\Bootstrap\HandleExceptions::flushState($this);
            }
        }
    }

    private function actor(string $role): \App\Models\User
    {
        $id = DB::table('users as u')->join('model_has_roles as mr', 'mr.model_id', '=', 'u.id')->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.model_type', 'App\Models\User')->where('r.guard_name', 'web')->where('r.name', $role)->where('u.is_active', true)->value('u.id');
        self::assertNotNull($id, 'Required existing disposable role fixture unavailable');

        return \App\Models\User::findOrFail($id);
    }

    private function registration(array $documents = []): array
    {
        $team = DB::table('team_profiles')->where('is_active', true)->value('team_id');
        self::assertNotNull($team);
        $input = ['full_name' => 'Synthetic F37H', 'email' => 'synthetic@example.test', 'phone' => '+628123456789', 'bank_account' => null,
            'nik' => (string) random_int(1000000000000000, 9999999999999999), 'team_id' => $team, 'address_ktp' => 'Synthetic address', 'address_current' => 'Synthetic domicile',
            'birth_place' => 'Synthetic city', 'birth_date' => '2000-01-01', 'education_level' => 'Sarjana (S1)', 'education_institution' => 'Synthetic school', 'graduation_year' => 2020];
        $receipt = app(FpEngine::class)->submitFpApplication($input, $documents, (string) Str::uuid());
        $sid = (int) DB::table('fp_submissions')->where('uuid', $receipt['receipt'])->value('id');

        return [$sid, (int) $team, $input];
    }

    public static function documentSets(): array
    {
        return [[[]], [['KTP']], [['IJAZAH']], [['KTP', 'IJAZAH']]];
    }

    #[DataProvider('documentSets')]
    public function test_registration_and_atomic_approval_without_manual_verification(array $types): void
    {
        $uploads = [];
        foreach ($types as $type) {
            $uploads[$type] = \Illuminate\Http\UploadedFile::fake()->image('synthetic.png', 320, 320);
        }
        $documents = app(\App\Infrastructure\FP\Uploads::class)->stage($uploads);
        [$sid, $team] = $this->registration($documents);
        $actor = $this->actor('admin');
        $canonicalMm = DB::table('team_profiles')->where('team_id', $team)->value('mm_fp_id');
        $source = ['recruitment_team_id' => $team, 'approval_mm_name_snapshot' => '  Synthetic MM  '];
        $mid = app(FpEngine::class)->approveFpSubmission($actor, $sid, $source);
        self::assertSame($mid, app(FpEngine::class)->approveFpSubmission($actor, $sid, []));
        $sub = DB::table('fp_submissions')->find($sid);
        $member = DB::table('financial_planners')->find($mid);
        self::assertSame('APPROVED', $sub->status);
        self::assertNull($sub->bank_account_encrypted);
        self::assertNull($sub->biodata_verified_revision);
        self::assertNull($sub->documents_verified_revision);
        self::assertSame('Synthetic MM', $member->approval_mm_name_snapshot);
        self::assertSame($canonicalMm, DB::table('team_profiles')->where('team_id', $team)->value('mm_fp_id'));
        self::assertMatchesRegularExpression('/^[0-9]{6}$/', (string) $member->nip);
        self::assertSame(0, DB::table('fp_audit_events')->where('submission_id', $sid)->whereIn('event', ['BIODATA_VERIFIED', 'DOCUMENTS_VERIFIED'])->count());
        self::assertSame(1, DB::table('fp_audit_events')->where('submission_id', $sid)->where('event', 'APPROVED')->count());
        self::assertSame(count($types), DB::table('fp_current_documents')->where('membership_id', $mid)->count());
    }

    public function test_admin_can_fill_missing_documents_before_approval(): void
    {
        [$sid, $team] = $this->registration();
        $actor = $this->actor('admin');
        self::assertSame(0, DB::table('fp_documents')->where('submission_id', $sid)->count());

        foreach (['KTP', 'IJAZAH'] as $type) {
            app(FillSubmissionDocument::class)->execute($actor, $sid, $type, \Illuminate\Http\UploadedFile::fake()->image('synthetic.png', 320, 320));
        }

        self::assertSame(2, DB::table('fp_documents')->where('submission_id', $sid)->count());
        self::assertSame(3, (int) DB::table('fp_submissions')->where('id', $sid)->value('document_revision'));
        // The second fill advances every current document to the new revision,
        // so the unchanged KTP also needs a revision-change audit event.
        self::assertSame(3, DB::table('fp_audit_events')->where('submission_id', $sid)->where('event', 'DOCUMENT_CHANGED')->count());
        $mid = app(FpEngine::class)->approveFpSubmission($actor, $sid, ['recruitment_team_id' => $team, 'approval_mm_name_snapshot' => 'Synthetic MM']);
        self::assertSame(2, DB::table('fp_documents')->where('submission_id', $sid)->where('membership_id', $mid)->count());
    }

    public function test_hrd_can_fill_missing_documents_before_approval(): void
    {
        [$sid, $team] = $this->registration();
        $actor = $this->actor('hrd');
        self::assertSame(0, DB::table('fp_documents')->where('submission_id', $sid)->count());

        app(FillSubmissionDocument::class)->execute($actor, $sid, 'KTP', \Illuminate\Http\UploadedFile::fake()->image('synthetic.png', 320, 320));

        self::assertSame(1, DB::table('fp_documents')->where('submission_id', $sid)->count());
        self::assertSame(2, (int) DB::table('fp_submissions')->where('id', $sid)->value('document_revision'));
        self::assertSame(1, DB::table('fp_audit_events')->where('submission_id', $sid)->where('event', 'DOCUMENT_CHANGED')->count());
    }

    public function test_admin_fills_bca_and_appends_document_replacements_without_overwriting_history(): void
    {
        [$sid, $team, $input] = $this->registration();
        $actor = $this->actor('admin');
        $mid = app(FpEngine::class)->approveFpSubmission($actor, $sid, ['recruitment_team_id' => $team, 'approval_mm_name_snapshot' => 'Synthetic MM']);
        $nip = DB::table('financial_planners')->where('id', $mid)->value('nip');
        $profile = array_intersect_key($input, array_flip(AmendFpBiodata::FIELDS));
        $profile['bank_account'] = '0012345678';
        app(AmendFpBiodata::class)->execute($actor, $mid, [...$profile, 'expected_revision' => 0]);
        self::assertSame('0012345678', \App\Models\FpMembership::findOrFail($mid)->bank_account_encrypted);
        self::assertNull(DB::table('financial_planners')->where('id', $mid)->value('bank_account_encrypted'));
        foreach (['KTP', 'IJAZAH', 'KTP'] as $type) {
            app(AmendFpDocument::class)->execute($actor, $mid, $type, AmendFpDocument::revision($mid, $type), \Illuminate\Http\UploadedFile::fake()->image('synthetic.png', 320, 320));
        }
        self::assertSame(3, DB::table('fp_document_amendments')->where('membership_id', $mid)->count());
        self::assertSame(2, DB::table('fp_current_documents')->where('membership_id', $mid)->count());
        self::assertSame($nip, DB::table('financial_planners')->where('id', $mid)->value('nip'));
        self::assertSame(0, DB::table('fp_documents')->where('submission_id', $sid)->count());
    }

    private function rejectsSql(callable $work, string $state, string $message): void
    {
        DB::statement('SAVEPOINT f37h_negative');
        $rejected = false;
        try {
            $work();
        } catch (\Illuminate\Database\QueryException $error) {
            $rejected = true;
            self::assertSame($state, $error->errorInfo[0] ?? null);
            self::assertStringContainsString($message, $error->errorInfo[2] ?? '');
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT f37h_negative');
            DB::statement('RELEASE SAVEPOINT f37h_negative');
        }
        self::assertTrue($rejected, 'Expected database invariant was not enforced');
    }

    public function test_optional_contract_still_rejects_fake_verification_and_missing_mm(): void
    {
        [$sid, $team] = $this->registration();
        $this->rejectsSql(fn () => DB::table('fp_submissions')->where('id', $sid)->update([
            'biodata_verified_revision' => 1, 'biodata_verified_at' => now(), 'biodata_verified_by' => $this->actor('admin')->id, 'biodata_verified_actor_id' => $this->actor('admin')->id,
        ]), 'P0001', 'manual verification retired');
        try {
            app(FpEngine::class)->approveFpSubmission($this->actor('admin'), $sid, ['recruitment_team_id' => $team]);
            self::fail('Approval without MM was accepted');
        } catch (\Illuminate\Validation\ValidationException $error) {
            self::assertArrayHasKey('approval_mm_name_snapshot', $error->errors());
        }
        self::assertSame('PENDING', DB::table('fp_submissions')->where('id', $sid)->value('status'));
        self::assertFalse(DB::table('financial_planners')->where('submission_id', $sid)->exists());
    }

    public function test_hrd_ro_keep_approval_rights_but_bm_cannot_approve_or_replace_documents(): void
    {
        foreach (['hrd', 'ro'] as $role) {
            [$sid, $team] = $this->registration();
            $mid = app(FpEngine::class)->approveFpSubmission($this->actor($role), $sid, ['recruitment_team_id' => $team, 'approval_mm_name_snapshot' => 'Synthetic MM']);
            self::assertSame('ACTIVE', DB::table('financial_planners')->where('id', $mid)->value('lifecycle_state'));
            $this->rejectsSql(fn () => DB::selectOne('SELECT public.fp_amend_membership_document(?,?,?,?,?::jsonb)', [$this->actor($role)->id, $mid, 'KTP', 0, '{}']), '42501', 'active admin required');
        }
        [$sid, $team] = $this->registration();
        try {
            app(FpEngine::class)->approveFpSubmission($this->actor('bm'), $sid, ['recruitment_team_id' => $team, 'approval_mm_name_snapshot' => 'Synthetic MM']);
            self::fail('BM approval accepted');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertSame('PENDING', DB::table('fp_submissions')->where('id', $sid)->value('status'));
        }
        $mid = app(FpEngine::class)->approveFpSubmission($this->actor('admin'), $sid, ['recruitment_team_id' => $team, 'approval_mm_name_snapshot' => 'Synthetic MM']);
        $this->rejectsSql(fn () => DB::selectOne('SELECT public.fp_amend_membership_document(?,?,?,?,?::jsonb)', [$this->actor('bm')->id, $mid, 'KTP', 0, '{}']), '42501', 'active admin required');
    }

    public function test_nip_mm_document_versions_and_permissions_cannot_be_bypassed(): void
    {
        [$sid, $team] = $this->registration();
        $actor = $this->actor('admin');
        $mid = app(FpEngine::class)->approveFpSubmission($actor, $sid, ['recruitment_team_id' => $team, 'approval_mm_name_snapshot' => 'Synthetic MM']);
        $this->rejectsSql(fn () => DB::table('financial_planners')->where('id', $mid)->update(['nip' => 999999]), '23514', 'ownership/NIP immutable');
        $this->rejectsSql(fn () => DB::table('financial_planners')->where('id', $mid)->update(['approval_mm_name_snapshot' => 'Different MM']), 'P0001', 'MM snapshot immutable');
        $document = \Illuminate\Http\UploadedFile::fake()->image('synthetic.png', 320, 320);
        app(AmendFpDocument::class)->execute($actor, $mid, 'KTP', 0, $document);
        try {
            app(AmendFpDocument::class)->execute($actor, $mid, 'KTP', 0, $document);
            self::fail('Stale document replacement accepted');
        } catch (\Illuminate\Validation\ValidationException) {
            self::assertSame(1, DB::table('fp_document_amendments')->where('membership_id', $mid)->count());
        }
        $privileges = (array) DB::selectOne("SELECT has_table_privilege(current_user,'public.fp_document_amendments','INSERT') AS ins,has_table_privilege(current_user,'public.fp_document_amendments','UPDATE') AS upd,has_table_privilege(current_user,'public.fp_document_amendments','DELETE') AS del,has_schema_privilege(current_user,'public','CREATE') AS ddl");
        self::assertSame(['ins' => false, 'upd' => false, 'del' => false, 'ddl' => false], $privileges);
    }

    public function test_approval_failure_rolls_back_nip_membership_and_terminal_state(): void
    {
        [$sid, $team] = $this->registration();
        $counter = DB::table('fp_nip_year_counters')->orderBy('year')->get()->toJson();
        $claims = DB::table('fp_identity_claims')->where('submission_id', $sid)->get()->toJson();
        DB::listen(static function ($query): void {
            if (str_starts_with($query->sql, 'insert into "fp_audit_events"') && in_array('APPROVED', $query->bindings, true)) {
                throw new \RuntimeException('Synthetic audit failure before COMMIT');
            }
        });
        try {
            app(FpEngine::class)->approveFpSubmission($this->actor('admin'), $sid, ['recruitment_team_id' => $team, 'approval_mm_name_snapshot' => 'Synthetic MM']);
            self::fail('Injected audit failure ignored');
        } catch (\RuntimeException $error) {
            self::assertSame('Synthetic audit failure before COMMIT', $error->getMessage());
        }
        self::assertSame('PENDING', DB::table('fp_submissions')->where('id', $sid)->value('status'));
        self::assertFalse(DB::table('financial_planners')->where('submission_id', $sid)->exists());
        self::assertSame($counter, DB::table('fp_nip_year_counters')->orderBy('year')->get()->toJson());
        self::assertSame($claims, DB::table('fp_identity_claims')->where('submission_id', $sid)->get()->toJson());
        self::assertFalse(DB::table('fp_audit_events')->where('submission_id', $sid)->where('event', 'APPROVED')->exists());
    }

    public function test_archived_profile_metadata_is_preserved_but_new_photo_and_inactive_approval_are_denied(): void
    {
        [$sid, $team] = $this->registration();
        $photo = DB::table('fp_documents')->where('type', 'PROFILE_PHOTO')->first();
        self::assertNotNull($photo, 'Existing historical photo fixture required');
        $before = json_encode($photo, JSON_THROW_ON_ERROR);
        $copy = (array) $photo;
        unset($copy['id']);
        $copy['uuid'] = (string) Str::uuid();
        $copy['submission_id'] = $sid;
        $copy['membership_id'] = null;
        $copy['revision'] = 1;
        $copy['deletion_state'] = 'NONE';
        $this->rejectsSql(fn () => DB::table('fp_documents')->insert($copy), 'P0001', 'profile photos retired');
        self::assertSame($before, json_encode(DB::table('fp_documents')->find($photo->id), JSON_THROW_ON_ERROR));
        self::assertSame(0, DB::table('fp_current_documents')->where('type', 'PROFILE_PHOTO')->count());
        $inactive = \App\Models\User::where('is_active', false)->firstOrFail();
        try {
            app(FpEngine::class)->approveFpSubmission($inactive, $sid, ['recruitment_team_id' => $team, 'approval_mm_name_snapshot' => 'Synthetic MM']);
            self::fail('Inactive actor approved');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertSame('PENDING', DB::table('fp_submissions')->where('id', $sid)->value('status'));
        }
    }

    public function test_optional_recruiter_and_admin_historical_correction_preserve_ownership_and_exit(): void
    {
        [$sid, $team, $input] = $this->registration();
        $recruiter = DB::table('financial_planners')->where('lifecycle_state', 'ACTIVE')->first();
        self::assertNotNull($recruiter);
        $actor = $this->actor('admin');
        $mid = app(FpEngine::class)->approveFpSubmission($actor, $sid, ['recruitment_team_id' => $team, 'source_fp_id' => $recruiter->id, 'approval_mm_name_snapshot' => 'Synthetic MM']);
        $original = DB::table('financial_planners')->find($mid);
        self::assertSame('FP', $original->source_type);
        self::assertSame($recruiter->id, $original->source_fp_id);
        app(FpEngine::class)->exitFp($actor, $mid, $original->reference_date, 'Synthetic test exit');
        $exit = json_encode(DB::table('fp_exits')->where('membership_id', $mid)->first(), JSON_THROW_ON_ERROR);
        $data = array_intersect_key($input, array_flip(AmendFpBiodata::FIELDS));
        $data['full_name'] = 'Synthetic historical correction';
        $data['bank_account'] = '0012345678';
        app(AmendFpBiodata::class)->execute($actor, $mid, [...$data, 'expected_revision' => 0]);
        $current = \App\Models\FpMembership::findOrFail($mid);
        self::assertSame('Synthetic historical correction', $current->full_name);
        self::assertSame('EXITED', $current->lifecycle_state->value);
        self::assertSame($original->nip, $current->nip);
        self::assertSame($original->full_name, DB::table('financial_planners')->where('id', $mid)->value('full_name'));
        self::assertSame($original->identity_id, DB::table('financial_planners')->where('id', $mid)->value('identity_id'));
        self::assertSame($exit, json_encode(DB::table('fp_exits')->where('membership_id', $mid)->first(), JSON_THROW_ON_ERROR));
        try {
            app(AmendFpBiodata::class)->execute($this->actor('hrd'), $mid, [...$data, 'expected_revision' => 1]);
            self::fail('HRD historical correction unexpectedly authorized');
        } catch (\Illuminate\Validation\ValidationException) {
            self::assertSame(1, AmendFpBiodata::revision($mid));
        }
    }
}
