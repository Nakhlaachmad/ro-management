<?php

declare(strict_types=1);

namespace App\Application\FP;

use App\Models\FpMembership;

final class RecruitmentPresentation
{
    /** Read snapshots only: never resolve renamed masters or infer legacy mappings. */
    public static function label(?FpMembership $membership): ?string
    {
        if ($membership === null) {
            return null;
        }
        if ((int) $membership->recruitment_contract_version === 2) {
            return $membership->recruitment_team_name_snapshot;
        }
        $path = implode(' — ', array_filter([
            $membership->recruitment_path_code_snapshot,
            $membership->recruitment_path_name_snapshot,
        ], fn ($value) => $value !== null && $value !== ''));
        $type = $membership->source_type?->value;
        $name = $membership->source_name_snapshot;
        $source = implode(' · ', array_unique(array_filter([$type, $name], fn ($value) => $value !== null && $value !== '')));
        $parts = array_values(array_unique(array_filter([$path, $source], fn ($value) => $value !== '')));
        if ($parts !== []) {
            return implode(' / ', $parts);
        }

        return $membership->legacy_source_raw ? $membership->legacy_source_raw.' (legacy / belum direkonsiliasi)' : 'Belum tersedia';
    }
}
