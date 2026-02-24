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

        // ======================
        // FP Masuk
        // ======================
        $fpMasuk = Fp::whereBetween('tanggal_masuk', [$startDate, $endDate])->count();

        // ======================
        // FP Keluar (ambil sekali)
        // ======================
        $keluarData = Fp::whereNotNull('tanggal_keluar')
            ->whereBetween('tanggal_keluar', [$startDate, $endDate])
            ->get();

        $keluarNormal = 0;
        $keluarTidakNormal = 0;

        foreach ($keluarData as $fp) {
            $masaKerja = Carbon::parse($fp->tanggal_masuk)
                ->diffInMonths(Carbon::parse($fp->tanggal_keluar));

            if ($masaKerja < 3) {
                $keluarTidakNormal++;
            } else {
                $keluarNormal++;
            }
        }

        return [
            Stat::make('FP Aktif', $fpMasuk)
                ->description('aktif')
                ->icon('heroicon-o-user-plus')
                ->color('success'),
            
            Stat::make('Keluar Normal', $keluarNormal)
                ->description('≥ 3 bulan')
                ->icon('heroicon-o-x-circle')
                ->color('danger'),

            Stat::make('Keluar tidak Normal', $keluarTidakNormal)
                ->description('< 3 bulan')
                ->icon('heroicon-o-check-circle')
                ->color('primary'),

        ];
    }
}