<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\FP\DocumentController;
use App\Infrastructure\FP\DocumentStorage;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PrivateDocumentHttpRegressionTest extends TestCase
{
    private const UUID = '11111111-1111-4111-8111-111111111111';

    private string $bytes;

    private bool $missing = false;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['app.debug' => false, 'fp.enabled' => true, 'fp.core_schema_only' => false, 'fp.optional_onboarding_enabled' => true, 'fp.storage_adapter' => 'local']);
        Schema::create('fp_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('submission_id')->nullable();
            $table->unsignedBigInteger('membership_id')->nullable();
            $table->string('type');
            $table->integer('revision');
            $table->string('processing_state');
            $table->string('deletion_state');
            $table->string('storage_adapter');
            $table->string('private_path');
            $table->string('mime');
            $table->integer('bytes');
            $table->string('sha256');
        });
        Schema::create('fp_document_amendments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('submission_id')->nullable();
            $table->unsignedBigInteger('membership_id');
            $table->string('type');
            $table->integer('revision');
            $table->uuid('previous_uuid')->nullable();
            $table->string('storage_adapter');
            $table->string('private_path');
            $table->string('mime');
            $table->integer('bytes');
            $table->string('sha256');
        });
        DB::statement("CREATE VIEW fp_current_documents AS SELECT * FROM fp_documents WHERE type IN ('KTP','IJAZAH') AND NOT EXISTS(SELECT 1 FROM fp_document_amendments a WHERE a.membership_id=fp_documents.membership_id AND a.type=fp_documents.type)");
        $this->bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP1sAAAAASUVORK5CYII=');
        $test = $this;
        $this->app->instance(DocumentStorage::class, new class($test) implements DocumentStorage
        {
            public function __construct(private PrivateDocumentHttpRegressionTest $test) {}

            public function read(string $path): string
            {
                return $this->test->readFixture();
            }

            public function adapter(): string
            {
                return 'local';
            }

            public function put(string $path, string $bytes): void
            {
                throw new \LogicException('Read-only HTTP regression');
            }

            public function delete(string $path): void
            {
                throw new \LogicException('Read-only HTTP regression');
            }
        });
        Route::middleware(['web', \App\Http\Middleware\FpReportAuthenticate::class, \App\Http\Middleware\FpEnabled::class])->get('/fp-private/documents/{uuid}', [DocumentController::class, 'show'])->name('fp.document');
        $this->record();
    }

    public function readFixture(): string
    {
        if ($this->missing) {
            throw new \RuntimeException('Synthetic private locator must not appear in response');
        }

        return $this->bytes;
    }

    private function actor(string $role, bool $active = true): User
    {
        $user = new User;
        $user->forceFill(['id' => 101, 'name' => 'Synthetic', 'email' => 'synthetic@example.test', 'is_active' => $active]);
        $user->setRelation('roles', new Collection([new Role(['name' => $role, 'guard_name' => 'web'])]));

        return $user;
    }

    private function record(array $changes = []): void
    {
        DB::table('fp_documents')->insert(array_replace(['uuid' => self::UUID, 'submission_id' => 2, 'membership_id' => 3, 'type' => 'KTP', 'revision' => 1, 'processing_state' => 'READY', 'deletion_state' => 'NONE', 'storage_adapter' => 'local', 'private_path' => 'synthetic/private.jpg', 'mime' => 'image/png', 'bytes' => strlen($this->bytes), 'sha256' => hash('sha256', $this->bytes)], $changes));
    }

    public static function rolesAndTypes(): array
    {
        $cases = [];
        foreach (['admin', 'hrd', 'ro', 'bm'] as $role) {
            foreach (['KTP', 'IJAZAH'] as $type) {
                $cases[$role.' '.$type] = [$role, $type];
            }
        }

        return $cases;
    }

    public static function roles(): array
    {
        return [['admin'], ['hrd'], ['ro'], ['bm']];
    }

    #[DataProvider('roles')]
    public function test_authorized_superseded_original_and_amendment_versions_remain_private(string $role): void
    {
        $this->actingAs($this->actor($role));
        foreach (['KTP', 'IJAZAH'] as $type) {
            DB::table('fp_documents')->update(['type' => $type]);
            $uuid = (string) \Illuminate\Support\Str::uuid();
            DB::table('fp_document_amendments')->insert(['uuid' => $uuid, 'submission_id' => 2, 'membership_id' => 3, 'type' => $type, 'revision' => 1, 'previous_uuid' => self::UUID, 'storage_adapter' => 'local', 'private_path' => 'synthetic/version.jpg', 'mime' => 'image/png', 'bytes' => strlen($this->bytes), 'sha256' => hash('sha256', $this->bytes)]);
            foreach ([self::UUID, $uuid] as $version) {
                $response = $this->get('/fp-private/documents/'.$version)->assertOk();
                self::assertSame($this->bytes, $response->getContent());
                self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            }
        }
    }

    #[DataProvider('rolesAndTypes')]
    public function test_authorized_roles_get_real_image_bytes_and_private_headers(string $role, string $type): void
    {
        DB::table('fp_documents')->update(['type' => $type]);
        $response = $this->actingAs($this->actor($role))->get('/fp-private/documents/'.self::UUID);
        $response->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame($this->bytes, $response->getContent());
        self::assertNotFalse(getimagesizefromstring($response->getContent()));
        self::assertStringNotContainsString('synthetic/private', $response->getContent());
    }

    public function test_guest_is_denied(): void
    {
        $this->getJson('/fp-private/documents/'.self::UUID)->assertUnauthorized();
    }

    public function test_inactive_user_is_denied(): void
    {
        $this->actingAs($this->actor('hrd', false))->getJson('/fp-private/documents/'.self::UUID)->assertForbidden();
    }

    public function test_unknown_role_is_denied(): void
    {
        $this->actingAs($this->actor('external'))->getJson('/fp-private/documents/'.self::UUID)->assertForbidden();
    }

    public function test_unknown_document_is_not_found(): void
    {
        $this->actingAs($this->actor('hrd'))->getJson('/fp-private/documents/00000000-0000-4000-8000-000000000000')->assertNotFound();
    }

    public function test_malformed_identifier_is_rejected_before_querying_document_storage(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'fp_documents') || str_contains($query->sql, 'fp_current_documents')) {
                $queries[] = $query->sql;
            }
        });
        $this->actingAs($this->actor('hrd'))->getJson('/fp-private/documents/not-a-uuid')->assertNotFound();
        self::assertSame([], $queries);
    }

    public function test_missing_private_file_fails_closed_without_leaking_locator(): void
    {
        $this->missing = true;
        $this->actingAs($this->actor('hrd'))->getJson('/fp-private/documents/'.self::UUID)->assertStatus(503)->assertDontSee('synthetic/private')->assertDontSee('Synthetic private locator');
    }

    public function test_integrity_mismatch_never_returns_document_bytes(): void
    {
        DB::table('fp_documents')->update(['sha256' => str_repeat('0', 64)]);
        $response = $this->actingAs($this->actor('hrd'))->getJson('/fp-private/documents/'.self::UUID)->assertStatus(503);
        self::assertNotSame($this->bytes, $response->getContent());
    }

    public function test_retired_photo_and_unready_document_are_not_exposed(): void
    {
        $this->actingAs($this->actor('admin'));
        DB::table('fp_documents')->update(['type' => 'PROFILE_PHOTO']);
        $this->getJson('/fp-private/documents/'.self::UUID)->assertNotFound();
        DB::table('fp_documents')->update(['type' => 'KTP', 'processing_state' => 'PROCESSING']);
        $this->getJson('/fp-private/documents/'.self::UUID)->assertNotFound();
    }
}
