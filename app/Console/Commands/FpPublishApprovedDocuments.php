<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\FP\FeatureAccess;
use App\Infrastructure\FP\DocumentStorage;
use App\Infrastructure\FP\DocumentStorageRegistry;
use App\Jobs\FP\PublishApprovedFpDocuments as PublishJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Operator retry for an approved FP whose Nextcloud publication was interrupted. */
final class FpPublishApprovedDocuments extends Command
{
    protected $signature = 'fp:publish-approved-documents {submission : Submission ID or UUID}';

    protected $description = 'Retry publishing an approved FP document set to Nextcloud';

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
        $key = (string) $this->argument('submission');
        $query = DB::table('fp_submissions');
        $sub = Str::isUuid($key) ? $query->where('uuid', $key)->first() : (ctype_digit($key) ? $query->where('id', (int) $key)->first() : null);
        if (! $sub) {
            $this->line('SUBMISSION_NOT_FOUND');

            return self::FAILURE;
        }
        if ($sub->status !== 'APPROVED') {
            $this->line('NOT_APPROVED');

            return self::FAILURE;
        }
        $member = DB::table('financial_planners')->where('submission_id', $sub->id)->orderByDesc('id')->first();
        if (! $member) {
            $this->line('MEMBERSHIP_NOT_FOUND');

            return self::FAILURE;
        }
        try {
            (new PublishJob((int) $sub->id, (int) $member->id))->handle($registry, $storage);
        } catch (Throwable $error) {
            Log::warning('FP Nextcloud publication retry failed', [
                'submission_id' => (int) $sub->id,
                'membership_id' => (int) $member->id,
                'error' => get_class($error),
            ]);
            $this->line('FAILED: '.get_class($error));

            return self::FAILURE;
        }
        $this->line('PUBLISHED');

        return self::SUCCESS;
    }
}
