<?php

declare(strict_types=1);

namespace App\Filament\Pages\FP;

use App\Application\FP\FeatureAccess;

final class UserStatus extends \Filament\Pages\Page
{
    protected static ?string $slug = 'users';

    protected static bool $isDiscovered = false;

    protected static ?string $title = 'User';

    protected static string $view = 'filament.fp.user-status';

    public static function canAccess(): bool
    {
        return FeatureAccess::allowed(auth()->user(), 'f2.view');
    }
}
