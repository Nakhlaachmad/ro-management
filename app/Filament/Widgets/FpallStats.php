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
        // ======================
        // Total FP Masuk (semua)
        // ======================
        $totalMasuk = Fp::count();

        // ======================
        // Ambil semua yang sudah keluar
        // ======================
        $keluarData = Fp::whereNotNull('tanggal_keluar')->get();

        $keluarNormal = 0;
        $keluarTidakNormal = 0;

        foreach ($keluarData as $fp) {
            $masaKerja = Carbon::parse($fp->tanggal_masuk)
                ->diffInMonths(Carbon::parse($fp->tanggal_keluar));

            if ($masaKerja < 3) {
                // < 3 bulan
                $keluarTidakNormal++;
            } else {
                // ≥ 3 bulan
                $keluarNormal++;
            }
        }

        return [
            Stat::make('Total FP Masuk', $totalMasuk)
                ->description('aktif')
                ->icon('heroicon-o-user-plus')
                ->color('success'),

            Stat::make('Total Keluar Normal', $keluarNormal)
                ->description('≥ 3 bulan')
                ->icon('heroicon-o-check-circle')
                ->color('danger'),

            Stat::make('Total Keluar Tidak Normal', $keluarTidakNormal)
                ->description('< 3 bulan')
                ->icon('heroicon-o-x-circle')
                ->color('primary'),
        ];
    }
}