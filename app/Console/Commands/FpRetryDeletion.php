<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class FpRetryDeletion extends Command
{
    protected $signature = 'fp:retry-deletions';

    protected $description = 'Dispatch persisted rejected document deletion intents';

    public function handle(): int
    {
        \App\Application\FP\FeatureAccess::requireEnabled();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new \RuntimeException('PostgreSQL required');
        }
        DB::table('fp_documents')->whereIn('deletion_state', ['REQUESTED', 'FAILED'])->select('submission_id')->distinct()->orderBy('submission_id')->chunk(100, function ($rows) {
            foreach ($rows as $row) {
                \App\Jobs\FP\DeleteRejectedDocuments::dispatch($row->submission_id);
            }
        });

        return self::SUCCESS;
    }
}
