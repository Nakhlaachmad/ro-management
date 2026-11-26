<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\FP\DocumentOperations;
use App\Infrastructure\FP\NextcloudRevisionStorage;
use App\Infrastructure\FP\NextcloudWebDavClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class F39ReconcileDocuments extends Command
{
    protected $signature = 'fp:f39-reconcile {--actor=} {--operation=} {--limit=25} {--apply} {--staging=}';

    protected $description = 'Inspect document operations on a verified F3.9 disposable database; dry-run by default';

    public function handle(): int
    {
        $actor = filter_var($this->option('actor'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $staging = (string) $this->option('staging');
        if (! $actor || $limit === false || $limit < 1 || $limit > 100 || ! is_dir($staging)) {
            $this->error('Provide an active Admin ID, a private staging directory and a limit of 1–100.');

            return self::FAILURE;
        }
        try {
            // This client is read-only even with --apply. Reconciliation never PUTs.
            $storage = new NextcloudRevisionStorage(NextcloudWebDavClient::configured(healthCheckOnly: true));
            $engine = new DocumentOperations(DB::connection()->getPdo(), $storage, $staging);
            $dryRun = ! $this->option('apply');
            $operation = $this->option('operation');
            $results = $operation
                ? [$engine->reconcile($actor, (string) $operation, $dryRun)]
                : $engine->batch($actor, $limit, $dryRun);
            foreach ($results as $result) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            }

            return self::SUCCESS;
        } catch (\Throwable) {
            // Never expose driver messages, connection strings or upstream payloads.
            $this->error('Reconciliation unavailable. Verify disposable identity, Admin access and private storage configuration.');

            return self::FAILURE;
        }
    }
}
