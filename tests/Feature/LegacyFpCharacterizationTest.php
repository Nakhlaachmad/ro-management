<?php

namespace Tests\Feature;

use App\Filament\Widgets\FpallStats;
use App\Models\Fp;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IntegrationTestCase;

final class LegacyFpCharacterizationTest extends IntegrationTestCase
{
    public static function legacyDateCases(): array
    {
        return [
            'reversed dates retain absolute classification' => ['2026-04-06', '2026-01-06', 3, -3, [0, 1, 0]],
            'exact two months are normal despite 59 days' => ['2026-01-05', '2026-03-05', 2, 2, [0, 1, 0]],
            'incomplete second month' => ['2026-01-05', '2026-03-04', 1, 1, [0, 0, 1]],
            'year boundary' => ['2025-12-01', '2026-02-01', 2, 2, [0, 1, 0]],
            'leap year month end' => ['2024-01-31', '2024-03-31', 2, 2, [0, 1, 0]],
            'leap year incomplete month' => ['2024-01-31', '2024-03-01', 1, 1, [0, 0, 1]],
            'leap day entry' => ['2024-02-29', '2024-04-29', 2, 2, [0, 1, 0]],
            'future dates are accepted' => ['2030-01-07', '2030-04-07', 3, 3, [0, 1, 0]],
            'null exit is excluded' => ['2026-01-05', null, null, null, [0, 0, 0]],
        ];
    }

    #[DataProvider('legacyDateCases')]
    public function test_legacy_date_boundaries(string $entry, ?string $exit, ?int $months, ?int $signedMonths, array $expectedStats): void
    {
        $fp = Fp::create($this->attributes(['tanggal_masuk' => $entry, 'tanggal_keluar' => $exit, 'status' => 'nonaktif']));
        $widget = new class extends FpallStats
        {
            public function characterize(): array
            {
                return $this->getStats();
            }
        };
        $this->assertSame($expectedStats, array_map(fn ($stat) => $stat->getValue(), $widget->characterize()));
        $this->assertSame($entry, $fp->fresh()->tanggal_masuk->toDateString());
        if ($exit === null) {
            $this->assertNull($fp->fresh()->tanggal_keluar);

            return;
        }
        $this->assertSame($exit, $fp->fresh()->tanggal_keluar->toDateString());
        $this->assertSame($months, Carbon::parse($entry)->diffInMonths(Carbon::parse($exit)));
        $this->assertSame($signedMonths, Carbon::parse($entry)->diffInMonths(Carbon::parse($exit), false));
    }

    public function test_null_entry_date_is_rejected_by_existing_database_constraint(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        Fp::create($this->attributes(['tanggal_masuk' => null]));
    }

    private function attributes(array $override = []): array
    {
        return array_merge([
            'name' => 'Synthetic FP', 'no_tlfn' => '0000000000', 'no_rekening' => 'TEST-ONLY', 'no_ktp' => 'TEST-ONLY',
            'alamat_ktp' => 'Synthetic', 'alamat' => 'Synthetic', 'tempat_lahir' => 'Synthetic', 'tanggal_lahir' => '2000-01-01',
            'lulusan' => 'Synthetic', 'grade' => 'A', 'team_id' => Team::create(['name' => 'Synthetic Leader', 'team' => 'Synthetic Team'])->id,
            'jalur' => 'MGM', 'tanggal_masuk' => '2026-01-05', 'status' => 'aktif',
        ], $override);
    }

    public function test_creating_saturday_or_sunday_is_rejected_but_weekday_is_accepted(): void
    {
        foreach (['2026-01-03', '2026-01-04'] as $date) {
            try {
                Fp::create($this->attributes(['tanggal_masuk' => $date]));
                $this->fail('Expected weekend rejection.');
            } catch (ValidationException $e) {
                $this->assertSame(['tanggal_masuk' => ['Tanggal masuk tidak boleh hari Sabtu atau Minggu.']], $e->errors());
            }
        }
        $fp = Fp::create($this->attributes());
        $this->assertSame('aktif', $fp->status);
        $this->assertSame('MGM', $fp->jalur); // Preserve unresolved legacy value.
        $this->assertDatabaseCount('fps', 1);
    }

    public function test_updating_weekend_entry_is_rejected(): void
    {
        $fp = Fp::create($this->attributes());
        try {
            $fp->update(['tanggal_masuk' => '2026-01-04']);
            $this->fail('Expected weekend rejection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tanggal_masuk', $e->errors());
        }
        $this->assertSame('2026-01-05', $fp->fresh()->tanggal_masuk->toDateString());
    }

    public function test_nonaktif_transition_overwrites_supplied_exit_date_and_reactivation_preserves_it(): void
    {
        $fp = Fp::create($this->attributes());
        $fp->update(['status' => 'nonaktif', 'tanggal_keluar' => '2026-03-01']);
        $exit = now()->toDateString();
        $this->assertSame($exit, $fp->fresh()->tanggal_keluar->toDateString());
        $this->travel(1)->days();
        $fp->update(['comment' => 'Synthetic comment']);
        $this->assertSame($exit, $fp->fresh()->tanggal_keluar->toDateString());
        $fp->update(['status' => 'aktif']);
        $this->assertSame($exit, $fp->fresh()->tanggal_keluar->toDateString());
        $this->assertSame('aktif', $fp->fresh()->status);
    }

    public function test_creating_nonaktif_does_not_automatically_set_exit_date(): void
    {
        $fp = Fp::create($this->attributes(['status' => 'nonaktif']));
        $this->assertNull($fp->fresh()->tanggal_keluar);
    }

    public function test_dashboard_uses_integer_absolute_calendar_months_not_sixty_days(): void
    {
        foreach ([
            ['2026-01-05', '2026-03-04', 'nonaktif'], // 58 days, 1 full month.
            ['2026-01-05', '2026-03-05', 'nonaktif'], // 59 days, 2 full months.
            ['2026-04-06', '2026-01-06', 'nonaktif'], // Reverse dates: absolute 3 months.
            ['2026-01-05', '2026-03-05', 'aktif'], // Counts active AND exited.
        ] as [$entry, $exit, $status]) {
            Fp::create($this->attributes(['tanggal_masuk' => $entry, 'tanggal_keluar' => $exit, 'status' => $status]));
        }
        $widget = new class extends FpallStats
        {
            public function characterize(): array
            {
                return $this->getStats();
            }
        };
        $stats = $widget->characterize();
        $this->assertSame([1, 3, 1], array_map(fn ($stat) => $stat->getValue(), $stats));
        $this->assertSame(2, Carbon::parse('2026-01-05')->diffInMonths(Carbon::parse('2026-03-05')));
        $this->assertSame(59, Carbon::parse('2026-01-05')->diffInDays(Carbon::parse('2026-03-05')));
    }

    public function test_legacy_scopes_compile_two_month_sql_and_cannot_execute_on_sqlite(): void
    {
        $this->assertStringContainsString('tanggal_keluar >= DATE_ADD(tanggal_masuk, INTERVAL 2 MONTH)', Fp::keluarNormal()->toSql());
        $this->assertStringContainsString('tanggal_keluar < DATE_ADD(tanggal_masuk, INTERVAL 2 MONTH)', Fp::keluarTidakNormal()->toSql());
        $this->expectException(\Illuminate\Database\QueryException::class);
        Fp::keluarNormal()->count();
    }

    public function test_team_delete_cascades_legacy_fp(): void
    {
        $fp = Fp::create($this->attributes());
        $fp->team->delete();
        $this->assertDatabaseMissing('fps', ['id' => $fp->id]);
    }
}
