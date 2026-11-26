<?php

declare(strict_types=1);

namespace App\Application\FP;

use App\Models\FpMembership;
use App\Models\FpSubmission;
use App\Models\FpTeam;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class FpDirectory
{
    public const SCOPES = ['all', 'active', 'normal', 'abnormal'];

    public static function members(string $scope = 'all'): Builder
    {
        return self::scope(FpMembership::query(), $scope);
    }

    public static function scope(Builder $query, string $scope): Builder
    {
        return match ($scope) {
            'all' => $query,
            'exited' => $query->where('lifecycle_state', 'EXITED'),
            'active' => $query->where('lifecycle_state', 'ACTIVE'),
            'normal', 'abnormal' => $query->where('lifecycle_state', 'EXITED')->whereHas('exit', fn (Builder $exit) => $exit->where('type', strtoupper($scope))),
            default => throw new InvalidArgumentException('Unknown FP directory'),
        };
    }

    public static function counts(): array
    {
        $counts = [];
        foreach (self::SCOPES as $scope) {
            $counts[$scope] = self::members($scope)->count();
        }
        $counts['unresolved'] = $counts['all'] - $counts['active'] - $counts['normal'] - $counts['abnormal'];
        $counts['pending'] = FpSubmission::where('status', 'PENDING')->count();
        $counts['teams'] = FpTeam::where('is_active', true)->count();

        return $counts;
    }
}
