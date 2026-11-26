<?php

declare(strict_types=1);

namespace App\Application\FP;

use Illuminate\Support\Facades\Validator;

final class ReportFilters
{
    public const STATUSES = ['all' => 'Semua FP', 'active' => 'Aktif', 'normal' => 'Keluar Normal', 'abnormal' => 'Keluar Tidak Normal', 'unresolved' => 'Belum terklasifikasi'];

    public static function validate(array $input): array
    {
        // Removed report filters and stale hidden period inputs never constrain the query.
        unset($input['name'], $input['nip']);
        $input['date_field'] ??= 'reference_date';
        $period = $input['period'] ?? 'custom';
        if ($period !== 'custom') {
            unset($input['from'], $input['until']);
        }
        if ($period !== 'month') {
            unset($input['month']);
        }
        if (! in_array($period, ['month', 'year'], true)) {
            unset($input['year']);
        }
        $rules = ['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d'], 'date_field' => ['required', 'in:reference_date'], 'status' => ['required', 'in:'.implode(',', array_keys(self::STATUSES))], 'team_id' => ['nullable', 'integer', 'min:1'], 'path_id' => ['nullable', 'integer', 'min:1'], 'recruitment_team_id' => ['nullable', 'integer', 'min:1'], 'period' => ['nullable', 'in:custom,today,last7,last30,month,year'], 'year' => ['nullable', 'required_if:period,month,year', 'integer', 'between:1900,9999'], 'month' => ['nullable', 'required_if:period,month', 'integer', 'between:1,12']];
        if (($input['period'] ?? 'custom') === 'custom' && ! empty($input['from'])) {
            $rules['until'][] = 'after_or_equal:from';
        }

        return ReportPeriods::resolve(Validator::make($input, $rules)->validate());
    }
}
