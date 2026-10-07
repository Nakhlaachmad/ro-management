<?php

namespace App\Filament\Widgets;

use App\Models\Fp;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Carbon\Carbon;

class FpperiodeStats extends BaseWidget
{
    use InteractsWithPageFilters;

    protected function getStats(): array
    {
        $startDate = $this->filters['startDate'] ?? now()->startOfMonth();
        $endDate   = $this->filters['endDate'] ?? now()->endOfMonth();
        $fpMasuk = Fp::whereBetween('tanggal_masuk', [$startDate, $endDate])->count();
        $keluarData = Fp::whereNotNull('tanggal_keluar')
            ->whereBetween('tanggal_keluar', [$startDate, $endDate])
            ->get();

        $keluarNormal = 0;
        $keluarTidakNormal = 0;

        foreach ($keluarData as $fp) {
            $masaKerja = Carbon::parse($fp->tanggal_masuk)
                ->diffInMonths(Carbon::parse($fp->tanggal_keluar), true);

            if ($masaKerja < 2) {
                $keluarTidakNormal++;
            } else {
                $keluarNormal++;
            }
        }

        return [
            Stat::make('FP Masuk', $fpMasuk)
                ->description('masuk')
                ->icon('heroicon-s-arrow-down-circle')
                ->color('success'),
            
            Stat::make('Keluar Normal', $keluarNormal)
                ->description('≥ 2 bulan')
                ->icon('heroicon-s-arrow-right-circle')
                ->color('danger'),

            Stat::make('Keluar tidak Normal', $keluarTidakNormal)
                ->description('< 2 bulan')
                ->icon('heroicon-s-exclamation-circle')
                ->color('primary'),

        ];
    }
}