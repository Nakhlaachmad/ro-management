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

        /*
        |--------------------------------------------------------------------------
        | AUTO GROUPING
        |--------------------------------------------------------------------------
        */

        // Jika lebih dari 90 hari → Monthly
       $days = $start->diffInDays($end);

$labels = [];
$data = [];

/*
|--------------------------------------------------------------------------
| > 90 Hari → WEEKLY (Sabtu - Jumat)
|--------------------------------------------------------------------------
*/
if ($days > 90) {

    $current = $start->copy()->startOfWeek(Carbon::SATURDAY);

    while ($current <= $end) {

        $weekStart = $current->copy();
        $weekEnd = $current->copy()->endOfWeek(Carbon::FRIDAY);

        if ($weekEnd > $end) {
            $weekEnd = $end;
        }

        $total = 0;

        foreach (CarbonPeriod::create($weekStart, $weekEnd) as $day) {
            $total += $results[$day->format('Y-m-d')] ?? 0;
        }

        $labels[] = $weekStart->format('d M') . ' - ' . $weekEnd->format('d M');
        $data[] = $total;

        $current->addWeek();
    }

/*
|--------------------------------------------------------------------------
| 31 - 60 Hari → Per 3 Hari
|--------------------------------------------------------------------------
*/
} elseif ($days > 30) {

    $current = $start->copy();

    while ($current <= $end) {

        $periodStart = $current->copy();
        $periodEnd = $current->copy()->addDays(2);

        if ($periodEnd > $end) {
            $periodEnd = $end;
        }

        $total = 0;

        foreach (CarbonPeriod::create($periodStart, $periodEnd) as $day) {
            $total += $results[$day->format('Y-m-d')] ?? 0;
        }

        $labels[] = $periodStart->format('d M') . ' - ' . $periodEnd->format('d M');
        $data[] = $total;

        $current->addDays(3);
    }

/*
|--------------------------------------------------------------------------
| ≤ 30 Hari → Per Hari
|--------------------------------------------------------------------------
*/
} else {

    foreach (CarbonPeriod::create($start, $end) as $day) {
        $labels[] = $day->format('d M');
        $data[] = $results[$day->format('Y-m-d')] ?? 0;
    }
}
        /*
        |--------------------------------------------------------------------------
        | EMA SMOOTHING (LANDai ala grafik emas)
        |--------------------------------------------------------------------------
        */

        $periodEma = count($data) > 12 ? 6 : 4;
        $multiplier = 2 / ($periodEma + 1);

        $ema = [];
        $ema[0] = $data[0] ?? 0;

        foreach ($data as $i => $value) {
            if ($i == 0) continue;
            $ema[$i] = round((($value - $ema[$i - 1]) * $multiplier) + $ema[$i - 1], 2);
        }

        /*
        |--------------------------------------------------------------------------
        | AUTO TREND COLOR
        |--------------------------------------------------------------------------
        */

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
                    'data' => $ema,
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
