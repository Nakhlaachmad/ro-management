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

    protected static ?string $heading = 'Grafik FP Masuk';
    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $startDate = $this->filters['startDate'] ?? now()->startOfMonth()->toDateString();
        $endDate = $this->filters['endDate'] ?? now()->endOfMonth()->toDateString();

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        $days = $start->diffInDays($end);

        $results = Fp::whereBetween('tanggal_masuk', [$start, $end])
            ->selectRaw('DATE(tanggal_masuk) as tanggal, COUNT(*) as total')
            ->groupBy('tanggal')
            ->pluck('total', 'tanggal');

        $labels = [];
        $data = [];
        $days = $start->diffInDays($end);
        $labels = [];
        $data = [];

        $isWorkday = function ($date) {
            return $date->isWeekday(); 
        };


        if ($days > 1095) {

            $period = CarbonPeriod::create(
                $start->copy()->startOfYear(),
                '1 year',
                $end->copy()->startOfYear()
            );

            foreach ($period as $date) {

                $yearStart = $date->copy()->startOfYear();
                $yearEnd = $date->copy()->endOfYear();

                if ($yearStart < $start) {
                    $yearStart = $start;
                }

                if ($yearEnd > $end) {
                    $yearEnd = $end;
                }

                $total = 0;

                foreach (CarbonPeriod::create($yearStart, $yearEnd) as $day) {
                    if ($isWorkday($day)) {
                        $total += $results[$day->format('Y-m-d')] ?? 0;
                    }
                }

                $labels[] = $date->format('Y');
                $data[] = $total;
            }

        } elseif ($days > 180) {

            $period = CarbonPeriod::create(
                $start->copy()->startOfMonth(),
                '1 month',
                $end->copy()->startOfMonth()
            );

            foreach ($period as $date) {

                $monthStart = $date->copy()->startOfMonth();
                $monthEnd = $date->copy()->endOfMonth();

                if ($monthStart < $start) {
                    $monthStart = $start;
                }

                if ($monthEnd > $end) {
                    $monthEnd = $end;
                }

                $total = 0;

                foreach (CarbonPeriod::create($monthStart, $monthEnd) as $day) {
                    if ($isWorkday($day)) {
                        $total += $results[$day->format('Y-m-d')] ?? 0;
                    }
                }

                $labels[] = $date->format('M Y');
                $data[] = $total;
            }

        } elseif ($days > 30) {

            $current = $start->copy()->startOfWeek(Carbon::MONDAY);

            while ($current <= $end) {

                $weekStart = $current->copy()->startOfWeek(Carbon::MONDAY);
                $weekEnd = $current->copy()->endOfWeek(Carbon::FRIDAY);

                if ($weekStart < $start) {
                    $weekStart = $start;
                }

                if ($weekEnd > $end) {
                    $weekEnd = $end;
                }

                $total = 0;

                foreach (CarbonPeriod::create($weekStart, $weekEnd) as $day) {
                    if ($isWorkday($day)) {
                        $total += $results[$day->format('Y-m-d')] ?? 0;
                    }
                }

                $labels[] = $weekStart->format('d M') . ' - ' . $weekEnd->format('d M');
                $data[] = $total;

                $current->addWeek();
            }

        } else {

            foreach (CarbonPeriod::create($start, $end) as $day) {

                if (!$isWorkday($day)) {
                    continue; // skip Sabtu & Minggu
                }

                $labels[] = $day->format('d M');
                $data[] = $results[$day->format('Y-m-d')] ?? 0;
            }
        }

                $periodEma = count($data) > 12 ? 6 : 4;
                $multiplier = 2 / ($periodEma + 1);

                $ema = [];
                $ema[0] = $data[0] ?? 0;

                foreach ($data as $i => $value) {
                    if ($i == 0) continue;
                    $ema[$i] = round((($value - $ema[$i - 1]) * $multiplier) + $ema[$i - 1], 2);
                }

                $first = $ema[0] ?? 0;
                $last = end($ema) ?: 0;

                $isUptrend = $last >= $first;

                $lineColor = $isUptrend ? '#16a34a' : '#dc2626';
                $bgColor = $isUptrend
                    ? 'rgba(22,163,74,0.15)'
                    : 'rgba(220,38,38,0.15)';

                return [
                    'datasets' => [
                        [
                            'label' => 'Trend FP',
                            'data' => $data,
                            'borderColor' => $lineColor,
                            'backgroundColor' => $bgColor,
                            'fill' => true,
                            'tension' => 0.65,
                            'cubicInterpolationMode' => 'monotone',
                            'pointRadius' => 0,
                            'borderWidth' => 3,
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
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => false,
                    'grace' => '15%',
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
