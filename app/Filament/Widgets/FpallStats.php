<?php

namespace App\Filament\Widgets;

use App\Models\Fp;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Carbon\Carbon;

class FpallStats extends BaseWidget
{
    protected static bool $isDiscovered = false;
    protected function getStats(): array
    {
        $totalAktif = Fp::where('status', 'aktif')->count();
        $keluarData = Fp::whereNotNull('tanggal_keluar')->get();
        $keluarNormal = 0;
        $keluarTidakNormal = 0;

        foreach ($keluarData as $fp) {
            $masaKerja = Carbon::parse($fp->tanggal_masuk)
                ->diffInMonths(Carbon::parse($fp->tanggal_keluar));

            if ($masaKerja < 2) {
                // < 3 bulan
                $keluarTidakNormal++;
            } else {
                // ≥ 3 bulan
                $keluarNormal++;
            }
        }

        return [
            Stat::make('Total FP Aktif', $totalAktif)
                ->description('aktif')
                ->icon('heroicon-s-check-circle')
                ->color('success'),

            Stat::make('Total Keluar Normal', $keluarNormal)
                ->description('≥ 2 bulan')
                ->icon('heroicon-s-arrow-right-circle')
                ->color('danger'),

            Stat::make('Total Keluar Tidak Normal', $keluarTidakNormal)
                ->description('< 2 bulan')
                ->icon('heroicon-s-exclamation-circle')
                ->color('primary'),
        ];
    }
}