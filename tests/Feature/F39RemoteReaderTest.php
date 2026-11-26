<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\FP\ReadRemoteDocument;
use App\Application\FP\VictoryAccess;
use App\Infrastructure\FP\NextcloudRevisionStorage;
use App\Infrastructure\FP\NextcloudWebDavClient;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class F39RemoteReaderTest extends TestCase
{
    private const MEMBERSHIP = '11111111-1111-4111-8111-111111111111';

    private const HISTORICAL = '22222222-2222-4222-8222-222222222221';

    private const CURRENT = '22222222-2222-4222-8222-222222222222';

    private string $bytes;

    private Factory $http;

    private string $remoteMode = 'normal';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'fp.enabled' => true, 'fp.core_schema_only' => false]);
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        self::assertStringStartsWith($this->app->basePath(), (new \ReflectionClass(ReadRemoteDocument::class))->getFileName());
        Schema::create('financial_planners', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('submission_id')->nullable();
        });
        Schema::create('fp_document_operations', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->string('operation_type');
            $table->unsignedBigInteger('membership_id');
            $table->string('type');
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('expected_revision');
            $table->unsignedBigInteger('target_revision');
            $table->uuid('revision_uuid');
            $table->string('storage_adapter');
            $table->string('private_path');
            $table->string('mime');
            $table->unsignedBigInteger('bytes');
            $table->string('sha256');
            $table->string('state');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('committed_at')->nullable();
        });
        Schema::create('fp_document_amendments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('submission_id')->nullable();
            $table->string('type');
            $table->unsignedBigInteger('revision');
            $table->unsignedBigInteger('actor_id');
            $table->string('storage_adapter');
            $table->string('private_path');
            $table->string('mime');
            $table->unsignedBigInteger('bytes');
            $table->string('sha256');
            $table->uuid('operation_uuid');
            $table->timestamp('integrity_verified_at')->nullable();
        });
        $this->bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP1sAAAAASUVORK5CYII=');
        $this->http = new Factory;
        $this->http->preventStrayRequests();
        $this->http->fake(function () {
            return match ($this->remoteMode) {
                'missing' => Factory::response('', 404),
                'outage' => throw new \RuntimeException('synthetic private upstream diagnostic'),
                'corrupt' => Factory::response('synthetic invalid private content', 200),
                default => Factory::response($this->bytes, 200, ['Content-Type' => 'application/x-bin']),
            };
        });
        $adapter = new NextcloudRevisionStorage(new NextcloudWebDavClient($this->http, 'https://storage.invalid', 'synthetic-user', 'synthetic-password', 'F39-Test', false));
        $this->app->instance(NextcloudRevisionStorage::class, $adapter);
        require $this->app->basePath('routes/f39-disposable.php');
        DB::table('financial_planners')->insert(['id' => 1, 'uuid' => self::MEMBERSHIP, 'submission_id' => 10]);
        $this->record(self::HISTORICAL, 1);
        $this->record(self::CURRENT, 2);
    }

    private function actor(string $role = 'admin', bool $active = true): User
    {
        $actor = new User;
        $actor->forceFill(['id' => 101, 'name' => 'Synthetic reader', 'email' => 'reader@example.test', 'is_active' => $active]);
        $actor->setRelation('roles', new Collection([new Role(['name' => $role, 'guard_name' => 'web'])]));

        return $actor;
    }

    private function record(string $revisionUuid, int $sequence): void
    {
        $document = Uuid::uuid5(self::MEMBERSHIP, 'KTP')->toString();
        $operation = Uuid::uuid4()->toString();
        $common = ['membership_id' => 1, 'type' => 'KTP', 'actor_id' => 101, 'storage_adapter' => 'nextcloud',
            'private_path' => 'documents/'.self::MEMBERSHIP.'/'.$document.'/revision-'.$revisionUuid.'/document.bin',
            'mime' => 'image/png', 'bytes' => strlen($this->bytes), 'sha256' => hash('sha256', $this->bytes)];
        DB::table('fp_document_operations')->insert($common + ['uuid' => $operation, 'operation_type' => 'ADMIN_DOCUMENT_AMENDMENT',
            'expected_revision' => $sequence - 1, 'target_revision' => $sequence, 'revision_uuid' => $revisionUuid,
            'state' => 'COMMITTED', 'verified_at' => '2026-11-18 00:00:00', 'committed_at' => '2026-11-18 00:01:00']);
        DB::table('fp_document_amendments')->insert($common + ['uuid' => $revisionUuid, 'submission_id' => 10,
            'revision' => $sequence, 'operation_uuid' => $operation, 'integrity_verified_at' => '2026-11-18 00:00:00']);
    }

    private function url(string $revision = self::CURRENT, ?string $document = null): string
    {
        $document ??= Uuid::uuid5(self::MEMBERSHIP, 'KTP')->toString();

        return '/fp-disposable/f39/documents/'.$document.'/revisions/'.$revision;
    }

    public static function rolesAndVersions(): array
    {
        $cases = [];
        foreach (['admin', 'hrd', 'ro', 'bm'] as $role) {
            foreach ([self::CURRENT, self::HISTORICAL] as $revision) {
                $cases[] = [$role, $revision];
            }
        }

        return $cases;
    }

    #[DataProvider('rolesAndVersions')]
    public function test_existing_view_roles_read_current_and_historical_remote_revisions(string $role, string $revision): void
    {
        $response = $this->actingAs($this->actor($role))->get($this->url($revision));
        $response->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename="document.png"')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        self::assertSame($this->bytes, $response->getContent());
        self::assertStringContainsString('private', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->http->assertSentCount(1);
    }

    public function test_guest_unknown_and_inactive_roles_are_denied_before_remote_io(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
        $this->actingAs($this->actor('external'))->getJson($this->url())->assertForbidden();
        $this->actingAs($this->actor('hrd', false))->getJson($this->url(self::HISTORICAL))->assertForbidden();
        $this->http->assertNothingSent();
    }

    public function test_bm_read_does_not_grant_edit_and_route_has_no_write_operation(): void
    {
        $actor = $this->actor('bm');
        self::assertFalse(VictoryAccess::allowed($actor, 'f2.documents'));
        $this->actingAs($actor)->postJson($this->url())->assertStatus(405);
        $this->http->assertNothingSent();
    }

    public function test_malformed_document_or_revision_is_rejected_before_remote_io(): void
    {
        $this->actingAs($this->actor('hrd'));
        $this->getJson($this->url(self::CURRENT, 'not-a-uuid'))->assertNotFound();
        $this->getJson($this->url('not-a-uuid'))->assertNotFound();
        $this->getJson($this->url(Uuid::uuid4()->toString()))->assertNotFound();
        $this->http->assertNothingSent();
    }

    public function test_another_fp_document_identity_cannot_be_paired_with_this_revision(): void
    {
        $anotherDocument = Uuid::uuid5('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'KTP')->toString();
        $this->actingAs($this->actor('admin'))->getJson($this->url(self::CURRENT, $anotherDocument))->assertNotFound();
        $this->http->assertNothingSent();
    }

    public function test_cross_fp_paired_access_preserves_existing_global_role_scope(): void
    {
        // All four active view roles already have global document-view access.
        $this->actingAs($this->actor('hrd'))->get($this->url())->assertOk();
        $this->http->assertSentCount(1);
    }

    public function test_uncommitted_operation_and_mismatched_canonical_tuple_fail_closed(): void
    {
        DB::table('fp_document_operations')->where('revision_uuid', self::CURRENT)->update(['state' => 'VERIFYING']);
        $this->actingAs($this->actor())->getJson($this->url())->assertNotFound();
        DB::table('fp_document_operations')->where('revision_uuid', self::CURRENT)->update(['state' => 'COMMITTED', 'bytes' => 999]);
        $this->getJson($this->url())->assertNotFound();
        $this->http->assertNothingSent();
    }

    public function test_metadata_path_traversal_is_rejected_before_remote_io(): void
    {
        DB::table('fp_document_operations')->update(['private_path' => '../private']);
        DB::table('fp_document_amendments')->update(['private_path' => '../private']);
        $this->actingAs($this->actor())->getJson($this->url())->assertNotFound();
        $this->http->assertNothingSent();
    }

    public function test_missing_remote_object_returns_sanitized_not_found(): void
    {
        $this->remoteMode = 'missing';
        $this->actingAs($this->actor())->getJson($this->url())->assertNotFound()
            ->assertDontSee('storage.invalid')->assertDontSee('synthetic-password')->assertDontSee('documents/');
    }

    public function test_outage_returns_sanitized_service_error_without_private_bytes(): void
    {
        $this->remoteMode = 'outage';
        $response = $this->actingAs($this->actor())->getJson($this->url())->assertStatus(503)
            ->assertDontSee('private upstream diagnostic')->assertDontSee('storage.invalid')->assertDontSee('synthetic-password');
        self::assertNotSame($this->bytes, $response->getContent());
    }

    public function test_remote_hash_mismatch_never_returns_private_bytes(): void
    {
        $this->remoteMode = 'corrupt';
        $this->actingAs($this->actor())->getJson($this->url())->assertStatus(503)
            ->assertDontSee('synthetic invalid private content');
    }

    public function test_valid_hash_with_wrong_canonical_mime_is_rejected(): void
    {
        DB::table('fp_document_operations')->update(['mime' => 'image/jpeg']);
        DB::table('fp_document_amendments')->update(['mime' => 'image/jpeg']);
        $this->actingAs($this->actor())->getJson($this->url())->assertStatus(503);
        $this->http->assertSentCount(1);
    }

    public function test_unsupported_metadata_mime_and_size_are_rejected_before_io(): void
    {
        DB::table('fp_document_operations')->update(['mime' => 'text/html']);
        DB::table('fp_document_amendments')->update(['mime' => 'text/html']);
        $this->actingAs($this->actor())->getJson($this->url())->assertStatus(503);
        DB::table('fp_document_operations')->update(['mime' => 'image/png', 'bytes' => 8 * 1024 * 1024 + 1]);
        DB::table('fp_document_amendments')->update(['mime' => 'image/png', 'bytes' => 8 * 1024 * 1024 + 1]);
        $this->getJson($this->url())->assertStatus(503);
        $this->http->assertNothingSent();
    }

    public function test_active_local_storage_provider_remains_unchanged(): void
    {
        self::assertSame('local', config('fp.storage_adapter'));
        self::assertFalse(config('nextcloud.enabled'));
        $this->actingAs($this->actor())->get($this->url())->assertOk();
        self::assertSame('local', config('fp.storage_adapter'));
    }
}
