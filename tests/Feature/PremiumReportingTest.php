<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\FP\ReportColumns;
use App\Application\FP\ReportExport;
use App\Application\FP\ReportFilters;
use App\Application\FP\ReportPeriods;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class PremiumReportingTest extends TestCase
{
    public function test_inclusive_presets_and_jakarta_month_year_boundary(): void
    {
        config(['fp.business_timezone' => 'Asia/Jakarta']);
        $now = CarbonImmutable::parse('2026-12-31T17:00:00Z');
        foreach (['today' => ['2027-01-01', '2027-01-01'], 'last7' => ['2026-12-26', '2027-01-01'], 'last30' => ['2026-12-03', '2027-01-01']] as $preset => $expected) {
            $v = ReportPeriods::resolve(['period' => $preset], $now);
            self::assertSame($expected, [$v['from'], $v['until']]);
        }
        $v = ReportPeriods::resolve(['period' => 'month', 'month' => 2, 'year' => 2028], $now);
        self::assertSame(['2028-02-01', '2028-02-29'], [$v['from'], $v['until']]);
        $v = ReportPeriods::resolve(['period' => 'year', 'year' => 2027], $now);
        self::assertSame(['2027-01-01', '2027-12-31'], [$v['from'], $v['until']]);
        self::assertSame(['from' => '2026-10-01', 'until' => '2026-10-01'], ReportPeriods::resolve(['from' => '2026-10-01', 'until' => '2026-10-01'], $now));
    }

    public function test_filter_validation_rejects_missing_month_and_unknown_period(): void
    {
        foreach ([['period' => 'month', 'year' => 2026], ['period' => 'year'], ['period' => 'forever'], ['period' => 'month', 'year' => 2026, 'month' => 13]] as $bad) {
            try {
                ReportFilters::validate($bad + ['status' => 'all', 'date_field' => 'reference_date']);
                self::fail('Invalid filters accepted');
            } catch (\Illuminate\Validation\ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_selected_columns_have_stable_order_and_block_unknown_or_empty(): void
    {
        self::assertSame(['name', 'nip', 'email'], ReportColumns::selected(['email', 'nip', 'name', 'nip']));
        foreach ([[], ['anything'], 'nip'] as $bad) {
            try {
                ReportColumns::selected($bad);
                self::fail('Invalid selection accepted');
            } catch (\Illuminate\Validation\ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_operator_authorized_sensitive_columns_are_selectable(): void
    {
        $columns = ['nik', 'bank_account', 'phone', 'address_ktp', 'address'];
        self::assertSame($columns, ReportColumns::selected($columns));
        self::assertCount(5, ReportColumns::headers($columns));
        self::assertNotContains('nik', ReportColumns::DEFAULTS);
    }

    public function test_custom_csv_xlsx_match_selected_columns_and_neutralize_formulas(): void
    {
        $columns = ReportColumns::selected(['email', 'name']);
        $headers = ReportColumns::headers($columns);
        self::assertSame(['Nama FP', 'Email'], $headers);
        $csv = ReportExport::render([['=1+1', 'synthetic@example.invalid']], 'csv', $headers);
        self::assertStringContainsString("'=1+1", $csv);
        $bytes = ReportExport::render([['=1+1', 'synthetic@example.invalid']], 'xlsx', $headers);
        $tmp = tempnam(sys_get_temp_dir(), 'fp37f-');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive;
        self::assertTrue($zip->open($tmp) === true);
        try {
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            self::assertStringContainsString('ref="A1:B2"', $xml);
            self::assertSame(4, substr_count($xml, 't="inlineStr"'));
            self::assertStringNotContainsString('<f>', $xml);
        } finally {
            $zip->close();
            unlink($tmp);
        }
    }
}
