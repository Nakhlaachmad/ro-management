<?php

declare(strict_types=1);

namespace App\Domain\FP;

final class AnnualNip
{
    public static function year(\DateTimeInterface $approval): int
    {
        return (int) \DateTimeImmutable::createFromInterface($approval)
            ->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y');
    }

    public static function number(int $year, int $serial): int
    {
        if ($serial < 1001 || $serial > 9999) {
            throw new \InvalidArgumentException('Nomor urut NIP tahunan harus 1001–9999; penerbitan tidak dapat dilanjutkan.');
        }
        $number = ($year % 100) * 10000 + $serial;
        if ($year < 2010 || $year > 9999 || $number < 100000 || $number > 999999) {
            throw new \InvalidArgumentException('Format NIP enam digit tidak dapat direpresentasikan aman dengan NIP historis numerik pada tahun ini.');
        }

        return $number;
    }
}
