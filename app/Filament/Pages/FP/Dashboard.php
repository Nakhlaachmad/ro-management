<?php

declare(strict_types=1);

namespace App\Filament\Pages\FP;

use App\Application\FP\FeatureAccess;
use App\Application\FP\FpDirectory;

final class Dashboard extends \Filament\Pages\Dashboard
{
    protected static bool $isDiscovered = false;

    protected static string $view = 'filament.fp.dashboard';

    public static function canAccess(): bool
    {
        return FeatureAccess::allowed(auth()->user(), 'f2.view');
    }

    public function analytics(): array
    {
        abort_unless(self::canAccess(), 403);

        return \App\Application\FP\FpDashboardAnalytics::snapshot();
    }

    public function summary(): array
    {
        abort_unless(self::canAccess(), 403);

        return FpDirectory::counts();
    }
}
