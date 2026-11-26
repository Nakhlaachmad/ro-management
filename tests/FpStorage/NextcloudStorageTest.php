<?php

declare(strict_types=1);

namespace Tests\FpStorage;

use App\Infrastructure\FP\DocumentStorageRegistry;
use App\Infrastructure\FP\NextcloudStorage;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NextcloudStorageTest extends TestCase
{
    private const PATH = 'fp/00000000-0000-4000-8000-000000000001/00000000-0000-4000-8000-000000000002.jpg';

    private function http(): Factory
    {
        $http = new Factory;
        $http->preventStrayRequests();

        return $http;
    }

    private function store(Factory $http): NextcloudStorage
    {
        return new NextcloudStorage($http, 'https://storage.invalid/dav', 'synthetic-user', 'synthetic-password');
    }

    public function test_upload_is_read_back_verified_and_existing_directories_are_idempotent(): void
    {
        $http = $this->http();
        $http->fake(fn ($r) => Factory::response($r->method() === 'GET' ? 'safe bytes' : '', $r->method() === 'MKCOL' ? 405 : 200));
        $this->store($http)->put(self::PATH, 'safe bytes');
        $http->assertSentCount(4);
        $http->assertSent(fn ($r) => $r->method() === 'PUT' && $r->body() === 'safe bytes');
    }

    public function test_remote_corruption_is_not_accepted(): void
    {
        $http = $this->http();
        $http->fake(fn ($r) => Factory::response($r->method() === 'GET' ? 'corrupt' : '', 200));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Private document integrity mismatch');
        $this->store($http)->put(self::PATH, 'safe bytes');
    }

    public function test_delete_404_is_successful(): void
    {
        $http = $this->http();
        $http->fake(['*' => Factory::response('', 404)]);
        $this->store($http)->delete(self::PATH);
        $http->assertSentCount(2);
        $http->assertSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_empty_network_chunks_before_eof_do_not_fail_a_successful_read(): void
    {
        $http = $this->http();
        $body = \GuzzleHttp\Psr7\Utils::streamFor('safe bytes');
        $emptyReads = 2;
        $stream = \GuzzleHttp\Psr7\FnStream::decorate($body, [
            'read' => function ($length) use ($body, &$emptyReads) {
                if ($emptyReads > 0) {
                    $emptyReads--;

                    return '';
                }

                return $body->read($length);
            },
        ]);
        $http->fake(['*' => Factory::response($stream, 200)]);

        self::assertSame('safe bytes', $this->store($http)->read(self::PATH));
        $http->assertSentCount(1);
    }

    public function test_redirect_is_rejected_and_redirects_tls_and_timeouts_are_bounded(): void
    {
        $http = $this->http();
        $http->fake(function ($r, $options) {
            self::assertFalse($options['allow_redirects']);
            self::assertTrue($options['verify']);
            self::assertSame(5, $options['connect_timeout']);
            self::assertSame(30, $options['timeout']);

            return Factory::response('', 302, ['Location' => 'https://external.invalid']);
        });
        $this->expectException(RuntimeException::class);
        $this->store($http)->read(self::PATH);
    }

    public function test_invalid_endpoint_and_locator_never_send_requests(): void
    {
        $http = $this->http();
        foreach (['http://storage.invalid/dav', 'https://user:pass@storage.invalid/dav', 'https://storage.invalid/dav?token=x', 'https://storage.invalid/dav#x'] as $endpoint) {
            try {
                new NextcloudStorage($http, $endpoint, 'fake', 'fake');
                self::fail('Unsafe endpoint accepted');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
        foreach (['../secret', 'fp/'.str_repeat('-', 36).'/'.str_repeat('-', 36).'.jpg', self::PATH.'?x'] as $path) {
            try {
                $this->store($http)->delete($path);
                self::fail('Unsafe locator accepted');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }$http->assertNothingSent();
    }

    public function test_network_exception_is_sanitized_without_previous_exception(): void
    {
        $http = $this->http();
        $http->fake(fn () => throw new RuntimeException('sensitive upstream payload'));
        try {
            $this->store($http)->read(self::PATH);
            self::fail('Failure swallowed');
        } catch (RuntimeException $e) {
            self::assertSame('Private document storage unavailable', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function test_oversized_reads_are_rejected(): void
    {
        $http = $this->http();
        $http->fake(['*' => Factory::response(str_repeat('x', 16 * 1024 * 1024 + 1), 200)]);
        $this->expectException(RuntimeException::class);
        $this->store($http)->read(self::PATH);
    }

    public function test_local_metadata_keeps_existing_adapter_and_unknown_adapter_fails_closed(): void
    {
        $local = new class implements \App\Infrastructure\FP\DocumentStorage
        {
            public function put(string $p, string $b): void {}

            public function read(string $p): string
            {
                return 'local';
            }

            public function delete(string $p): void {}

            public function adapter(): string
            {
                return 'local';
            }
        };
        $registry = new DocumentStorageRegistry;
        self::assertSame($local, $registry->forAdapter('local', $local));
        $this->expectException(RuntimeException::class);
        $registry->forAdapter('public', $local);
    }

    public function test_nextcloud_metadata_resolves_remote_without_changing_local_default(): void
    {
        $http = $this->http();
        $http->fake(['*' => Factory::response('remote bytes', 200)]);
        $c = new \Illuminate\Container\Container;
        $c->instance('config', new \Illuminate\Config\Repository(['fp' => ['nextcloud' => ['endpoint' => 'https://storage.invalid/dav', 'user' => 'fake', 'password' => 'fake']]]));
        $c->instance(Factory::class, $http);
        \Illuminate\Container\Container::setInstance($c);
        $local = new class implements \App\Infrastructure\FP\DocumentStorage
        {
            public function put(string $p, string $b): void {}

            public function read(string $p): string
            {
                return 'local';
            }

            public function delete(string $p): void {}

            public function adapter(): string
            {
                return 'local';
            }
        };
        $registry = new DocumentStorageRegistry;
        $remote = $registry->forAdapter('nextcloud', $local);
        self::assertSame('remote bytes', $remote->read(self::PATH));
        self::assertSame($local, $registry->forAdapter('local', $local));
        $http->assertSentCount(1);
    }
}
