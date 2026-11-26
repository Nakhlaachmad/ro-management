<?php

declare(strict_types=1);

namespace App\Application\FP;

final class RecruitmentCsvColumns
{
    /** Resolve column aliases without converting master codes into typed sources. */
    public static function resolve(array $row): ?string
    {
        $values = [];
        foreach ($row as $header => $value) {
            $header = strtolower(trim(str_replace('_', ' ', ltrim((string) $header, "\xEF\xBB\xBF"))));
            if (in_array($header, ['jalur rekrutmen', 'sumber rekrutmen', 'jalur'], true)) {
                $values[] = trim((string) $value);
            }
        }
        $values = array_values(array_unique($values));
        if (count($values) > 1) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'sumber_rekrutmen' => 'Kolom Jalur Rekrutmen dan Sumber Rekrutmen memiliki nilai berbeda. Tinjau konflik sebelum impor.',
            ]);
        }

        return ($values[0] ?? '') === '' ? null : $values[0];
    }
}
