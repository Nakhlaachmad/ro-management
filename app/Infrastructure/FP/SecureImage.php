<?php

declare(strict_types=1);

namespace App\Infrastructure\FP;

use InvalidArgumentException;
use RuntimeException;

final class SecureImage
{
    public function sanitize(string $bytes): array
    {
        if (strlen($bytes) < 20 || strlen($bytes) > 8 * 1024 * 1024) {
            throw new InvalidArgumentException('Invalid document size');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $jpeg = str_starts_with($bytes, "\xff\xd8\xff");
        $png = str_starts_with($bytes, "\x89PNG\r\n\x1a\n");
        if (! ($jpeg && $mime === 'image/jpeg') && ! ($png && $mime === 'image/png')) {
            throw new InvalidArgumentException('Only JPEG/PNG images supported');
        }
        $size = @getimagesizefromstring($bytes);
        if (! $size || min($size[0], $size[1]) < 240 || $size[0] * $size[1] > 16000000) {
            throw new InvalidArgumentException('Invalid image resolution');
        }
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('GD image processing required');
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            throw new InvalidArgumentException('Image decode failed');
        }
        ob_start();
        try {
            $ok = imagejpeg($image, null, 88);
            $safe = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        if (! $ok || ! is_string($safe)) {
            throw new RuntimeException('Image encoding failed');
        }

        return ['bytes' => $safe, 'mime' => 'image/jpeg', 'extension' => 'jpg', 'sha256' => hash('sha256', $safe), 'size' => strlen($safe)];
    }
}
