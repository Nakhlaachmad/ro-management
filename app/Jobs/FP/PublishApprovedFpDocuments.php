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

/** Publishes approved documents into the operator's readable Nextcloud folder. */
final class PublishApprovedFpDocuments implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 5;

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function __construct(public int $submissionId, public int $membershipId)
    {
        $this->afterCommit();
    }

    public function handle(DocumentStorageRegistry $registry, DocumentStorage $storage): void
    {
        FeatureAccess::requireEnabled();
        if (config('fp.storage_adapter') !== 'nextcloud') {
            return;
        }
        $sub = DB::table('fp_submissions')->where('id', $this->submissionId)->first();
        $member = DB::table('financial_planners')->where('id', $this->membershipId)->first();
        if (! $sub || $sub->status !== 'APPROVED' || ! $member
            || (int) $member->submission_id !== $this->submissionId || $member->nip === null) {
            return;
        }
        try {
            $remote = $registry->forAdapter('nextcloud', $storage);
            if (! $remote instanceof NextcloudStorage) {
                return;
            }
            $local = $registry->forAdapter('local', $storage);
            // Use the submitted biodata name so later membership edits do not move the folder.
            $folder = FpDocumentPath::approvedFolder((string) ($sub->full_name ?? $member->full_name), (string) $member->nip);
            $documents = DB::table('fp_documents')
                ->where('submission_id', $this->submissionId)
                ->where('membership_id', $this->membershipId)
                ->orderBy('id')
                ->get();
            foreach ($documents as $document) {
                if ($document->processing_state !== 'READY' || $document->deletion_state !== 'NONE') {
                    continue;
                }
                $bytes = $local->read((string) $document->private_path);
                if (strlen($bytes) !== (int) $document->bytes
                    || ! hash_equals((string) $document->sha256, hash('sha256', $bytes))) {
                    throw new RuntimeException('Staged FP document integrity mismatch');
                }
                $remote->putOnce(FpDocumentPath::friendlyForFolder($folder, (string) $document->type), $bytes);
            }
        } catch (\Throwable $error) {
            Log::warning('FP Nextcloud publication failed', [
                'submission_id' => $this->submissionId,
                'membership_id' => $this->membershipId,
                'error' => get_class($error),
            ]);
            throw $error;
        }
    }
}
