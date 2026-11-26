<?php

declare(strict_types=1);

namespace Tests\FpStorage;

use App\Infrastructure\FP\FpDocumentPath;
use App\Infrastructure\FP\NextcloudStorage;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NextcloudAliasStorageTest extends TestCase
{
    private const PRIVATE = 'fp/00000000-0000-4000-8000-000000000001/00000000-0000-4000-8000-000000000002.jpg';

    private const FRIENDLY = 'fp/Budi Santoso/ktp.jpg';

    private function http(bool $conflict = false): Factory
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $bytes = 'sKiLLed-image-bytes';
        $uploaded = false;
        $http->fake(function ($request) use ($bytes, $conflict, &$uploaded) {
            if ($request->method() === 'HEAD') {
                return Factory::response('', $uploaded ? 200 : 404);
            }
            if ($request->method() === 'MKCOL') {
                return Factory::response('', 201);
            }
            if ($request->method() === 'PUT') {
                if ($conflict && str_contains($request->url(), 'Budi%20Santoso')) {
                    return Factory::response('', 412);
                }
                if (str_contains($request->url(), 'Budi%20Santoso')) {
                    $uploaded = true;
                }

                return Factory::response('', 201);
            }
            if ($request->method() === 'GET') {
                return Factory::response(str_contains($request->url(), 'Budi%20Santoso') ? $bytes : FpDocumentPath::aliasPayload(self::FRIENDLY), 200);
            }
            if ($request->method() === 'DELETE' && str_contains($request->url(), 'Budi%20Santoso')) {
                $uploaded = false;
            }

            return Factory::response('', 204);
        });

        return $http;
    }

    private function store(Factory $http): NextcloudStorage
    {
        return new NextcloudStorage($http, 'https://storage.invalid/dav', 'synthetic-user', 'synthetic-password');
    }

    public function test_alias_upload_read_and_delete_use_the_readable_folder(): void
    {
        $http = $this->http();
        $storage = $this->store($http);

        $storage->putAliased(self::PRIVATE, 'sKiLLed-image-bytes', self::FRIENDLY);

        self::assertSame('sKiLLed-image-bytes', $storage->read(self::PRIVATE));
        self::assertTrue($storage->exists(self::FRIENDLY));
        $http->assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), 'Budi%20Santoso/ktp.jpg')
            && $request->hasHeader('If-None-Match', '*'));

        $storage->delete(self::PRIVATE);
        $http->assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), 'Budi%20Santoso/ktp.jpg'));
    }

    public function test_put_once_is_idempotent_without_overwriting(): void
    {
        $path = FpDocumentPath::approved('Budi Santoso', 1001, 'KTP');

        $http = $this->http();
        self::assertTrue($this->store($http)->putOnce($path, 'sKiLLed-image-bytes'));
        self::assertFalse($this->store($this->http(true))->putOnce($path, 'different-bytes'));
        $this->store($http)->delete($path);
        $http->assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), 'Budi%20Santoso_1001'));
    }

    public function test_name_conflict_is_a_validation_error_without_overwriting(): void
    {
        $http = $this->http(true);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Nama folder FP sudah digunakan');
        $this->store($http)->putAliased(self::PRIVATE, 'sKiLLed-image-bytes', self::FRIENDLY);
    }

    public function test_put_current_overwrites_changed_bytes_and_skips_identical_retries(): void
    {
        $path = FpDocumentPath::approved('Budi Santoso', 1001, 'KTP');
        $objects = ['https://storage.invalid/dav/fp/Budi%20Santoso_1001/ktp.jpg' => 'old-bytes'];
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(function ($request) use (&$objects) {
            if ($request->method() === 'HEAD') {
                return Factory::response('', array_key_exists($request->url(), $objects) ? 200 : 404, ['ETag' => '"v1"']);
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
        $storage = $this->store($http);

        self::assertTrue($storage->putCurrent($path, 'new-bytes'));
        self::assertFalse($storage->putCurrent($path, 'new-bytes'));
        self::assertSame('new-bytes', $objects['https://storage.invalid/dav/fp/Budi%20Santoso_1001/ktp.jpg']);
        $http->assertSent(fn ($request) => $request->method() === 'PUT' && $request->hasHeader('If-Match', '"v1"'));
    }

    public function test_put_current_rejects_a_concurrent_remote_update(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(function ($request) {
            if ($request->method() === 'HEAD' || $request->method() === 'GET') {
                return Factory::response('old-bytes', 200, ['ETag' => '"v1"']);
            }
            if ($request->method() === 'MKCOL') {
                return Factory::response('', 201);
            }

            return Factory::response('', 412);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Concurrent document update');
        $this->store($http)->putCurrent(FpDocumentPath::approved('Budi Santoso', 1001, 'IJAZAH'), 'new-bytes');
    }
}
