<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\FP\ReportFilters;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class RefinementFiltersTest extends TestCase
{
    private function validateFilters(array $input): array
    {
        return ReportFilters::validate($input + ['status' => 'all', 'date_field' => 'reference_date']);
    }

    public function test_removed_report_parameters_cannot_silently_filter_exports(): void
    {
        $v = $this->validateFilters(['name' => 'No match', 'nip' => '1 OR 1=1']);
        self::assertArrayNotHasKey('name', $v);
        self::assertArrayNotHasKey('nip', $v);
    }

    public function test_presets_ignore_invalid_stale_hidden_inputs(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-31T17:00:00Z'));
        try {
            foreach (['today' => '2027-01-01', 'last7' => '2026-12-26', 'last30' => '2026-12-03'] as $period => $from) {
                $v = $this->validateFilters(['period' => $period, 'from' => 'invalid', 'until' => '1900-01-01', 'month' => 99, 'year' => 'bad']);
                self::assertSame($from, $v['from']);
                self::assertSame('2027-01-01', $v['until']);
                self::assertArrayNotHasKey('month', $v);
                self::assertArrayNotHasKey('year', $v);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_custom_range_ignores_month_and_year(): void
    {
        $v = $this->validateFilters(['period' => 'custom', 'from' => '2026-10-01', 'until' => '2026-10-15', 'month' => 99, 'year' => 'bad']);
        self::assertSame('2026-10-01', $v['from']);
        self::assertSame('2026-10-15', $v['until']);
        self::assertArrayNotHasKey('month', $v);
        self::assertArrayNotHasKey('year', $v);
    }

    public function test_month_and_year_ignore_stale_dates_and_irrelevant_month(): void
    {
        $v = $this->validateFilters(['period' => 'month', 'month' => 2, 'year' => 2028, 'from' => 'bad', 'until' => 'bad']);
        self::assertSame(['2028-02-01', '2028-02-29'], [$v['from'], $v['until']]);
        $v = $this->validateFilters(['period' => 'year', 'year' => 2027, 'month' => 99, 'from' => 'bad']);
        self::assertSame(['2027-01-01', '2027-12-31'], [$v['from'], $v['until']]);
        self::assertArrayNotHasKey('month', $v);
    }

    public function test_invalid_relevant_period_values_still_fail_closed(): void
    {
        foreach ([['period' => 'custom', 'from' => '2026-10-15', 'until' => '2026-10-01'], ['period' => 'month', 'month' => 13, 'year' => 2026], ['period' => 'year', 'year' => 1899], ['period' => 'year', 'year' => 10000]] as $input) {
            try {
                $this->validateFilters($input);
                self::fail('Invalid relevant period accepted');
            } catch (\Illuminate\Validation\ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
