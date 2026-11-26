<?php

declare(strict_types=1);

namespace Tests\FpStorage;

use App\Infrastructure\FP\DocumentStorage;
use App\Infrastructure\FP\DocumentStorageRegistry;
use App\Jobs\FP\PublishAmendedFpDocuments;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class PublishAmendedFpDocumentsTest extends TestCase
{
    private const OLD = 'old-ktp-bytes';

    private const NEW = 'new-ktp-bytes';

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
        });
        Schema::create('fp_document_amendments', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('submission_id');
            $table->string('type');
            $table->integer('revision');
            $table->string('storage_adapter');
            $table->string('private_path');
            $table->integer('bytes');
            $table->string('sha256');
        });
        DB::table('fp_submissions')->insert(['id' => 7, 'status' => 'APPROVED', 'full_name' => 'Budi Santoso']);
        DB::table('financial_planners')->insert(['id' => 11, 'submission_id' => 7, 'nip' => '1001', 'full_name' => 'Budi Santoso']);
        foreach ([[21, 1, self::OLD], [22, 2, self::NEW]] as [$id, $revision, $bytes]) {
            DB::table('fp_document_amendments')->insert([
                'id' => $id, 'membership_id' => 11, 'submission_id' => 7, 'type' => 'KTP',
                'revision' => $revision, 'storage_adapter' => 'local',
                'private_path' => 'fp/ktp/revision-'.$revision.'.jpg',
                'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            ]);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('fp_document_amendments');
        Schema::dropIfExists('financial_planners');
        Schema::dropIfExists('fp_submissions');
        parent::tearDown();
    }

    private function local(): DocumentStorage
    {
        return new class implements DocumentStorage
        {
            public array $files = [
                'fp/ktp/revision-1.jpg' => 'old-ktp-bytes',
                'fp/ktp/revision-2.jpg' => 'new-ktp-bytes',
            ];

            public function put(string $path, string $bytes): void
            {
                $this->files[$path] = $bytes;
            }

            public function read(string $path): string
            {
                return $this->files[$path] ?? throw new RuntimeException('Missing amended document');
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

    /** @return array{0: \Closure(): array<string, string>, 1: \Closure(): int} */
    private function fakeRemote(string $initial): array
    {
        $objects = ['https://storage.invalid/dav/fp/Budi%20Santoso_1001/ktp.jpg' => $initial];
        $puts = 0;
        Http::fake(function ($request) use (&$objects, &$puts) {
            if ($request->method() === 'HEAD') {
                return Factory::response('', array_key_exists($request->url(), $objects) ? 200 : 404, ['ETag' => '"v1"']);
            }
            if ($request->method() === 'MKCOL') {
                return Factory::response('', 201);
            }
            if ($request->method() === 'PUT') {
                $puts++;
                $objects[$request->url()] = $request->body();

                return Factory::response('', 201);
            }
            if ($request->method() === 'GET') {
                return Factory::response($objects[$request->url()] ?? '', 200);
            }

            return Factory::response('', 204);
        });

        return [
            static function () use (&$objects): array {
                return $objects;
            },
            static function () use (&$puts): int {
                return $puts;
            },
        ];
    }

    public function test_amendment_job_is_queued_with_bounded_retries(): void
    {
        $job = new PublishAmendedFpDocuments(22);
        self::assertInstanceOf(ShouldQueue::class, $job);
        self::assertSame(5, $job->tries);
        self::assertSame([30, 120, 600, 1800], $job->backoff());
    }

    public function test_latest_amendment_replaces_remote_bytes_and_retry_is_a_noop(): void
    {
        [$objects, $puts] = $this->fakeRemote(self::OLD);
        $registry = app(DocumentStorageRegistry::class);
        $local = $this->local();

        (new PublishAmendedFpDocuments(22))->handle($registry, $local);
        (new PublishAmendedFpDocuments(22))->handle($registry, $local);

        self::assertSame(self::NEW, $objects()['https://storage.invalid/dav/fp/Budi%20Santoso_1001/ktp.jpg']);
        self::assertSame(1, $puts());
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request->hasHeader('If-Match', '"v1"'));
    }

    public function test_stale_amendment_is_not_published(): void
    {
        [$objects, $puts] = $this->fakeRemote(self::NEW);
        (new PublishAmendedFpDocuments(21))->handle(app(DocumentStorageRegistry::class), $this->local());
        self::assertSame(self::NEW, $objects()['https://storage.invalid/dav/fp/Budi%20Santoso_1001/ktp.jpg']);
        self::assertSame(0, $puts());
    }

    public function test_retry_command_republishes_the_latest_amendment(): void
    {
        [$objects] = $this->fakeRemote(self::OLD);
        app()->instance(DocumentStorage::class, $this->local());
        self::assertSame(0, Artisan::call('fp:publish-amended-documents', ['amendment' => 22]));
        self::assertStringContainsString('PUBLISHED', Artisan::output());
        self::assertSame(self::NEW, $objects()['https://storage.invalid/dav/fp/Budi%20Santoso_1001/ktp.jpg']);
    }
}
