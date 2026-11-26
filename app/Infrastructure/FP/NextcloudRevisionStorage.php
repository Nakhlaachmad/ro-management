<?php

declare(strict_types=1);

namespace App\Infrastructure\FP;

/** Explicit opt-in adapter. Never registered as the active application provider. */
final class NextcloudRevisionStorage implements DocumentStorage
{
    public function __construct(private NextcloudWebDavClient $client) {}

    public function adapter(): string
    {
        return 'nextcloud';
    }

    public function put(string $path, #[\SensitiveParameter] string $bytes): void
    {
        $this->client->put($path, $bytes);
    }

    public function read(string $path): string
    {
        return $this->client->read($path);
    }

    public function delete(string $path): void
    {
        throw new \RuntimeException('Verified operation staging cleanup required');
    }
}
