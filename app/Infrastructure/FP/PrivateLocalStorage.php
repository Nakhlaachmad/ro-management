<?php

declare(strict_types=1);

namespace App\Infrastructure\FP;

use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;

final class PrivateLocalStorage implements DocumentStorage
{
    public function __construct(private FilesystemAdapter $disk) {}

    public function put(string $path, string $bytes): void
    {
        if (! $this->disk->put($path, $bytes, ['visibility' => 'private'])) {
            throw new RuntimeException('Private storage unavailable');
        }
    }

    public function read(string $path): string
    {
        $bytes = $this->disk->get($path);
        if (! is_string($bytes)) {
            throw new RuntimeException('Document unavailable');
        }

return $bytes;
    }

    public function delete(string $path): void
    {
        if ($this->disk->exists($path) && ! $this->disk->delete($path)) {
            throw new RuntimeException('Deletion unavailable');
        }
    }

    public function adapter(): string
    {
        return 'local';
    }
}
