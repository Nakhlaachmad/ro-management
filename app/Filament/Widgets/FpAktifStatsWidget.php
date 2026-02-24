<?php

namespace App\Filament\Widgets;

use App\Models\Fp;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FpAktifStatsWidget extends BaseWidget
{
    // Agar widget memenuhi lebar layar Dashboard
    protected int|string|array $columnSpan = 'full';
    protected static bool $isDiscovered = false;
    protected function getStats(): array
    {
        return [
            Stat::make('FP Aktif', Fp::where('status','aktif')->count())
                ->color('success')
                ->icon('heroicon-o-check-circle'),
        ];
    }
}