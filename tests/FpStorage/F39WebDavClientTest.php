<?php

declare(strict_types=1);

namespace Tests\FpStorage;

use App\Infrastructure\FP\NextcloudWebDavClient;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class F39WebDavClientTest extends TestCase
{
    private const ID = '00000000-0000-4000-8000-000000000001';

    private const COLLECTION = '<d:multistatus xmlns:d="DAV:"><d:response><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';

    private function client(Factory $http): NextcloudWebDavClient
    {
        $http->preventStrayRequests();

        return new NextcloudWebDavClient($http, 'https://storage.invalid', 'service-test', 'synthetic-password');
    }

    public function test_health_is_authenticated_propfind_with_tls_no_redirect_and_bounded_timeouts(): void
    {
        $http = new Factory;
        $http->fake(function ($request, $options) {
            self::assertSame('PROPFIND', $request->method());
            self::assertTrue($request->hasHeader('Depth', '0'));
            self::assertTrue($request->hasHeader('Authorization'));
            self::assertTrue($options['verify']);
            self::assertFalse($options['allow_redirects']);
            self::assertSame(5, $options['connect_timeout']);
            self::assertSame(30, $options['timeout']);

            return Factory::response(self::COLLECTION, 207);
        });
        self::assertSame('CONNECTED', $this->client($http)->health());
    }

    public function test_upload_uses_opaque_revision_path_and_never_overwrites_an_existing_object(): void
    {
        $http = new Factory;
        $http->fake(function ($request) {
            return match ($request->method()) {
                'MKCOL' => Factory::response('', 201),
                'PUT' => Factory::response('', 201),
                'GET' => Factory::response('synthetic-document', 200),
            };
        });
        $client = $this->client($http);
        $path = $client->revisionPath(self::ID, self::ID, self::ID);
        $client->put($path, 'synthetic-document');
        $http->assertSent(fn ($r) => $r->method() === 'PUT' && $r->hasHeader('If-None-Match', '*'));
        $http->assertNotSent(fn ($r) => $r->method() === 'DELETE');
        self::assertSame('synthetic-document', $client->read($path));
    }

    public function test_replay_requires_identical_content_and_checks_existing_collections(): void
    {
        $http = new Factory;
        $http->fake(fn ($r) => match ($r->method()) {
            'MKCOL' => Factory::response('', 405),
            'PROPFIND' => Factory::response(self::COLLECTION, 207),
            'PUT' => Factory::response('', 412),
            'GET' => Factory::response('same', 200),
        });
        $client = $this->client($http);
        $client->put($client->revisionPath(self::ID, self::ID, self::ID), 'same');
        $this->expectExceptionMessage('NEXTCLOUD_INTEGRITY_WARNING');
        $client->put($client->revisionPath(self::ID, self::ID, self::ID), 'different');
    }

    public function test_timed_out_upload_is_uncertain_has_no_retry_or_delete_and_no_sensitive_exception(): void
    {
        $http = new Factory;
        $http->fake(fn ($r) => $r->method() === 'MKCOL' ? Factory::response('', 201) : throw new RuntimeException('secret upstream payload'));
        $client = $this->client($http);
        try {
            $client->put($client->revisionPath(self::ID, self::ID, self::ID), 'synthetic');
            self::fail('Timeout accepted');
        } catch (RuntimeException $error) {
            self::assertSame('NEXTCLOUD_UPLOAD_UNCERTAIN', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        // The factory records successful exchanges. The failing PUT is attempted once.
        $http->assertSentCount(5);
        $http->assertNotSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_authentication_error_and_missing_object_are_sanitized(): void
    {
        foreach ([401 => 'NEXTCLOUD_AUTHENTICATION_FAILED', 403 => 'NEXTCLOUD_AUTHENTICATION_FAILED', 404 => 'NEXTCLOUD_OBJECT_MISSING', 503 => 'NEXTCLOUD_STORAGE_UNAVAILABLE', 302 => 'NEXTCLOUD_STORAGE_UNAVAILABLE'] as $status => $message) {
            $http = new Factory;
            $http->fake(['*' => Factory::response('upstream-sensitive-body', $status)]);
            try {
                $this->client($http)->read('staging/'.self::ID.'/document.bin');
                self::fail('Bad status accepted');
            } catch (RuntimeException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }

    public function test_xml_entities_and_non_collection_propfind_are_rejected(): void
    {
        foreach (['<!DOCTYPE d [<!ENTITY x SYSTEM "file:///secret">]><d/>', '<d:multistatus xmlns:d="DAV:"/>', '<html>login</html>'] as $xml) {
            $http = new Factory;
            $http->fake(['*' => Factory::response($xml, 207)]);
            try {
                $this->client($http)->health();
                self::fail('Invalid PROPFIND accepted');
            } catch (RuntimeException $error) {
                self::assertContains($error->getMessage(), ['NEXTCLOUD_STORAGE_UNAVAILABLE', 'NEXTCLOUD_RESPONSE_INVALID']);
            }
        }
    }

    public function test_untrusted_paths_and_http_are_rejected_before_any_network_request(): void
    {
        $http = new Factory;
        $client = $this->client($http);
        foreach (['../secret', 'staging/'.self::ID.'/../../secret', 'documents/person-name/file', 'staging/'.self::ID.'/document.bin?x'] as $path) {
            try {
                $client->read($path);
                self::fail('Invalid path accepted');
            } catch (RuntimeException $error) {
                self::assertSame('NEXTCLOUD_LOCATOR_INVALID', $error->getMessage());
            }
        }
        $http->assertNothingSent();
        $this->expectExceptionMessage('NEXTCLOUD_CONFIGURATION_INVALID');
        new NextcloudWebDavClient($http, 'http://storage.invalid', 'test', 'test');
    }

    public function test_oversized_response_is_rejected(): void
    {
        $http = new Factory;
        $http->fake(['*' => Factory::response(str_repeat('x', 16 * 1024 * 1024 + 1), 200)]);
        $this->expectExceptionMessage('NEXTCLOUD_RESPONSE_INVALID');
        $this->client($http)->read('staging/'.self::ID.'/document.bin');
    }

    public function test_health_only_client_cannot_create_remote_objects(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $client = new NextcloudWebDavClient($http, 'https://storage.invalid', 'test', 'test', writesEnabled: false);
        try {
            $client->put('staging/'.self::ID.'/document.bin', 'synthetic');
            self::fail('Health-only client uploaded');
        } catch (RuntimeException $error) {
            self::assertSame('NEXTCLOUD_DISABLED', $error->getMessage());
        }
        $http->assertNothingSent();
    }

    public function test_empty_network_chunks_before_eof_do_not_replay_the_request(): void
    {
        $http = new Factory;
        $body = \GuzzleHttp\Psr7\Utils::streamFor(self::COLLECTION);
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
        $http->fake(['*' => Factory::response($stream, 207)]);
        self::assertSame('CONNECTED', $this->client($http)->health());
        $http->assertSentCount(1);
    }

    public function test_read_timeout_is_sanitized_without_retry(): void
    {
        $http = new Factory;
        $body = \GuzzleHttp\Psr7\Utils::streamFor('');
        $stream = \GuzzleHttp\Psr7\FnStream::decorate($body, [
            'eof' => fn () => false,
            'getMetadata' => fn ($key = null) => $key === 'timed_out' ? true : [],
        ]);
        $http->fake(['*' => Factory::response($stream, 207)]);
        try {
            $this->client($http)->health();
            self::fail('Timed out stream accepted');
        } catch (RuntimeException $error) {
            self::assertSame('NEXTCLOUD_RESPONSE_INVALID', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        $http->assertSentCount(1);
    }
}
