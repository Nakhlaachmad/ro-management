<?php

declare(strict_types=1);

namespace App\Application\FP;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class FeatureAccess
{
    public const PERMISSIONS = ['f2.view', 'f2.pii', 'f2.biodata', 'f2.documents', 'f2.approve', 'f2.reject', 'f2.exit', 'f2.teams', 'f3.jalur.view', 'f3.jalur.manage', 'f3.reports.view', 'f3.reports.export'];

    public static function enabled(): bool
    {
        return config('fp.enabled') === true && config('fp.core_schema_only') === false;
    }

    public static function allowed(?User $user, string $permission): bool
    {
        return VictoryAccess::allowed($user, $permission);
    }

    public static function require(User $user, string $permission): void
    {
        if (! self::allowed($user, $permission)) {
            throw new AuthorizationException('FP action not authorized');
        }
    }

    public static function requireEnabled(): void
    {
        if (! self::enabled()) {
            throw new AuthorizationException('FP feature disabled');
        }
    }
}
