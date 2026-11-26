<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\FP\FeatureAccess;
use App\Infrastructure\FP\DocumentStorage;
use App\Infrastructure\FP\DocumentStorageRegistry;
use App\Jobs\FP\PublishAmendedFpDocuments as PublishJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Operator retry for a latest amendment whose Nextcloud publication was interrupted. */
final class FpPublishAmendedDocuments extends Command
{
    protected $signature = 'fp:publish-amended-documents {amendment : Amendment ID}';

    protected $description = 'Retry publishing the latest approved FP document amendment to Nextcloud';

    public function handle(DocumentStorageRegistry $registry, DocumentStorage $storage): int
    {
        try {
            FeatureAccess::requireEnabled();
        } catch (Throwable $error) {
            $this->line('FP_DISABLED: '.get_class($error));

            return self::FAILURE;
        }
        if (config('fp.storage_adapter') !== 'nextcloud') {
            $this->line('SKIPPED_LOCAL_ADAPTER');

            return self::SUCCESS;
        }
        $id = (int) $this->argument('amendment');
        if (! DB::table('fp_document_amendments')->where('id', $id)->exists()) {
            $this->line('AMENDMENT_NOT_FOUND');

            return self::FAILURE;
        }
        try {
            (new PublishJob($id))->handle($registry, $storage);
        } catch (Throwable $error) {
            Log::warning('FP Nextcloud amendment publication retry failed', [
                'amendment_id' => $id,
                'error' => get_class($error),
            ]);
            $this->line('FAILED: '.get_class($error));

            return self::FAILURE;
        }
        $this->line('PUBLISHED');

        return self::SUCCESS;
    }
}
