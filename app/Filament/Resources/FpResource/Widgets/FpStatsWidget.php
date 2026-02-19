<?php

namespace App\Filament\Resources\FpResource\Widgets;


use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Fp;

class FpStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total FP Aktif', Fp::where('status', 'aktif')->count()),
            Stat::make('Total FP Keluar', Fp::where('status', 'nonaktif')->count()),
        ];
    }
}
