<?php

declare(strict_types=1);

namespace App\Policies;

use App\Application\FP\FeatureAccess;
use App\Models\User;

final class FpPolicy
{
    public function viewAny(User $user): bool
    {
        return FeatureAccess::allowed($user, 'f2.view');
    }

    public function view(User $user, object $record): bool
    {
        return $this->viewAny($user);
    }

    public function preview(User $user, object $record): bool
    {
        return FeatureAccess::allowed($user, 'f2.documents.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, object $record): bool
    {
        return false;
    }

    public function delete(User $user, object $record): bool
    {
        return false;
    }
}
