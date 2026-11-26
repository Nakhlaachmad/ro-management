<?php

declare(strict_types=1);

namespace App\Domain\FP;

use DateTimeImmutable;
use InvalidArgumentException;

final class ExitRule
{
    public static function classify(string $reference, string $exit): array
    {
        $parse = static function (string $value): DateTimeImmutable {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (! $date || $date->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException('Invalid calendar date');
            }

return $date;
        };
        $start = $parse($reference);
        $end = $parse($exit);
        if ($end < $start) {
            throw new InvalidArgumentException('Exit precedes reference');
        }
        $days = (int) $start->diff($end)->days;

        return ['duration_days' => $days, 'type' => $days >= 60 ? 'NORMAL' : 'ABNORMAL'];
    }
}
