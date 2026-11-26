<?php

declare(strict_types=1);

namespace Tests\FpStorage;

use App\Infrastructure\FP\DocumentStorage;
use App\Infrastructure\FP\DocumentStorageRegistry;
use App\Jobs\FP\PublishApprovedFpDocuments;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class PublishApprovedFpDocumentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fp.enabled' => true,
            'fp.core_schema_only' => false,
            'fp.storage_adapter' => 'nextcloud',
            'fp.nextcloud.endpoint' => 'https://storage.invalid/dav',
            'fp.nextcloud.user' => 'synthetic-user',
            'fp.nextcloud.password' => 'synthetic-password',
        ]);
        Schema::create('fp_submissions', function ($table): void {
            $table->id();
            $table->string('status');
            $table->string('full_name')->nullable();
        });
        Schema::create('financial_planners', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('submission_id');
            $table->string('nip');
            $table->string('full_name');
            $table->string('lifecycle_state')->nullable();
        });
        Schema::create('fp_documents', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('submission_id');
            $table->unsignedBigInteger('membership_id');
            $table->string('type');
            $table->text('private_path');
            $table->string('mime');
            $table->integer('bytes');
            $table->string('sha256');
            $table->string('processing_state');
            $table->string('deletion_state');
        });
        DB::table('fp_submissions')->insert(['id' => 7, 'status' => 'APPROVED', 'full_name' => 'Budi Santoso']);
        DB::table('financial_planners')->insert(['id' => 11, 'submission_id' => 7, 'nip' => '1001', 'full_name' => 'Budi Santoso']);
        foreach (['KTP' => 'ktp-bytes', 'IJAZAH' => 'ijazah-bytes'] as $type => $bytes) {
            DB::table('fp_documents')->insert([
                'submission_id' => 7, 'membership_id' => 11, 'type' => $type,
                'private_path' => 'fp/'.strtolower($type).'/document.jpg', 'mime' => 'image/jpeg',
                'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
                'processing_state' => 'READY', 'deletion_state' => 'NONE',
            ]);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('fp_documents');
        Schema::dropIfExists('financial_planners');
        Schema::dropIfExists('fp_submissions');
        parent::tearDown();
    }

    private function local(): DocumentStorage
    {
        return new class implements DocumentStorage
        {
            public array $files = [
                'fp/ktp/document.jpg' => 'ktp-bytes',
                'fp/ijazah/document.jpg' => 'ijazah-bytes',
            ];

            public function put(string $path, string $bytes): void
            {
                $this->files[$path] = $bytes;
            }

            public function read(string $path): string
            {
                return $this->files[$path] ?? throw new RuntimeException('Missing staged document');
            }

            public function delete(string $path): void
            {
                unset($this->files[$path]);
            }

            public function adapter(): string
            {
                return 'local';
            }
        };
    }

    public function test_publication_job_is_queued_with_bounded_retries(): void
    {
        $job = new PublishApprovedFpDocuments(7, 11);
        self::assertInstanceOf(ShouldQueue::class, $job);
        self::assertSame(5, $job->tries);
        self::assertSame([30, 120, 600, 1800], $job->backoff());
    }

    public function test_approved_documents_are_published_to_name_and_nip_folder(): void
    {
        $objects = [];
        Http::fake(function ($request) use (&$objects) {
            if ($request->method() === 'HEAD') {
                return Factory::response('', array_key_exists($request->url(), $objects) ? 200 : 404);
            }
            if ($request->method() === 'MKCOL') {
                return Factory::response('', 201);
            }
            if ($request->method() === 'PUT') {
                if (array_key_exists($request->url(), $objects)) {
                    return Factory::response('', 412);
                }
                $objects[$request->url()] = $request->body();

                return Factory::response('', 201);
            }
            if ($request->method() === 'GET') {
                return Factory::response($objects[$request->url()] ?? '', 200);
            }

            return Factory::response('', 204);
        });
        $job = new PublishApprovedFpDocuments(7, 11);
        $registry = app(DocumentStorageRegistry::class);
        $local = $this->local();

        $job->handle($registry, $local);
        $job->handle($registry, $local);

        self::assertSame('ktp-bytes', $objects['https://storage.invalid/dav/fp/Budi%20Santoso_1001/ktp.jpg'] ?? null);
        self::assertSame('ijazah-bytes', $objects['https://storage.invalid/dav/fp/Budi%20Santoso_1001/ijazah.jpg'] ?? null);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/fp/Budi%20Santoso_1001/ktp.jpg'));
    }

    public function test_retry_command_republishes_an_approved_submission(): void
    {
        $objects = [];
        Http::fake(function ($request) use (&$objects) {
            if ($request->method() === 'HEAD') {
                return Factory::response('', array_key_exists($request->url(), $objects) ? 200 : 404);
            }
            if ($request->method() === 'MKCOL') {
                return Factory::response('', 201);
            }
            if ($request->method() === 'PUT') {
                $objects[$request->url()] = $request->body();

                return Factory::response('', 201);
            }
            if ($request->method() === 'GET') {
                return Factory::response($objects[$request->url()] ?? '', 200);
            }

            return Factory::response('', 204);
        });

        app()->instance(DocumentStorage::class, $this->local());
        self::assertSame(0, Artisan::call('fp:publish-approved-documents', ['submission' => 7]));
        self::assertStringContainsString('PUBLISHED', Artisan::output());
        self::assertArrayHasKey('https://storage.invalid/dav/fp/Budi%20Santoso_1001/ktp.jpg', $objects);
    }

    public function test_pending_or_mismatched_membership_is_not_published(): void
    {
        DB::table('fp_submissions')->where('id', 7)->update(['status' => 'PENDING']);
        Http::fake();
        (new PublishApprovedFpDocuments(7, 11))->handle(app(DocumentStorageRegistry::class), $this->local());
        Http::assertNothingSent();
    }
}
