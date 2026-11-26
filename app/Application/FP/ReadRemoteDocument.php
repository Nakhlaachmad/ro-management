<?php

declare(strict_types=1);

namespace App\Application\FP;

use App\Infrastructure\FP\NextcloudRevisionStorage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Reads committed remote revisions without changing the application's provider. */
final class ReadRemoteDocument
{
    public function __construct(private NextcloudRevisionStorage $storage) {}

    /** @return array{bytes: string, mime: string, filename: string} */
    public function read(User $actor, string $documentUuid, string $revisionUuid): array
    {
        FeatureAccess::require($actor, 'f2.documents.view');
        abort_unless(Uuid::isValid($documentUuid) && Uuid::isValid($revisionUuid), 404);

        try {
            $revision = DB::table('fp_document_amendments as revision')
                ->join('financial_planners as membership', 'membership.id', '=', 'revision.membership_id')
                ->join('fp_document_operations as operation', 'operation.uuid', '=', 'revision.operation_uuid')
                ->where('revision.uuid', $revisionUuid)
                ->where('operation.operation_type', 'ADMIN_DOCUMENT_AMENDMENT')
                ->where('operation.state', 'COMMITTED')
                ->where('revision.storage_adapter', 'nextcloud')
                ->where('operation.storage_adapter', 'nextcloud')
                ->whereIn('revision.type', ['KTP', 'IJAZAH'])
                ->whereNotNull('revision.integrity_verified_at')
                ->whereNotNull('operation.verified_at')
                ->whereNotNull('operation.committed_at')
                ->whereColumn([
                    ['revision.uuid', 'operation.revision_uuid'],
                    ['revision.membership_id', 'operation.membership_id'],
                    ['revision.type', 'operation.type'],
                    ['revision.revision', 'operation.target_revision'],
                    ['revision.actor_id', 'operation.actor_id'],
                    ['revision.private_path', 'operation.private_path'],
                    ['revision.mime', 'operation.mime'],
                    ['revision.bytes', 'operation.bytes'],
                    ['revision.sha256', 'operation.sha256'],
                    ['revision.integrity_verified_at', 'operation.verified_at'],
                ])
                ->whereRaw('operation.target_revision = operation.expected_revision + 1')
                ->whereRaw('(revision.submission_id = membership.submission_id OR (revision.submission_id IS NULL AND membership.submission_id IS NULL))')
                ->first(['revision.uuid', 'revision.type', 'revision.mime', 'revision.bytes', 'revision.sha256', 'revision.private_path', 'membership.uuid as membership_uuid']);
        } catch (QueryException) {
            abort(503, 'Layanan dokumen privat belum tersedia.');
        }

        abort_unless($revision && Uuid::isValid($revision->membership_uuid), 404);
        $expectedDocument = Uuid::uuid5($revision->membership_uuid, $revision->type)->toString();
        abort_unless(hash_equals($expectedDocument, strtolower($documentUuid)), 404);
        $expectedPath = 'documents/'.$revision->membership_uuid.'/'.$expectedDocument.'/revision-'.$revision->uuid.'/document.bin';
        abort_unless(hash_equals($expectedPath, $revision->private_path), 404);

        abort_unless(in_array($revision->mime, ['image/jpeg', 'image/png'], true)
            && (int) $revision->bytes > 0 && (int) $revision->bytes <= 8 * 1024 * 1024
            && preg_match('/\A[0-9a-f]{64}\z/', $revision->sha256), 503);

        try {
            $bytes = $this->storage->read($revision->private_path);
        } catch (\Throwable $error) {
            abort($error->getMessage() === 'NEXTCLOUD_OBJECT_MISSING' ? 404 : 503, 'Layanan dokumen privat belum tersedia.');
        }

        abort_unless(strlen($bytes) === (int) $revision->bytes
            && hash_equals($revision->sha256, hash('sha256', $bytes)), 503);
        $image = @getimagesizefromstring($bytes);
        $actualMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $signatureMatches = $revision->mime === 'image/jpeg'
            ? str_starts_with($bytes, "\xff\xd8\xff")
            : str_starts_with($bytes, "\x89PNG\r\n\x1a\n");
        abort_unless($signatureMatches && $image !== false
            && ($image['mime'] ?? null) === $revision->mime && $actualMime === $revision->mime, 503);

        return ['bytes' => $bytes, 'mime' => $revision->mime, 'filename' => $revision->mime === 'image/png' ? 'document.png' : 'document.jpg'];
    }
}
