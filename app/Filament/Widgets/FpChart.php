<?php

namespace App\Filament\Widgets;

use App\Models\Fp;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class FpChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Grafik FP Masuk per Tanggal';
    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $startDate = $this->filters['startDate'] ?? now()->startOfMonth()->toDateString();
        $endDate = $this->filters['endDate'] ?? now()->endOfMonth()->toDateString();

        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $days = $start->diffInDays($end);

        /*
        |--------------------------------------------------------------------------
        | AUTO GROUPING
        |--------------------------------------------------------------------------
        */

        if ($days > 180) {

            $results = Fp::whereBetween('tanggal_masuk', [$start, $end])
                ->selectRaw('DATE_FORMAT(tanggal_masuk, "%Y-%m") as bulan, COUNT(*) as total')
                ->groupBy('bulan')
                ->pluck('total', 'bulan');

            $period = CarbonPeriod::create(
                $start->copy()->startOfMonth(),
                '1 month',
                $end->copy()->startOfMonth()
            );

            $labels = [];
            $data = [];

            foreach ($period as $date) {
                $key = $date->format('Y-m');
                $labels[] = $date->format('M Y');
                $data[] = $results[$key] ?? 0;
            }

        } else {

            $results = Fp::whereBetween('tanggal_masuk', [$start, $end])
                ->selectRaw('DATE(tanggal_masuk) as tanggal, COUNT(*) as total')
                ->groupBy('tanggal')
                ->pluck('total', 'tanggal');

            $period = CarbonPeriod::create($start, $end);

            $labels = [];
            $data = [];

            foreach ($period as $date) {
                $key = $date->format('Y-m-d');
                $labels[] = $date->format('d M');
                $data[] = $results[$key] ?? 0;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | MOVING AVERAGE 7 HARI
        |--------------------------------------------------------------------------
        */

        $movingAverage = [];
        $window = 7;

        foreach ($data as $index => $value) {
            $slice = array_slice($data, max(0, $index - $window + 1), $window);
            $movingAverage[] = round(array_sum($slice) / count($slice), 2);
        }

        /*
        |--------------------------------------------------------------------------
        | DYNAMIC SMOOTHING
        |--------------------------------------------------------------------------
        */

        if ($days <= 30) {
            $tension = 0.5;
            $pointRadius = 3;
        } elseif ($days <= 90) {
            $tension = 0.3;
            $pointRadius = 0;
        } else {
            $tension = 0.1;
            $pointRadius = 0;
        }

        /*
        |--------------------------------------------------------------------------
        | AUTO TREND COLOR
        |--------------------------------------------------------------------------
        */

        $first = $data[0] ?? 0;
        $last = end($data) ?: 0;

        $isUptrend = $last >= $first;

        $lineColor = $isUptrend ? '#22c55e' : '#ef4444';
        $bgColor = $isUptrend
            ? 'rgba(34,197,94,0.15)'
            : 'rgba(239,68,68,0.15)';

        return [
            'datasets' => [

                // DATA UTAMA
                [
                    'label' => 'FP Masuk',
                    'data' => $data,
                    'borderColor' => $lineColor,
                    'backgroundColor' => $bgColor,
                    'fill' => true,
                    'tension' => $tension,
                    'cubicInterpolationMode' => 'monotone',
                    'pointRadius' => $pointRadius,
                    'borderWidth' => 3,
                ],

                // MOVING AVERAGE
                [
                    'label' => 'Rata-rata 7 Hari',
                    'data' => $movingAverage,
                    'borderColor' => '#60a5fa',
                    'backgroundColor' => 'transparent',
                    'borderDash' => [6, 6],
                    'tension' => 0.4,
                    'pointRadius' => 0,
                    'borderWidth' => 2,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'animation' => [
                'duration' => 800,
                'easing' => 'easeInOutQuart',
            ],
            'interaction' => [
                'mode' => 'nearest',
                'axis' => 'x',
                'intersect' => false,
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                ],
                'zoom' => [
                    'zoom' => [
                        'wheel' => [
                            'enabled' => true,
                        ],
                        'pinch' => [
                            'enabled' => true,
                        ],
                        'mode' => 'x',
                    ],
                    'pan' => [
                        'enabled' => true,
                        'mode' => 'x',
                    ],
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'grid' => [
                        'color' => 'rgba(255,255,255,0.04)',
                    ],
                ],
                'x' => [
                    'grid' => [
                        'display' => false,
                    ],
                ],
            ],
        ];
    }
}
