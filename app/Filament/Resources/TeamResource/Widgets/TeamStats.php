<?php

namespace App\Filament\Resources\TeamResource\Widgets;

use App\Models\Team;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TeamStats extends BaseWidget
{
    protected function getStats(): array
    {
        return Team::withCount([
            'fps as aktif_count' => function ($query) {
                $query->where('status', 'aktif');
            }
        ])->get()->map(function ($team) {
        return Stat::make(
            $team->name . ' > ' . $team->team,
            $team->aktif_count . ' Anggota Aktif'
        );
        })->toArray();
    }
}

