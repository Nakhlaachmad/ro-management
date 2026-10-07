<?php

namespace Tests\Feature;

use App\Filament\Widgets\FpallStats;
use App\Filament\Widgets\FpChart;
use App\Filament\Widgets\FpperiodeStats;
use App\Filament\Widgets\FpPerTeamStats;
use App\Models\Fp;
use App\Models\Team;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IntegrationTestCase;

final class LegacyDateWidgetsTest extends IntegrationTestCase
{
    public static function monthCases(): array
    {
        $cases = LegacyFpCharacterizationTest::legacyDateCases();
        foreach ($cases as &$case) {
            $case = [$case[0], $case[1], $case[4]];
        }
        unset($case);

        return $cases + [
            'same date' => ['2026-01-05', '2026-01-05', [0, 0, 1]],
            'one month' => ['2026-01-05', '2026-02-05', [0, 0, 1]],
            'above two months' => ['2026-01-05', '2026-03-06', [0, 1, 0]],
            'non leap February month end' => ['2026-01-30', '2026-02-28', [0, 0, 1]],
            'reverse partial months' => ['2026-03-04', '2026-01-05', [0, 0, 1]],
        ];
    }

    #[DataProvider('monthCases')]
    public function test_actual_stats_widgets_preserve_legacy_counts(string $entry, ?string $exit, array $expected): void
    {
        $this->createFp($entry, $exit);
        $all = new class extends FpallStats
        {
            public function output(): array
            {
                return $this->getStats();
            }
        };
        $period = new class extends FpperiodeStats
        {
            public function output(): array
            {
                return $this->getStats();
            }
        };
        $period->filters = ['startDate' => '2020-01-01', 'endDate' => '2035-12-31'];
        $team = new class extends FpPerTeamStats
        {
            public function output(): array
            {
                return $this->getStats();
            }
        };
        $this->assertSame($expected, array_map(fn ($stat) => $stat->getValue(), $all->output()));
        $this->assertSame([1, $expected[1], $expected[2]], array_map(fn ($stat) => $stat->getValue(), $period->output()));
        foreach ($team->output() as $index => $stat) {
            preg_match_all('/>\s*(\d+)\s*<\/span>/', (string) $stat->getDescription(), $matches);
            $this->assertSame([(string) $expected[$index]], $matches[1]);
        }
    }

    private function createFp(string $entry, ?string $exit): void
    {
        Fp::create([
            'name' => 'Synthetic FP', 'no_tlfn' => '0000000000', 'no_rekening' => 'TEST-ONLY', 'no_ktp' => 'TEST-ONLY',
            'alamat_ktp' => 'Synthetic', 'alamat' => 'Synthetic', 'tempat_lahir' => 'Synthetic', 'tanggal_lahir' => '2000-01-01',
            'lulusan' => 'Synthetic', 'grade' => 'A',
            'team_id' => Team::create(['name' => 'Synthetic Leader', 'team' => 'Synthetic Team'])->id,
            'jalur' => 'MGM', 'tanggal_masuk' => $entry, 'tanggal_keluar' => $exit, 'status' => 'nonaktif',
        ]);
    }

    public static function chartCases(): array
    {
        return [
            '29 daily' => [29, 'daily'], '30 daily' => [30, 'daily'], '31 weekly' => [31, 'weekly'],
            '179 weekly' => [179, 'weekly'], '180 weekly' => [180, 'weekly'], '181 monthly' => [181, 'monthly'],
            '1094 monthly' => [1094, 'monthly'], '1095 monthly' => [1095, 'monthly'], '1096 yearly' => [1096, 'yearly'],
            'reverse daily empty' => [-30, 'daily'],
            'same day daily' => [0, 'daily'],
        ];
    }

    #[DataProvider('chartCases')]
    public function test_actual_chart_preserves_labels_and_data(int $days, string $granularity): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $start = new DateTimeImmutable('2026-01-05');
        $end = $start->modify(($days >= 0 ? '+' : '').$days.' days');
        $this->createFp($start->format('Y-m-d'), null);
        $chart = new class extends FpChart
        {
            public function output(): array
            {
                return $this->getData();
            }
        };
        $chart->filters = ['startDate' => $start->format('Y-m-d'), 'endDate' => $end->format('Y-m-d')];
        $expectedLabels = [];
        if ($granularity === 'daily') {
            for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
                if ((int) $date->format('N') < 6) {
                    $expectedLabels[] = $date->format('d M');
                }
            }
        } elseif ($granularity === 'weekly') {
            for ($date = $start; $date <= $end; $date = $date->modify('+7 days')) {
                $last = $date->modify('+4 days');
                if ($last > $end) {
                    $last = $end;
                }
                $expectedLabels[] = $date->format('d M').' - '.$last->format('d M');
            }
        } elseif ($granularity === 'monthly') {
            for ($date = $start->modify('first day of this month'); $date <= $end; $date = $date->modify('+1 month')) {
                $expectedLabels[] = $date->format('M Y');
            }
        } else {
            for ($year = 2026; $year <= (int) $end->format('Y'); $year++) {
                $expectedLabels[] = (string) $year;
            }
        }
        $output = $chart->output();
        $this->assertSame($expectedLabels, $output['labels']);
        $expectedData = array_fill(0, count($expectedLabels), 0);
        if ($days >= 0) {
            $expectedData[0] = 1;
        }
        $this->assertSame($expectedData, $output['datasets'][0]['data']);
        $this->assertSame('Trend FP', $output['datasets'][0]['label']);
    }
}
