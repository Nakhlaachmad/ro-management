<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\FP\RecruitmentPresentation;
use App\Application\FP\RecruitmentTeamSource;
use App\Filament\FP\ApprovalSourceForm;
use App\Models\FpMembership;
use Tests\TestCase;

final class Phase3ContractsTest extends TestCase
{
    public function test_v2_source_ignores_client_selected_reference_semantics(): void
    {
        config(['fp.recruitment_team_enabled' => true]);
        self::assertSame(['recruitment_team_id' => 8, 'source_type' => 'TEAM', 'source_fp_id' => null, 'source_team_id' => 8], RecruitmentTeamSource::normalize(['recruitment_team_id' => 8, 'reference_type' => 'HRD', 'source_team_id' => 999]));
        self::assertSame(['recruitment_team_id' => 8, 'source_type' => 'FP', 'source_fp_id' => 4, 'source_team_id' => null], RecruitmentTeamSource::normalize(['recruitment_team_id' => 8, 'source_fp_id' => 4, 'source_type' => 'TEAM']));
        $names = array_map(fn ($field) => $field->getName(), ApprovalSourceForm::fields());
        self::assertSame(['approval_mm_name_snapshot', 'recruitment_team_id'], $names);
        self::assertNotContains('source_fp_id', $names);
        self::assertNotContains('approval_summary', $names);
        self::assertNotContains('reference_type', $names);
        self::assertNotContains('recruitment_path_id', $names);
    }

    public function test_v2_requires_positive_canonical_team_id(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        RecruitmentTeamSource::normalize(['recruitment_team_id' => 0]);
    }

    public function test_historical_snapshot_and_v2_team_name_are_separate(): void
    {
        $historical = new FpMembership(['recruitment_path_code_snapshot' => 'A', 'recruitment_path_name_snapshot' => 'Historical master', 'recruitment_contract_version' => 1]);
        self::assertStringContainsString('Historical master', RecruitmentPresentation::label($historical));
        $v2 = new FpMembership(['recruitment_contract_version' => 2, 'recruitment_team_name_snapshot' => 'Team Alpha']);
        self::assertSame('Team Alpha', RecruitmentPresentation::label($v2));
    }

    public function test_source_filter_names_do_not_reinterpret_historical_master_ids(): void
    {
        $filters = \App\Application\FP\ReportFilters::validate(['status' => 'all', 'path_id' => 7, 'recruitment_team_id' => 9]);
        self::assertEquals(7, $filters['path_id']);
        self::assertEquals(9, $filters['recruitment_team_id']);
    }

    public function test_bm_inactive_and_unrecognized_roles_cannot_invoke_new_services(): void
    {
        config(['fp.enabled' => true, 'fp.core_schema_only' => false, 'fp.organization_enabled' => true]);
        foreach (['bm', 'inactive', 'unrecognized'] as $role) {
            $user = new \App\Models\User(['is_active' => $role !== 'inactive']);
            $user->id = 99;
            $user->setRelation('roles', new \Illuminate\Database\Eloquent\Collection([new \Spatie\Permission\Models\Role(['name' => $role === 'inactive' ? 'hrd' : $role, 'guard_name' => 'web'])]));
            try {
                app(\App\Application\FP\Organization::class)->command($user, 'CREATE_TEAM', []);
                self::fail('Unauthorized service reached database');
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
                self::assertTrue(true);
            }
        }
    }
}
