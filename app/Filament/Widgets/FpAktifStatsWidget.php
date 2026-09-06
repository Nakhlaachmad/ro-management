<?php

namespace App\Filament\Widgets;

use App\Models\Fp;
use App\Models\Team;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\HtmlString;

class FpAktifStatsWidget extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        // Total semua aktif
        $totalAktif = Fp::where('status', 'aktif')->count();

        // Ambil nama team unik
        $teams = Team::select('team')->distinct()->pluck('team');

        $list = '<div class="mt-3 space-y-2 text-sm">';

        foreach ($teams as $teamName) {

            // Ambil semua id dengan nama team sama
            $teamIds = Team::where('team', $teamName)->pluck('id');

            $aktifPerTeam = Fp::whereIn('team_id', $teamIds)
                ->where('status', 'aktif')
                ->count();

            $list .= "
                <div class='flex items-center justify-between px-1 py-1'>
                    <span class='text-gray-700 dark:text-gray-200'>
                        {$teamName}
                        <span class='text-gray-400 dark:text-gray-500 mx-1'>•</span>
                    </span>
                    <span class='font-semibold px-2 py-0.5 rounded
                                bg-green-100 dark:bg-green-900/40
                                text-green-600 dark:text-green-400
                                text-sm tabular-nums'>
                        {$aktifPerTeam}
                    </span>
                </div>
            ";
        }

        $list .= '</div>';

        return [
            Stat::make('FP Aktif', $totalAktif)
                ->description(new HtmlString($list))
                ->icon('heroicon-s-check-circle')
                ->color('success'),
        ];
    }
}