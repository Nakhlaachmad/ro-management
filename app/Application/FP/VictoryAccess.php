<?php

declare(strict_types=1);

namespace App\Application\FP;

use App\Models\User;

final class VictoryAccess
{
    public const VIEW = ['f2.view', 'f2.pii', 'f2.documents.view', 'f2.teams.view', 'f3.jalur.view', 'f3.reports.view', 'f3.reports.export', 'f3.users.view'];

    public const MUTATE = ['f2.biodata', 'f2.documents', 'f2.approve', 'f2.reject', 'f2.exit', 'f2.teams', 'f3.jalur.manage', 'f3.users.create', 'f3.users.edit'];

    public static function role(?User $actor): ?string
    {
        if (! $actor || ! $actor->is_active) {
            return null;
        }
        foreach (['admin', 'hrd', 'ro', 'bm'] as $role) {
            if ($actor->hasRole($role, 'web')) {
                return $role;
            }
        }

        return null;
    }

    /** HRD is the operational administrator for FP documents; legacy admin also passes. */
    public static function canManageDocuments(?User $actor): bool
    {
        return in_array(self::role($actor), ['admin', 'hrd'], true);
    }

    public static function allowed(?User $actor, string $action): bool
    {
        if (! FeatureAccess::enabled()) {
            return false;
        }
        $role = self::role($actor);
        if ($role === null) {
            return false;
        }
        if (in_array($action, self::VIEW, true)) {
            return true;
        }
        if ($action === 'f3.users.permissions') {
            return $role === 'admin';
        }

        return in_array($action, self::MUTATE, true) && $role !== 'bm';
    }

    public static function require(User $actor, string $action): void
    {
        if (! self::allowed($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Victory action not authorized');
        }
    }
}
