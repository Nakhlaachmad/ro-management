<?php

declare(strict_types=1);

namespace App\Infrastructure\FP;

interface DocumentStorage
{
    public function put(string $path, string $bytes): void;

    public function read(string $path): string;

    public function delete(string $path): void;

    public function adapter(): string;
}
