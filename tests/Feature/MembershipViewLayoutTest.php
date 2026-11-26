<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\FpMembershipResource\Pages\ViewFpMembership;
use App\Models\FpDocument;
use App\Models\FpMembership;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

final class MembershipViewLayoutTest extends TestCase
{
    private function page(string $role = 'hrd'): ViewFpMembership
    {
        config(['fp.enabled' => true, 'fp.core_schema_only' => false]);
        auth()->setUser(\Tests\VictoryAccess\RoleMatrixTest::actor([$role]));
        $record = new FpMembership;
        $record->forceFill(['id' => 91, 'origin' => 'LEGACY', 'lifecycle_state' => null, 'nip' => 1001]);
        $record->setRelation('documents', new Collection);
        $record->setRelation('submission', null);
        $page = new ViewFpMembership;
        $page->record = $record;

        return $page;
    }

    public function test_missing_legacy_documents_are_safe_and_nip_is_not_changed(): void
    {
        $page = $this->page();
        self::assertSame('Detail Financial Planner', $page->getTitle());
        self::assertFalse($page->canManageInlineDocuments());
        foreach (['PROFILE_PHOTO', 'KTP', 'IJAZAH'] as $type) {
            self::assertNull($page->getInlineDocument($type));
        }
        self::assertSame(1001, $page->getRecord()->nip);
        self::assertNull($page->getRecord()->lifecycle_state);
        $actions = (new \ReflectionMethod($page, 'getHeaderActions'))->invoke($page);
        self::assertSame(['documents'], array_map(fn ($action) => $action->getName(), $actions));
        self::assertFalse($actions[0]->isVisible());
    }

    public function test_all_four_roles_view_only_current_membership_ready_documents(): void
    {
        foreach (['admin', 'hrd', 'ro', 'bm'] as $role) {
            $page = $this->page($role);
            $docs = new Collection;
            foreach ([[1, 91, 'READY', 'NONE'], [2, 92, 'READY', 'NONE'], [3, 91, 'FAILED', 'NONE'], [4, 91, 'READY', 'REQUESTED']] as [$id, $owner, $state, $deleted]) {
                $doc = new FpDocument;
                $doc->forceFill(['id' => $id, 'membership_id' => $owner, 'type' => 'KTP', 'processing_state' => $state, 'deletion_state' => $deleted]);
                $docs->push($doc);
            }
            $page->getRecord()->setRelation('documents', $docs);
            self::assertSame(1, $page->getInlineDocument('KTP')->id);
            self::assertNull($page->getInlineDocument('PROFILE_PHOTO'));
            auth()->forgetUser();
            self::assertNull($page->getInlineDocument('KTP'));
        }
    }

    public function test_bm_cannot_mount_mutation_on_membership_page(): void
    {
        $page = $this->page('bm');
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $page->mountAction('approve');
    }
}
