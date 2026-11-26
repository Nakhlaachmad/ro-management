<?php

declare(strict_types=1);

namespace App\Jobs\FP;

use App\Application\FP\FeatureAccess;
use App\Infrastructure\FP\DocumentStorage;
use App\Infrastructure\FP\DocumentStorageRegistry;
use App\Infrastructure\FP\FpDocumentPath;
use App\Infrastructure\FP\NextcloudStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Publishes the latest approved FP document amendment to the readable Nextcloud folder. */
final class PublishAmendedFpDocuments implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 5;

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function __construct(public int $amendmentId)
    {
        $this->afterCommit();
    }

    public function handle(DocumentStorageRegistry $registry, DocumentStorage $storage): void
    {
        FeatureAccess::requireEnabled();
        if (config('fp.storage_adapter') !== 'nextcloud') {
            return;
        }
        $amendment = DB::table('fp_document_amendments')->where('id', $this->amendmentId)->first();
        if (! $amendment) {
            return;
        }
        $latest = DB::table('fp_document_amendments')
            ->where('membership_id', $amendment->membership_id)
            ->where('type', $amendment->type)
            ->max('revision');
        // Older revisions remain private history; they never replace the readable copy.
        if ((int) $amendment->revision !== (int) $latest) {
            return;
        }
        $sub = DB::table('fp_submissions')->where('id', $amendment->submission_id)->first();
        $member = DB::table('financial_planners')->where('id', $amendment->membership_id)->first();
        if (! $sub || $sub->status !== 'APPROVED' || ! $member
            || (int) $member->submission_id !== (int) $amendment->submission_id || $member->nip === null) {
            return;
        }
        try {
            $remote = $registry->forAdapter('nextcloud', $storage);
            if (! $remote instanceof NextcloudStorage) {
                return;
            }
            if ($amendment->storage_adapter !== 'local') {
                throw new RuntimeException('Unsupported amendment adapter');
            }
            $local = $registry->forAdapter('local', $storage);
            $bytes = $local->read((string) $amendment->private_path);
            if (strlen($bytes) !== (int) $amendment->bytes
                || ! hash_equals((string) $amendment->sha256, hash('sha256', $bytes))) {
                throw new RuntimeException('Amended FP document integrity mismatch');
            }
            $folder = FpDocumentPath::approvedFolder((string) ($sub->full_name ?? $member->full_name), (string) $member->nip);
            $remote->putCurrent(FpDocumentPath::friendlyForFolder($folder, (string) $amendment->type), $bytes);
        } catch (\Throwable $error) {
            Log::warning('FP Nextcloud amendment publication failed', [
                'amendment_id' => $this->amendmentId,
                'membership_id' => (int) $amendment->membership_id,
                'type' => (string) $amendment->type,
                'revision' => (int) $amendment->revision,
                'error' => get_class($error),
            ]);
            throw $error;
        }
    }
}
