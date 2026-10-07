<?php

namespace App\Filament\Widgets;

use App\Models\Fp;
use App\Models\Team;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\HtmlString;
use Carbon\Carbon;

class FpPerTeamStats extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $teams = Team::select('team')->distinct()->pluck('team');
        $aktifList = '<div class="mt-3 text-sm divide-y divide-gray-200">';
        $normalList = '<div class="mt-3 text-sm divide-y divide-gray-200">';
        $tidakNormalList = '<div class="mt-3 text-sm divide-y divide-gray-200">';

        foreach ($teams as $teamName) {

            $teamIds = Team::where('team', $teamName)->pluck('id');

            // FP Aktif
            $aktif = Fp::whereIn('team_id', $teamIds)
                ->where('status', 'aktif')
                ->count();

            // FP Keluar
            $keluarData = Fp::whereIn('team_id', $teamIds)
                ->whereNotNull('tanggal_keluar')
                ->get();

            $keluarNormal = 0;
            $keluarTidakNormal = 0;

            foreach ($keluarData as $fp) {
                $masaKerja = \Carbon\Carbon::parse($fp->tanggal_masuk)
                    ->diffInMonths(\Carbon\Carbon::parse($fp->tanggal_keluar), true);

                if ($masaKerja < 2) {
                    $keluarTidakNormal++;
                } else {
                    $keluarNormal++;
                }
            }

            $rowAktif = "
                <div class='flex items-center justify-between py-2 px-1'>
                    <span class='text-green-700'>
                        {$teamName}
                        <span class='text-gray-400 mx-1'>•</span>
                    </span>
                    <span class='font-semibold px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-sm tabular-nums'>
                        {$aktif}
                    </span>
                </div>
            ";
            $rowNormal = "
                <div class='flex items-center justify-between py-2 px-1'>
                    <span class='text-red-700'>
                        {$teamName}
                        <span class='text-gray-400 mx-1'>•</span>
                    </span>
                    <span class='font-semibold px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-sm tabular-nums'>
                        {$keluarNormal}
                    </span>
                </div>
            ";
            $rowTidakNormal = "
                <div class='flex items-center justify-between py-2 px-1'>
                    <span class='text-yellow-700'>
                        {$teamName}
                        <span class='text-gray-400 mx-1'>•</span>
                    </span>
                    <span class='font-semibold px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-sm tabular-nums'>
                        {$keluarTidakNormal}
                    </span>
                </div>
            ";


            $aktifList .= $rowAktif;
            $normalList .= $rowNormal;
            $tidakNormalList .= $rowTidakNormal;
        }

        $aktifList .= '</div>';
        $normalList .= '</div>';
        $tidakNormalList .= '</div>';

        return [
            Stat::make('FP Aktif', '')
                ->description(new HtmlString($aktifList))
                ->color('success')
                ->icon('heroicon-s-check-circle'),

            Stat::make('Keluar Normal (≥2 bln)', '')
                ->description(new HtmlString($normalList))
                ->color('danger')
                ->icon('heroicon-s-arrow-right-circle'),

            Stat::make('Keluar Tidak Normal (<2 bln)', '')
                ->description(new HtmlString($tidakNormalList))
                ->color('warning')
                ->icon('heroicon-s-exclamation-circle'),
        ];
    }
}