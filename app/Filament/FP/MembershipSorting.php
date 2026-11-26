<?php

declare(strict_types=1);

namespace App\Filament\FP;

use Illuminate\Database\Eloquent\Builder;

final class MembershipSorting
{
    public static function recruitment(Builder $query, string $direction): Builder
    {
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Invalid sort direction');
        }
        if (config('fp.recruitment_team_enabled')) {
            $query->orderByRaw("CASE WHEN recruitment_contract_version=2 THEN recruitment_team_name_snapshot ELSE COALESCE(recruitment_path_name_snapshot,source_name_snapshot,legacy_source_raw) END ".$direction);
            return $query->orderBy('financial_planners.id', $direction);
        }
        foreach (['recruitment_path_code_snapshot', 'recruitment_path_name_snapshot', 'source_type', 'source_name_snapshot'] as $column) {
            $query->orderBy($column, $direction);
        }

        return $query->orderBy('financial_planners.id', $direction);
    }
}
