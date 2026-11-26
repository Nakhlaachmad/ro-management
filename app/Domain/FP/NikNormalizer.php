<?php

declare(strict_types=1);

namespace App\Domain\FP;

use InvalidArgumentException;

final class NikNormalizer
{
    public static function normalize(string $input): string
    {
        $nik = trim($input, " \t\r\n");
        if (preg_match('/\A[0-9]{16}\z/', $nik) !== 1) {
            throw new InvalidArgumentException('NIK must contain exactly 16 ASCII digits.');
        }

        return $nik;
    }

    public static function lookup(string $input, string $key): string
    {
        if ($key === '') {
            throw new InvalidArgumentException('NIK lookup key is not configured.');
        }

        return hash_hmac('sha256', self::normalize($input), $key);
    }
}
