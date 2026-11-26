<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\FP\ReportFilters;
use App\Filament\FP\ApprovalSourceForm;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class FinalRevisionTest extends TestCase
{
    public function test_approval_source_is_limited_to_mm_and_one_source_master(): void
    {
        $valid = ['recruitment_path_id' => 7, 'approval_mm_name_snapshot' => 'MM Synthetic'];
        self::assertSame(['approval_mm_name_snapshot' => 'MM Synthetic', 'recruitment_path_id' => 7, 'source_type' => 'HRD', 'source_fp_id' => null, 'source_team_id' => null], ApprovalSourceForm::source($valid));
        $injected = ApprovalSourceForm::source($valid + ['source_fp_id' => 11, 'reference_type' => 'TEAM', 'source_team_id' => 9]);
        self::assertSame('HRD', $injected['source_type']);
        self::assertNull($injected['source_fp_id']);
        self::assertNull($injected['source_team_id']);
    }

    public function test_invalid_source_master_or_missing_mm_fails_validation(): void
    {
        foreach ([['recruitment_path_id' => 0], ['approval_mm_name_snapshot' => '']] as $bad) {
            try {
                ApprovalSourceForm::source($bad + ['recruitment_path_id' => 7, 'approval_mm_name_snapshot' => 'MM Synthetic']);
                self::fail('Invalid source accepted');
            } catch (\Illuminate\Validation\ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_report_reference_basis_is_explicit_and_old_exit_basis_is_rejected(): void
    {
        $valid = ReportFilters::validate(['status' => 'all']);
        self::assertSame('reference_date', $valid['date_field']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        ReportFilters::validate(['status' => 'all', 'date_field' => 'exit_date']);
    }

    public function test_approval_has_only_mm_and_source_master_fields(): void
    {
        config(['fp.recruitment_team_enabled' => false]);
        $fields = \Filament\Forms\Form::make(new \App\Filament\Resources\FpSubmissionResource\Pages\ListFpSubmissions)->schema(ApprovalSourceForm::fields())->getComponents(true);
        self::assertCount(2, $fields);
        self::assertSame('Nama MM', $fields[0]->getLabel());
        self::assertSame('approval_mm_name_snapshot', $fields[0]->getName());
        self::assertTrue($fields[0]->isRequired());
        self::assertSame('Sumber Rekrutmen', $fields[1]->getLabel());
        self::assertSame('recruitment_path_id', $fields[1]->getName());
        self::assertTrue($fields[1]->isRequired());
    }

    public function test_active_path_lookup_returns_ids_and_name_only_master_labels(): void
    {
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        Schema::create('fp_recruitment_paths', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code');
            $t->boolean('is_active');
        });
        try {
            DB::table('fp_recruitment_paths')->insert([['id' => 1, 'name' => 'Referral', 'code' => 'INTERNAL_SECRET', 'is_active' => true], ['id' => 2, 'name' => 'Inactive', 'code' => 'OTHER', 'is_active' => false]]);
            self::assertSame([1 => 'Referral'], ApprovalSourceForm::paths());
            self::assertSame([], ApprovalSourceForm::paths('INTERNAL_SECRET'));
        } finally {
            Schema::drop('fp_recruitment_paths');
        }
    }
}
