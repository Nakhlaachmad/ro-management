<?php

namespace App\Filament\Widgets;

use App\Models\Fp;
use App\Models\Team;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\HtmlString;

class FpKeluarTidakNormalStats extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $totalKeluarTidakNormal = Fp::keluarTidakNormal()
            ->count();

        // Ambil nama team unik
        $teams = Team::select('team')->distinct()->pluck('team');

        $list = '<div class="mt-3 space-y-2 text-sm">';

        foreach ($teams as $teamName) {

            $teamIds = Team::where('team', $teamName)->pluck('id');

            $keluarTidakNormalPerTeam = Fp::whereIn('team_id', $teamIds)
                ->keluarTidakNormal()
                ->count();

        $list .= "
            <div class='flex items-center justify-between px-1 py-1'>
                <span class='text-gray-700 dark:text-gray-200'>
                    {$teamName}
                    <span class='text-gray-400 dark:text-gray-500 mx-1'>•</span>
                </span>
                <span class='font-semibold px-2 py-0.5 rounded
                            bg-red-100 dark:bg-red-900/40
                            text-red-600 dark:text-red-400
                            text-sm tabular-nums'>
                    {$keluarTidakNormalPerTeam}
                </span>
            </div>
        ";
        }

        $list .= '</div>';

        return [
            Stat::make('Total FP Keluar Tidak Normal', $totalKeluarTidakNormal)
                ->description(new HtmlString($list))
                ->icon('heroicon-s-exclamation-circle')
                ->color('warning'),
        ];
    }
}
