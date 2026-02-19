<?php

namespace App\Filament\Widgets;

use App\Models\Fp;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FpKeluarTidakNormalStats extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        return [
            Stat::make(
                'Total FP Keluar Tidak Normal',
                Fp::whereNotNull('tanggal_keluar')
                    ->whereNotNull('tanggal_masuk')
                    ->whereRaw('tanggal_keluar < DATE_ADD(tanggal_masuk, INTERVAL 3 MONTH)')
                    ->count()
                ),
        ];
    }
}
