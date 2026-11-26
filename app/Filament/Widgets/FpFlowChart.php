<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Application\FP\FeatureAccess;
use App\Application\FP\FpDashboardAnalytics;

final class FpFlowChart extends \Filament\Widgets\ChartWidget
{
    protected static ?string $heading = 'Perjalanan FP · 6 bulan';

    protected static ?string $description = 'Masuk/keluar per bulan (sumbu kiri); seluruh membership masuk kumulatif (sumbu kanan), termasuk EXITED. Record tanpa tanggal referensi tetap di Total FP, dikecualikan dari grafik.';

    protected static ?string $pollingInterval = '60s';

    protected static bool $isLazy = false;

    protected static ?string $maxHeight = '300px';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return FeatureAccess::allowed(auth()->user(), 'f2.view');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        abort_unless(self::canView(), 403);
        $s = FpDashboardAnalytics::series();

        return ['labels' => $s['labels'], 'datasets' => [
            ['label' => 'FP masuk', 'data' => $s['joined'], 'borderColor' => '#0788D0', 'backgroundColor' => 'rgba(7,136,208,.1)', 'tension' => .25, 'yAxisID' => 'flow'],
            ['label' => 'FP keluar', 'data' => $s['exited'], 'borderColor' => '#EEAD38', 'backgroundColor' => 'rgba(238,173,56,.1)', 'tension' => .25, 'yAxisID' => 'flow'],
            ['label' => 'Total kumulatif', 'data' => $s['cumulative'], 'borderColor' => '#7796BD', 'borderDash' => [5, 4], 'tension' => .25, 'yAxisID' => 'total'],
        ]];
    }

    protected function getOptions(): array
    {
        return ['maintainAspectRatio' => false, 'interaction' => ['mode' => 'index', 'intersect' => false], 'scales' => ['y' => ['display' => false, 'grid' => ['display' => false]], 'flow' => ['type' => 'linear', 'position' => 'left', 'beginAtZero' => true, 'ticks' => ['precision' => 0], 'grid' => ['color' => 'rgba(120,145,170,.12)']], 'total' => ['type' => 'linear', 'position' => 'right', 'beginAtZero' => true, 'ticks' => ['precision' => 0], 'grid' => ['drawOnChartArea' => false]]], 'plugins' => ['legend' => ['position' => 'bottom'], 'tooltip' => ['backgroundColor' => '#10263F', 'padding' => 12, 'cornerRadius' => 10]]];
    }
}
