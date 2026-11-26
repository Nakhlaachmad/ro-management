<?php

declare(strict_types=1);

namespace App\Filament\FP;

final class NipSearch
{
    public static function apply(\Illuminate\Database\Eloquent\Builder $query, string $search): \Illuminate\Database\Eloquent\Builder
    {
        $number = ltrim(trim($search), '0');
        $number = $number === '' ? '0' : $number;
        if (! preg_match('/\A[0-9]{1,19}\z/', $number)
            || (strlen($number) === 19 && strcmp($number, '9223372036854775807') > 0)) {
            return $query->whereRaw('1 = 0');
        }

        // Exact numeric lookup retains the existing UNIQUE NIP index.
        return $query->where('financial_planners.nip', $number);
    }
}
