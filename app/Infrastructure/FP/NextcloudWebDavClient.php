<?php

declare(strict_types=1);

namespace App\Infrastructure\FP;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use RuntimeException;

/** Transport for F3.9. PostgreSQL owns document identity and revision history. */
final class NextcloudWebDavClient
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    private const MAX_BYTES = 16 * 1024 * 1024;

    private string $endpoint;

    public function __construct(
        private Factory $http,
        string $baseUrl,
        #[\SensitiveParameter] private string $username,
        #[\SensitiveParameter] private string $password,
        private string $rootFolder = 'Victory-FP-Management',
        private bool $writesEnabled = true,
    ) {
        $url = parse_url($baseUrl);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || ! in_array($url['path'] ?? '', ['', '/'], true)
            || preg_match('/[\x00-\x20\x7f]/', $baseUrl)
            || $username === '' || preg_match('/[\x00-\x20\x7f\/:\\\\]/', $username)
            || $password === '' || preg_match('/[\r\n]/', $password)
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,99}\z/', $rootFolder)) {
            throw new RuntimeException('NEXTCLOUD_CONFIGURATION_INVALID');
        }
        $this->endpoint = rtrim($baseUrl, '/').'/remote.php/dav/files/'.rawurlencode($username);
    }

    public static function configured(bool $healthCheckOnly = false): self
    {
        if (! $healthCheckOnly && ! config('nextcloud.enabled', false)) {
            throw new RuntimeException('NEXTCLOUD_DISABLED');
        }

        return new self(app(Factory::class), (string) config('nextcloud.base_url'),
            (string) config('nextcloud.username'), (string) config('nextcloud.app_password'),
            (string) config('nextcloud.root_folder'), ! $healthCheckOnly && (bool) config('nextcloud.enabled', false));
    }

    public function revisionPath(string $fp, string $document, string $revision): string
    {
        foreach ([$fp, $document, $revision] as $identifier) {
            if (! preg_match('/\A'.self::UUID.'\z/', $identifier)) {
                throw new RuntimeException('NEXTCLOUD_LOCATOR_INVALID');
            }
        }

        return 'documents/'.$fp.'/'.$document.'/revision-'.$revision.'/document.bin';
    }

    private function validatePath(string $path): void
    {
        $uuid = self::UUID;
        if (! preg_match('~\A(?:documents/'.$uuid.'/'.$uuid.'/revision-'.$uuid.'/document\.bin|staging/'.$uuid.'/document\.bin)\z~', $path)) {
            throw new RuntimeException('NEXTCLOUD_LOCATOR_INVALID');
        }
    }

    private function request(string $method, string $path, #[\SensitiveParameter] ?string $bytes = null, array $headers = []): Response
    {
        $url = $this->endpoint.'/'.implode('/', array_map('rawurlencode', explode('/', $path)));
        try {
            // No automatic retries. A timed-out PUT has an unknown remote outcome.
            $request = $this->http->withBasicAuth($this->username, $this->password)
                ->connectTimeout(5)->timeout(30)->withoutRedirecting()
                ->withOptions(['verify' => true, 'stream' => true, 'read_timeout' => 30])
                ->withHeaders($headers);
            if ($bytes !== null) {
                $request = $request->withBody($bytes, 'application/octet-stream');
            }
            $response = $request->send($method, $url);
        } catch (\Throwable) {
            throw new RuntimeException($method === 'PUT' ? 'NEXTCLOUD_UPLOAD_UNCERTAIN' : 'NEXTCLOUD_UNAVAILABLE');
        }
        if (in_array($response->status(), [401, 403], true)) {
            $response->close();
            throw new RuntimeException('NEXTCLOUD_AUTHENTICATION_FAILED');
        }

        return $response;
    }

    private function body(Response $response, int $limit): string
    {
        $stream = $response->toPsrResponse()->getBody();
        try {
            $bytes = '';
            $deadline = hrtime(true) + 30_000_000_000;
            while (! $stream->eof()) {
                if (hrtime(true) >= $deadline || $stream->getMetadata('timed_out')) {
                    throw new RuntimeException('NEXTCLOUD_RESPONSE_INVALID');
                }
                $chunk = $stream->read(min(65536, $limit + 1 - strlen($bytes)));
                // Chunked network streams may temporarily return no bytes before
                // EOF. Wait within the read deadline, without replaying a request.
                if ($chunk === '' && ! $stream->eof()) {
                    usleep(10000);

                    continue;
                }
                if (strlen($bytes) + strlen($chunk) > $limit) {
                    throw new RuntimeException('NEXTCLOUD_RESPONSE_INVALID');
                }
                $bytes .= $chunk;
            }

            return $bytes;
        } catch (\Throwable) {
            throw new RuntimeException('NEXTCLOUD_RESPONSE_INVALID');
        } finally {
            $stream->close();
        }
    }

    private function collectionExists(string $path): void
    {
        $response = $this->request('PROPFIND', $path, null, ['Depth' => '0']);
        $status = $response->status();
        $xml = $this->body($response, 65536);
        if ($status !== 207 || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('NEXTCLOUD_STORAGE_UNAVAILABLE');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument;
            if (! $document->loadXML($xml, LIBXML_NONET)) {
                throw new RuntimeException('NEXTCLOUD_RESPONSE_INVALID');
            }
            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('d', 'DAV:');
            if ($document->documentElement?->localName !== 'multistatus'
                || $document->documentElement?->namespaceURI !== 'DAV:'
                || $xpath->query('//d:propstat[contains(d:status," 200 ")]/d:prop/d:resourcetype/d:collection')->length < 1) {
                throw new RuntimeException('NEXTCLOUD_STORAGE_UNAVAILABLE');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function health(): string
    {
        $this->collectionExists('');

        return 'CONNECTED';
    }

    public function put(string $path, #[\SensitiveParameter] string $bytes): void
    {
        if (! $this->writesEnabled) {
            throw new RuntimeException('NEXTCLOUD_DISABLED');
        }
        $this->validatePath($path);
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('NEXTCLOUD_DOCUMENT_SIZE_INVALID');
        }
        $directory = '';
        foreach (explode('/', $this->rootFolder.'/'.dirname($path)) as $part) {
            $directory = ltrim($directory.'/'.$part, '/');
            $response = $this->request('MKCOL', $directory);
            $status = $response->status();
            $response->close();
            if ($status === 405) {
                $this->collectionExists($directory);
            } elseif ($status !== 201) {
                throw new RuntimeException('NEXTCLOUD_STORAGE_UNAVAILABLE');
            }
        }
        $response = $this->request('PUT', $this->rootFolder.'/'.$path, $bytes, ['If-None-Match' => '*']);
        $status = $response->status();
        $response->close();
        if (! in_array($status, [201, 204, 412], true)) {
            throw new RuntimeException($status >= 500 ? 'NEXTCLOUD_UPLOAD_UNCERTAIN' : 'NEXTCLOUD_UPLOAD_FAILED');
        }
        // 412 may be an idempotent replay, but only identical verified bytes qualify.
        $remote = $this->read($path);
        if (strlen($bytes) !== strlen($remote) || ! hash_equals(hash('sha256', $bytes), hash('sha256', $remote))) {
            throw new RuntimeException('NEXTCLOUD_INTEGRITY_WARNING');
        }
    }

    public function read(string $path): string
    {
        $this->validatePath($path);
        $response = $this->request('GET', $this->rootFolder.'/'.$path);
        $status = $response->status();
        if ($status !== 200) {
            $response->close();
            throw new RuntimeException($status === 404 ? 'NEXTCLOUD_OBJECT_MISSING' : 'NEXTCLOUD_STORAGE_UNAVAILABLE');
        }

        return $this->body($response, self::MAX_BYTES);
    }

    // Deliberately no DELETE API until persisted operation ownership and rollback
    // evidence can authorize cleanup. Historical revision objects are immutable.
}
