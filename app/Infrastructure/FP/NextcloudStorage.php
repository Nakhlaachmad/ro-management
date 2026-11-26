<?php

declare(strict_types=1);

namespace App\Infrastructure\FP;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use RuntimeException;

final class NextcloudStorage implements DocumentStorage
{
    private const MAX_BYTES = 16 * 1024 * 1024;

    public function __construct(private Factory $http, private string $endpoint, private string $user, private string $password)
    {
        $url = parse_url($endpoint);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['query']) || isset($url['fragment']) || preg_match('/[\r\n]/', $endpoint) || $user === '' || $password === '') {
            throw new RuntimeException('Nextcloud configuration incomplete');
        }
    }

    private function encoded(string $path): string
    {
        return rtrim($this->endpoint, '/').'/'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function url(string $path, bool $friendly = false): string
    {
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
        if (! $friendly && preg_match('~\Afp/'.$uuid.'/'.$uuid.'\.(jpg|png)\z~', $path) !== 1) {
            throw new RuntimeException('Invalid private locator');
        }
        if ($friendly && ! FpDocumentPath::isFriendly($path)) {
            throw new InvalidArgumentException('Invalid FP document locator');
        }

        return $this->encoded($path);
    }

    private function request(string $method, string $url, ?string $bytes = null, array $headers = []): Response
    {
        try {
            $request = $this->http->withBasicAuth($this->user, $this->password)->connectTimeout(5)->timeout(30)->withoutRedirecting()->withOptions(['verify' => true]);
            if ($method === 'GET' || $method === 'HEAD') {
                $request = $request->withOptions(['stream' => true]);
            }
            if ($headers !== []) {
                $request = $request->withHeaders($headers);
            }
            if ($bytes !== null) {
                $request = $request->withBody($bytes, 'application/octet-stream');
            }

            return $request->send($method, $url);
        } catch (\Throwable) {
            // Never propagate request exceptions containing credentials, URL or bytes.
            throw new RuntimeException('Private document storage unavailable');
        }
    }

    private function createDirectories(string $path, bool $friendly): void
    {
        $parts = explode('/', $path);
        $base = rtrim($this->endpoint, '/');
        foreach (array_slice($parts, 0, -1) as $part) {
            $base .= '/'.rawurlencode($part);
            $response = $this->request('MKCOL', $base);
            if (! $response->successful() && $response->status() !== 405) {
                throw new RuntimeException('Storage directory unavailable');
            }
        }
    }

    public function put(string $path, string $bytes): void
    {
        $target = $this->url($path);
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('Invalid private document size');
        }
        $this->createDirectories($path, false);
        if (! $this->request('PUT', $target, $bytes)->successful()) {
            throw new RuntimeException('Storage upload unavailable');
        }
        if (! hash_equals(hash('sha256', $bytes), hash('sha256', $this->readBytes($target)))) {
            throw new RuntimeException('Private document integrity mismatch');
        }
    }

    /** Idempotent publication to a human-readable folder; returns false when the object already exists. */
    public function putOnce(string $friendlyPath, string $bytes): bool
    {
        $target = $this->url($friendlyPath, true);
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('Invalid private document size');
        }
        if ($this->exists($friendlyPath)) {
            return false;
        }
        $this->createDirectories($friendlyPath, true);
        $response = $this->request('PUT', $target, $bytes, ['If-None-Match' => '*']);
        if ($response->status() === 412) {
            return false;
        }
        if (! $response->successful()) {
            throw new RuntimeException('Storage upload unavailable');
        }
        if (! hash_equals(hash('sha256', $bytes), hash('sha256', $this->readBytes($target)))) {
            throw new RuntimeException('Private document integrity mismatch');
        }

        return true;
    }

    /** Replaces an existing readable document only when the current bytes differ. */
    public function putCurrent(string $friendlyPath, string $bytes): bool
    {
        $target = $this->url($friendlyPath, true);
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('Invalid private document size');
        }
        $hash = hash('sha256', $bytes);
        $head = $this->request('HEAD', $target);
        $etag = null;
        if ($head->status() === 200) {
            if (hash_equals($hash, hash('sha256', $this->readBytes($target)))) {
                return false;
            }
            $etag = $head->header('ETag');
        } elseif ($head->status() !== 404) {
            throw new RuntimeException('Storage lookup unavailable');
        }
        $this->createDirectories($friendlyPath, true);
        $headers = $etag === null || $etag === '' ? [] : ['If-Match' => $etag];
        $response = $this->request('PUT', $target, $bytes, $headers);
        if ($response->status() === 412) {
            throw new RuntimeException('Concurrent document update');
        }
        if (! $response->successful() || ! hash_equals($hash, hash('sha256', $this->readBytes($target)))) {
            throw new RuntimeException('Private document integrity mismatch');
        }

        return true;
    }

    /** Stores the real document under the readable FP folder and a small alias at the private locator. */
    public function putAliased(string $privatePath, string $bytes, string $friendlyPath): void
    {
        $target = $this->url($friendlyPath, true);
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('Invalid private document size');
        }
        $this->createDirectories($friendlyPath, true);
        $response = $this->request('PUT', $target, $bytes, ['If-None-Match' => '*']);
        if ($response->status() === 412) {
            throw new InvalidArgumentException('Nama folder FP sudah digunakan');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Storage upload unavailable');
        }
        try {
            if (! hash_equals(hash('sha256', $bytes), hash('sha256', $this->readBytes($target)))) {
                throw new RuntimeException('Private document integrity mismatch');
            }
            $this->put($privatePath, FpDocumentPath::aliasPayload($friendlyPath));
        } catch (\Throwable $error) {
            try {
                $this->request('DELETE', $target);
            } catch (\Throwable) {
                // Keep the original failure; cleanup remains observable through the staged alias.
            }
            throw $error;
        }
    }

    public function exists(string $friendlyPath): bool
    {
        $response = $this->request('HEAD', $this->url($friendlyPath, true));
        if ($response->status() === 404) {
            return false;
        }
        if (! $response->successful()) {
            throw new RuntimeException('Storage lookup unavailable');
        }

        return true;
    }

    private function readBytes(string $url): string
    {
        $response = $this->request('GET', $url);
        if (! $response->successful()) {
            throw new RuntimeException('Document unavailable');
        }
        $stream = $response->toPsrResponse()->getBody();
        try {
            $bytes = '';
            $deadline = hrtime(true) + 30_000_000_000;
            while (! $stream->eof()) {
                if (hrtime(true) >= $deadline || $stream->getMetadata('timed_out')) {
                    throw new RuntimeException('Document unreadable');
                }
                $chunk = $stream->read(min(65536, self::MAX_BYTES + 1 - strlen($bytes)));
                // Network streams may yield an empty chunk before EOF. Wait within
                // the read deadline instead of failing a successful response.
                if ($chunk === '' && ! $stream->eof()) {
                    usleep(10000);

                    continue;
                }
                $bytes .= $chunk;
                if (strlen($bytes) > self::MAX_BYTES) {
                    throw new RuntimeException('Private document size exceeded');
                }
            }

            return $bytes;
        } catch (\Throwable) {
            throw new RuntimeException('Document unavailable');
        } finally {
            $stream->close();
        }
    }

    public function read(string $path): string
    {
        $bytes = $this->readBytes($this->url($path));
        $target = FpDocumentPath::aliasTarget($bytes);

        return $target === null ? $bytes : $this->readBytes($this->url($target, true));
    }

    public function delete(string $path): void
    {
        if (FpDocumentPath::isFriendly($path)) {
            $response = $this->request('DELETE', $this->url($path, true));
            if (! $response->successful() && $response->status() !== 404) {
                throw new RuntimeException('Deletion unavailable');
            }

            return;
        }
        try {
            $target = FpDocumentPath::aliasTarget($this->readBytes($this->url($path)));
            if ($target !== null) {
                $this->request('DELETE', $this->url($target, true));
            }
        } catch (\Throwable) {
            // A failed alias read must not prevent cleanup of the private locator.
        }
        $response = $this->request('DELETE', $this->url($path));
        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException('Deletion unavailable');
        }
    }

    public function adapter(): string
    {
        return 'nextcloud';
    }
}
