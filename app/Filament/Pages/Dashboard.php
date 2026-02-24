<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use App\Filament\Widgets\FpSummaryStats;
use App\Filament\Widgets\FpAktifStatsWidget;
use App\Filament\Widgets\FpKeluarNormalStats;
use App\Filament\Widgets\FpKeluarTidakNormalStats;
use App\Filament\Widgets\FpChart;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;
    protected static ?string $navigationIcon = 'heroicon-o-briefcase';

    public function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Filter Periode Grafik')
                    ->schema([
                        DatePicker::make('startDate')
                            ->label('Dari Tanggal')
                            ->default(now()->startOfMonth())
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->live(),

                        DatePicker::make('endDate')
                            ->label('Sampai Tanggal')
                            ->default(now()->endOfMonth())
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->live(),
                    ])
                    ->columns(2)
                    ->compact(),
            ]);
    }
    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\FpallStats::class,
        ];
    }
}
