<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\FP\FpDashboardAnalytics;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class PremiumDashboardTest extends TestCase
{
    public function test_calendar_month_rolls_at_jakarta_midnight_without_changing_history(): void
    {
        config(['fp.business_timezone' => 'Asia/Jakarta']);
        [$start,$end] = FpDashboardAnalytics::bounds(CarbonImmutable::parse('2026-10-31T16:59:59Z'));
        self::assertSame('2026-10-01', $start->toDateString());
        self::assertSame('2026-10-31', $end->toDateString());
        [$start,$end] = FpDashboardAnalytics::bounds(CarbonImmutable::parse('2026-10-31T17:00:00Z'));
        self::assertSame('2026-11-01', $start->toDateString());
        self::assertSame('2026-11-30', $end->toDateString());
    }

    public function test_period_flows_and_cumulative_total_are_distinct(): void
    {
        $start = CarbonImmutable::parse('2026-11-01', 'Asia/Jakarta');
        $joined = ['2026-11' => 2, '2027-01' => 1];
        $exited = ['2026-11' => 1];
        $s = FpDashboardAnalytics::assemble($start, $joined, $exited, 7);
        self::assertSame([2, 0, 1, 0, 0, 0], $s['joined']);
        self::assertSame([1, 0, 0, 0, 0, 0], $s['exited']);
        self::assertSame([9, 9, 10, 10, 10, 10], $s['cumulative']);
        self::assertCount(6, $s['labels']);
        self::assertSame(['2026-11' => 2, '2027-01' => 1], $joined);
    }

    public function test_guest_cannot_query_dashboard_and_bm_can_view_chart(): void
    {
        config(['fp.enabled' => true, 'fp.core_schema_only' => false]);
        self::assertFalse(\App\Filament\Widgets\FpFlowChart::canView());
        auth()->setUser(\Tests\VictoryAccess\RoleMatrixTest::actor(['bm']));
        self::assertTrue(\App\Filament\Widgets\FpFlowChart::canView());
        auth()->forgetUser();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new \App\Filament\Pages\FP\Dashboard)->analytics();
    }
}
