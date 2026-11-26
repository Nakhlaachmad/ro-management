<?php

declare(strict_types=1);

namespace Tests\FpReports;

use App\Application\FP\FpReports;
use App\Application\FP\ReportExport;
use App\Application\FP\ReportFilters;
use PHPUnit\Framework\TestCase;

final class ReportsTest extends TestCase
{
    protected function setUp(): void
    {
        $c = new \Illuminate\Container\Container;
        $c->instance('config', new \Illuminate\Config\Repository(['fp' => ['enabled' => true, 'core_schema_only' => false]]));
        $v = new \Illuminate\Validation\Factory(new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en'), $c);
        $c->instance('validator', $v);
        \Illuminate\Container\Container::setInstance($c);
        \Illuminate\Support\Facades\Facade::setFacadeApplication($c);
        $r = new \Illuminate\Database\ConnectionResolver(['offline' => new \Illuminate\Database\PostgresConnection(null)]);
        $r->setDefaultConnection('offline');
        \Illuminate\Database\Eloquent\Model::setConnectionResolver($r);
    }

    public function test_filters_are_bound_and_use_canonical_exit_records(): void
    {
        $q = FpReports::query(['status' => 'normal', 'date_field' => 'exit_date', 'from' => '2026-01-01', 'until' => '2026-12-31', 'team_id' => 3, 'path_id' => 4]);
        self::assertSame(['EXITED', 'NORMAL', 3, 4, '2026-01-01', '2026-12-31'], $q->getBindings());
        self::assertStringContainsString('"fp_exits"', $q->toSql());
        self::assertStringNotContainsString('"fps"', $q->toSql());
        self::assertStringNotContainsString('encrypted', $q->toSql());
    }

    public function test_unresolved_is_explicit_and_never_assigned_active(): void
    {
        $q = FpReports::query(['status' => 'unresolved']);
        self::assertSame(['EXITED'], $q->getBindings());
        self::assertStringContainsString('is null', $q->toSql());
        self::assertStringContainsString('not exists', $q->toSql());
    }

    public function test_filter_validation_rejects_invalid_dates_status_and_identifier(): void
    {
        foreach ([['from' => '2026-02-30'], ['from' => '2026-03-02', 'until' => '2026-03-01'], ['status' => 'legacy-active'], ['path_id' => '1 OR 1=1'], ['date_field' => 'nip']] as $bad) {
            try {
                ReportFilters::validate(array_replace(['status' => 'all', 'date_field' => 'reference_date'], $bad));
                self::fail('Invalid filter accepted');
            } catch (\Illuminate\Validation\ValidationException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('2026-10-11', ReportFilters::validate(['status' => 'all', 'date_field' => 'reference_date', 'until' => '2026-10-11'])['until']);
    }

    public function test_csv_formula_and_leading_control_injection_is_neutralized(): void
    {
        foreach (['=1+1', '+SUM(A1)', '-1+2', '@SUM(A1)', " \t=1", "\u{FEFF}=1", "\tfoo", "\r=1", "\n=1"] as $value) {
            self::assertSame("'".$value, ReportExport::csvCell($value));
        }
        self::assertSame('Victory', ReportExport::csvCell('Victory'));
        self::assertSame('1001', ReportExport::csvCell(1001));
        $csv = ReportExport::render([[1, '=1+1', '', 'Team, A', '', 'Aktif', '', '', '']], 'csv');
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $s = fopen('php://temp', 'w+');
        fwrite($s, substr($csv, 3));
        rewind($s);
        self::assertSame(ReportExport::HEADERS, fgetcsv($s, 0, ',', '"', ''));
        $row = fgetcsv($s, 0, ',', '"', '');
        fclose($s);
        self::assertSame("'=1+1", $row[1]);
        self::assertSame('Team, A', $row[3]);
        self::assertSame('', $row[2]);
    }

    public function test_xlsx_is_real_package_with_explicit_text_and_no_formulas(): void
    {
        $bytes = ReportExport::render([[1, '=HYPERLINK("https://invalid")', '1001', 'A & B', '', 'Aktif', '', '', '']], 'xlsx');
        $path = tempnam(sys_get_temp_dir(), 'fp-test-');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        self::assertTrue($zip->open($path) === true);
        try {
            self::assertSame(5, $zip->numFiles);
            foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml'] as $name) {
                $d = new \DOMDocument;
                self::assertTrue($d->loadXML($zip->getFromName($name)));
            }
            $xp = new \DOMXPath($d);
            $xp->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            self::assertSame(0, $xp->query('//s:f')->length);
            self::assertSame(18, $xp->query('//s:c[@t="inlineStr"]')->length);
            self::assertSame('=HYPERLINK("https://invalid")', $xp->evaluate('string(//s:c[@r="B2"]/s:is/s:t)'));
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_volume_and_byte_caps_fail_closed(): void
    {
        foreach ([array_fill(0, 5001, ['x']), [[str_repeat('x', ReportExport::MAX_BYTES + 1)]]] as $rows) {
            foreach (['csv', 'xlsx'] as $format) {
                try {
                    ReportExport::render($rows, $format);
                    self::fail('Limit bypass');
                } catch (\Illuminate\Validation\ValidationException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    public function test_role_policy_allows_bm_view_and_scoped_export(): void
    {
        $user = new class extends \App\Models\User
        {
            public string $role = 'bm';

            public function hasRole($roles, ?string $guard = null): bool
            {
                return $roles === $this->role;
            }

            public function hasPermissionTo($permission, $guardName = null): bool
            {
                return true;
            }
        };
        $user->is_active = true;
        self::assertFalse(FpReports::allowed(null));
        self::assertTrue(FpReports::allowed($user));
        self::assertTrue(FpReports::allowed($user, true));
        foreach (['admin', 'hrd', 'ro'] as $role) {
            $user->role = $role;
            self::assertTrue(FpReports::allowed($user));
            self::assertTrue(FpReports::allowed($user, true));
        }
        $user->role = 'unknown';
        self::assertFalse(FpReports::allowed($user));
        $user->role = 'admin';
        $user->is_active = false;
        self::assertFalse(FpReports::allowed($user, true));
        self::assertFalse(\App\Filament\Pages\FP\Reports::isDiscovered());
    }
}
