<?php

declare(strict_types=1);

namespace App\Application\FP;

use Carbon\CarbonImmutable;

final class ReportPeriods
{
    public const LABELS = ['today' => 'Hari Ini', 'last7' => '7 Hari Terakhir', 'last30' => '30 Hari Terakhir', 'custom' => 'Pilih Tanggal', 'month' => 'Pilih Bulan', 'year' => 'Pilih Tahun'];

    public static function timezone(): string
    {
        return config('fp.business_timezone') ?: 'Asia/Jakarta';
    }

    public static function resolve(array $filters, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now(self::timezone()))->setTimezone(self::timezone());
        $range = match ($filters['period'] ?? 'custom') {
            'custom' => null,'today' => [$now, $now],'last7' => [$now->subDays(6), $now],'last30' => [$now->subDays(29), $now],
            'month' => [CarbonImmutable::create((int) $filters['year'], (int) $filters['month'], 1, 0, 0, 0, self::timezone()), CarbonImmutable::create((int) $filters['year'], (int) $filters['month'], 1, 0, 0, 0, self::timezone())->endOfMonth()],
            'year' => [CarbonImmutable::create((int) $filters['year'], 1, 1, 0, 0, 0, self::timezone()), CarbonImmutable::create((int) $filters['year'], 12, 31, 0, 0, 0, self::timezone())],
            default => throw new \InvalidArgumentException('Unknown period'),
        };
        if ($range) {
            $filters['from'] = $range[0]->format('Y-m-d');
            $filters['until'] = $range[1]->format('Y-m-d');
        }

        return $filters;
    }
}
