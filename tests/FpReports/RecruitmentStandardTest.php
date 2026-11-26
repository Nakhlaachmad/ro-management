<?php

declare(strict_types=1);

namespace Tests\FpReports;

use App\Application\FP\RecruitmentCsvColumns;
use App\Application\FP\RecruitmentPresentation;
use App\Application\FP\ReportExport;
use App\Models\FpMembership;
use Tests\TestCase;

final class RecruitmentStandardTest extends TestCase
{
    public function test_snapshots_and_typed_source_are_preserved_in_one_display(): void
    {
        $fp = new FpMembership;
        $fp->forceFill(['recruitment_path_code_snapshot' => 'A', 'recruitment_path_name_snapshot' => 'Reguler / Umum', 'source_type' => 'TEAM', 'source_name_snapshot' => 'Victory Team']);
        $before = $fp->getAttributes();
        self::assertSame('A — Reguler / Umum / TEAM · Victory Team', RecruitmentPresentation::label($fp));
        self::assertSame($before, $fp->getAttributes());
        $fp->source_type = 'HRD';
        $fp->source_name_snapshot = 'HRD';
        self::assertSame('A — Reguler / Umum / HRD', RecruitmentPresentation::label($fp));
    }

    public function test_legacy_and_pending_are_never_fabricated(): void
    {
        self::assertNull(RecruitmentPresentation::label(null));
        $fp = new FpMembership;
        self::assertSame('Belum tersedia', RecruitmentPresentation::label($fp));
        $fp->legacy_source_raw = 'MGM';
        self::assertSame('MGM (legacy / belum direkonsiliasi)', RecruitmentPresentation::label($fp));
        self::assertNull($fp->nip);
    }

    public function test_csv_old_new_aliases_and_equal_columns_resolve_once(): void
    {
        foreach (['Jalur Rekrutmen', 'Sumber Rekrutmen', 'jalur_rekrutmen', 'sumber_rekrutmen', 'Jalur'] as $header) {
            self::assertSame('A', RecruitmentCsvColumns::resolve([$header => 'A']));
        }
        self::assertSame('A', RecruitmentCsvColumns::resolve(['Jalur Rekrutmen' => ' A ', 'Sumber Rekrutmen' => 'A']));
        self::assertNull(RecruitmentCsvColumns::resolve([]));
        self::assertNull(RecruitmentCsvColumns::resolve(['Sumber Rekrutmen' => '']));
    }

    public function test_csv_conflicts_are_rejected_without_silent_overwrite(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        RecruitmentCsvColumns::resolve(['Jalur Rekrutmen' => 'A', 'Sumber Rekrutmen' => 'HRD']);
    }

    public function test_export_has_one_recruitment_column(): void
    {
        self::assertCount(9, ReportExport::HEADERS);
        self::assertSame('Sumber Rekrutmen', ReportExport::HEADERS[4]);
        self::assertSame(1, count(array_filter(ReportExport::HEADERS, fn ($header) => str_contains($header, 'Rekrutmen'))));
    }

    public function test_approval_has_only_mm_and_source_fields(): void
    {
        $components = \App\Filament\FP\FpForms::source();
        self::assertCount(2, $components);
        self::assertSame('Nama MM', $components[0]->getLabel());
        self::assertSame('approval_mm_name_snapshot', $components[0]->getName());
        self::assertSame('Sumber Rekrutmen', $components[1]->getLabel());
        self::assertSame('recruitment_path_id', $components[1]->getName());
        foreach (['HRD' => ['7:HRD:0', null, null], 'TEAM' => ['7:TEAM:9', 9, null], 'FP' => ['7:FP:11', null, 11]] as $type => [$choice, $team, $fp]) {
            self::assertSame(['recruitment_path_id' => 7, 'source_type' => $type, 'source_team_id' => $team, 'source_fp_id' => $fp], \App\Filament\FP\RecruitmentChoice::decode($choice));
        }
    }

    public function test_report_row_uses_same_display_without_mutation(): void
    {
        $fp = new FpMembership;
        $fp->forceFill(['id' => 7, 'full_name' => 'Synthetic', 'nip' => 1001, 'lifecycle_state' => null, 'source_type' => 'HRD', 'source_name_snapshot' => 'HRD', 'recruitment_path_code_snapshot' => 'A', 'recruitment_path_name_snapshot' => 'Reguler / Umum']);
        $fp->setRelation('team', null);
        $fp->setRelation('exit', null);
        $before = $fp->getAttributes();
        $row = \App\Application\FP\FpReports::row($fp);
        self::assertCount(count(ReportExport::HEADERS), $row);
        self::assertSame(RecruitmentPresentation::label($fp), $row[4]);
        self::assertSame(1001, $row[2]);
        self::assertSame('Belum terklasifikasi', $row[5]);
        self::assertSame($before, $fp->getAttributes());
    }
}
