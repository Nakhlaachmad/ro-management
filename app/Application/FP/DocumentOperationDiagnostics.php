<?php

declare(strict_types=1);

namespace App\Application\FP;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

final class DocumentOperationDiagnostics
{
    public const PER_PAGE = 25;

    private const STATES = ['PENDING', 'UPLOADING', 'UPLOADED', 'VERIFYING', 'COMMITTED', 'FAILED', 'QUARANTINED'];

    private const ERRORS = ['REMOTE_MISSING', 'REMOTE_UNKNOWN', 'AUTHENTICATION_FAILED', 'INTEGRITY_MISMATCH', 'REVISION_CONFLICT', 'STAGING_INVALID', 'AUTHORIZATION_DENIED', 'COMMIT_UNCERTAIN'];

    public static function allowed(?User $actor): bool
    {
        return FeatureAccess::enabled() && VictoryAccess::role($actor) === 'admin';
    }

    public function snapshot(?User $actor, int $page = 1): array
    {
        abort_unless(self::allowed($actor), 403);
        abort_if($page < 1 || $page > 100000, 422);

        try {
            foreach (['fp_document_operations', 'fp_document_operation_events', 'fp_document_amendments', 'financial_planners'] as $table) {
                if (! Schema::hasTable($table)) {
                    return $this->emptySnapshot('NOT_INSTALLED', $page);
                }
            }

            $reconciliation = DB::table('fp_document_operation_events')
                ->select('operation_uuid')->selectRaw('MAX(occurred_at) AS reconciled_at')
                ->where('action', 'RECONCILED')->groupBy('operation_uuid');

            $rows = DB::table('fp_document_operations as operation')
                ->join('financial_planners as membership', 'membership.id', '=', 'operation.membership_id')
                ->leftJoin('fp_document_amendments as revision', 'revision.operation_uuid', '=', 'operation.uuid')
                ->leftJoinSub($reconciliation, 'reconciliation', 'reconciliation.operation_uuid', '=', 'operation.uuid')
                ->select([
                    'operation.uuid as operation_uuid', 'membership.uuid as membership_uuid',
                    'operation.type', 'operation.revision_uuid', 'operation.state',
                    'operation.bytes as expected_size', 'operation.error_code',
                    'operation.created_at', 'operation.lease_until', 'reconciliation.reconciled_at',
                ])
                ->selectRaw("CASE WHEN operation.state='COMMITTED'
                    AND revision.uuid=operation.revision_uuid
                    AND revision.membership_id=operation.membership_id
                    AND revision.type=operation.type
                    AND revision.revision=operation.target_revision
                    AND revision.storage_adapter=operation.storage_adapter
                    AND revision.private_path=operation.private_path
                    AND revision.mime=operation.mime
                    AND revision.bytes=operation.bytes
                    AND revision.sha256=operation.sha256
                    AND revision.integrity_verified_at IS NOT NULL
                    AND revision.integrity_verified_at=operation.verified_at
                    THEN revision.bytes ELSE NULL END AS verified_size")
                ->orderByDesc('operation.created_at')->orderBy('operation.uuid')
                ->paginate(self::PER_PAGE, ['*'], 'page', $page);

            $now = CarbonImmutable::now('UTC');
            $rows->setCollection($rows->getCollection()->map(fn (object $row): array => $this->project($row, $now)));

            return ['status' => 'AVAILABLE', 'rows' => $rows];
        } catch (QueryException) {
            // Driver errors can contain connection details. The view receives a fixed state only.
            return $this->emptySnapshot('UNAVAILABLE', $page);
        }
    }

    private function emptySnapshot(string $status, int $page): array
    {
        return ['status' => $status, 'rows' => new LengthAwarePaginator([], 0, self::PER_PAGE, $page)];
    }

    private function project(object $row, CarbonImmutable $now): array
    {
        $state = in_array($row->state, self::STATES, true) ? $row->state : 'UNKNOWN';
        $error = $row->error_code === null ? null : (in_array($row->error_code, self::ERRORS, true) ? $row->error_code : 'UNCLASSIFIED');
        $verifiedSize = $row->verified_size === null ? null : (int) $row->verified_size;
        $integrity = $verifiedSize !== null ? 'VERIFIED' : (($state === 'COMMITTED' || $error === 'INTEGRITY_MISMATCH') ? 'WARNING' : 'PENDING');
        $nonterminal = in_array($state, ['PENDING', 'UPLOADING', 'UPLOADED', 'VERIFYING'], true);
        $staleAt = $row->lease_until !== null
            ? CarbonImmutable::parse($row->lease_until, 'UTC')
            : CarbonImmutable::parse($row->created_at, 'UTC')->addSeconds(120);
        $membershipUuid = $this->opaqueUuid($row->membership_uuid);
        $documentUuid = $membershipUuid !== null && in_array($row->type, ['KTP', 'IJAZAH'], true)
            ? Uuid::uuid5($membershipUuid, $row->type)->toString()
            : null;

        return [
            'operation_uuid' => $this->opaqueUuid($row->operation_uuid),
            'document_uuid' => $documentUuid,
            'revision_uuid' => $this->opaqueUuid($row->revision_uuid),
            'state' => $state,
            'integrity_state' => $integrity,
            'expected_size' => (int) $row->expected_size,
            'verified_size' => $verifiedSize,
            'error_code' => $error,
            'last_reconciled_at' => $row->reconciled_at === null ? null : CarbonImmutable::parse($row->reconciled_at, 'UTC')->format('Y-m-d H:i:s').' UTC',
            'stale' => $nonterminal && $staleAt->isBefore($now),
            'quarantined' => $state === 'QUARANTINED',
        ];
    }

    private function opaqueUuid(?string $value): ?string
    {
        return $value !== null && Uuid::isValid($value) ? $value : null;
    }
}
