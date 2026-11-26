<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class PremiumOperationsTest extends TestCase
{
    public function test_master_code_is_internal_unique_and_edit_fields_do_not_accept_it(): void
    {
        $codes = [];
        for ($i = 0; $i < 30; $i++) {
            $code = \App\Application\FP\RecruitmentPaths::internalCode();
            self::assertMatchesRegularExpression('/^R[A-F0-9]{16}$/', $code);
            $codes[] = $code;
        }
        self::assertCount(30, array_unique($codes));
        foreach ([true, false] as $create) {
            $fields = \App\Filament\Resources\FpRecruitmentPathResource::fields($create);
            self::assertNotContains('code', array_map(fn ($field) => $field->getName(), $fields));
        }
    }

    public function test_computed_source_sort_is_server_side_stable_and_does_not_join_rows(): void
    {
        $query = \App\Filament\FP\MembershipSorting::recruitment(\App\Models\FpMembership::query(), 'desc');
        self::assertStringContainsString('"recruitment_path_code_snapshot" desc', $query->toSql());
        self::assertStringContainsString('"financial_planners"."id" desc', $query->toSql());
        self::assertStringNotContainsString('join', $query->toSql());
        $this->expectException(\InvalidArgumentException::class);
        \App\Filament\FP\MembershipSorting::recruitment($query, 'desc;DROP');
    }

    public function test_session_read_state_detects_new_or_changed_notifications(): void
    {
        $before = [1 => ['PENDING', 1, 1, null, null]];
        self::assertSame([], \App\Filament\FP\SubmissionUpdates::changes($before, $before));
        $current = [1 => ['APPROVED', 1, 1, 1, 1], 2 => ['PENDING', 1, 1, null, null]];
        self::assertSame($current, \App\Filament\FP\SubmissionUpdates::changes($before, $current));
        self::assertSame([], \App\Filament\FP\SubmissionUpdates::changes($current, $current));
    }

    public function test_guest_cannot_mark_notification_read(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new \App\Filament\FP\SubmissionUpdates)->markRead();
    }

    public function test_membership_and_submission_infolists_build_without_table_only_methods(): void
    {
        $membership = \App\Filament\Resources\FpMembershipResource::infolist(\Filament\Infolists\Infolist::make(new \App\Filament\Resources\FpMembershipResource\Pages\ViewFpMembership));
        $submission = \App\Filament\Resources\FpSubmissionResource::infolist(\Filament\Infolists\Infolist::make(new \App\Filament\Resources\FpSubmissionResource\Pages\ViewFpSubmission));
        self::assertCount(3, $membership->getComponents());
        self::assertCount(4,$submission->getComponents());
    }
}
