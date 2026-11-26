<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\FpSubmissionResource\Pages\ViewFpSubmission;
use App\Models\FpDocument;
use App\Models\FpMembership;
use App\Models\FpSubmission;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

final class InlineSubmissionReviewTest extends TestCase
{
    private function page(string $role, string $status = 'PENDING', bool $verified = true, bool $optional = false): ViewFpSubmission
    {
        config(['fp.enabled' => true, 'fp.core_schema_only' => false, 'fp.optional_onboarding_enabled' => $optional]);
        auth()->setUser(\Tests\VictoryAccess\RoleMatrixTest::actor([$role]));
        $submission = new FpSubmission;
        $submission->forceFill(['id' => 12, 'status' => $status, 'biodata_revision' => 2, 'document_revision' => 2, 'biodata_verified_revision' => $verified ? 2 : 1, 'documents_verified_revision' => 2]);
        $submission->setRelation('documents', new Collection);
        if ($status === 'APPROVED') {
            $submission->setRelation('membership', new FpMembership(['id' => 77]));
        }
        $page = new ViewFpSubmission;
        $page->record = $submission;
        $method = new \ReflectionMethod($page, 'getHeaderActions');
        $actions = $method->invoke($page);
        (new \ReflectionProperty($page, 'cachedHeaderActions'))->setValue($page, $actions);

        return $page;
    }

    public function test_operator_actions_keep_confirmation_and_revision_guard_without_duplicates(): void
    {
        foreach (['admin', 'hrd', 'ro'] as $role) {
            $page = $this->page($role);
            self::assertSame('Detail Pengajuan FP', $page->getTitle());
            self::assertSame('Menunggu Validasi', $page->getSubheading());
            $actions = $page->getApprovalBarActions();
            self::assertSame(['approve', 'reject'], array_map(fn ($action) => $action->getName(), $actions));
            foreach ($actions as $action) {
                self::assertTrue($action->shouldOpenModal(fn () => false));
                self::assertNotContains($action->getName(), array_map(fn ($header) => $header->getName(), $page->getCachedHeaderActions()));
            }
            self::assertNotContains('documents', array_map(fn ($header) => $header->getName(), $page->getCachedHeaderActions()));
            $headerActions = (new \ReflectionMethod($page, 'getHeaderActions'))->invoke($page);
            self::assertNotContains('amendDocument', array_map(fn ($header) => $header->getName(), $headerActions));
            self::assertFalse($actions[0]->isDisabled());
            self::assertSame('danger', $actions[1]->getColor());
            self::assertTrue($this->page($role, verified: false)->getApprovalBarActions()[0]->isDisabled());
        }
    }

    public function test_bm_and_final_status_have_no_mutation_bar(): void
    {
        self::assertSame([], $this->page('bm')->getApprovalBarActions());
        foreach (['APPROVED', 'REJECTED'] as $status) {
            self::assertSame([], $this->page('hrd', $status)->getApprovalBarActions());
        }
        $page = $this->page('bm');
        try {
            $page->mountAction('approve');
            self::fail('BM direct action must be denied');
        } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
            self::assertSame(403, $this->app[\Illuminate\Contracts\Debug\ExceptionHandler::class]->render(\Illuminate\Http\Request::create('/fp-dev'), $exception)->getStatusCode());
        }
    }

    public function test_card_upload_is_available_to_document_managers_before_and_after_approval(): void
    {
        foreach (['admin', 'hrd', 'ro', 'bm'] as $role) {
            $allowed = in_array($role, ['admin', 'hrd'], true);
            self::assertSame($allowed, $this->page($role, optional: true)->canManageInlineDocuments(), $role);
            self::assertSame($allowed, $this->page($role, 'APPROVED', optional: true)->canManageInlineDocuments(), $role);
        }
        self::assertFalse($this->page('hrd', 'REJECTED', optional: true)->canManageInlineDocuments());
    }

    public function test_inline_document_needs_permission_current_revision_ready_and_not_deleted(): void
    {
        $page = $this->page('hrd');
        $documents = new Collection;
        foreach ([[1, 'READY', 'NONE', 2], [2, 'READY', 'NONE', 1], [3, 'PROCESSING', 'NONE', 2], [4, 'READY', 'REQUESTED', 2]] as [$id, $state, $deleted, $revision]) {
            $document = new FpDocument;
            $document->forceFill(['id' => $id, 'type' => 'KTP', 'processing_state' => $state, 'deletion_state' => $deleted, 'revision' => $revision]);
            $documents->push($document);
        }
        $page->getRecord()->setRelation('documents', $documents);
        self::assertSame(1, $page->getInlineDocument('KTP')->id);
        self::assertNull($page->getInlineDocument('IJAZAH'));
        auth()->forgetUser();
        self::assertNull($page->getInlineDocument('KTP'));
    }
}
