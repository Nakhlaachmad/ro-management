<?php

declare(strict_types=1);

namespace App\Jobs\FP;

use App\Infrastructure\FP\DocumentStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class DeleteRejectedDocuments implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 5;

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function __construct(public int $submissionId)
    {
        $this->afterCommit();
    }

    private function state(object $sub, int $id, string $state, ?string $error): void
    {
        DB::transaction(function () use ($sub, $id, $state, $error) {
            DB::table('person_identities')->where('id', $sub->identity_id)->lockForUpdate()->first();
            DB::table('team_profiles')->where('team_id', $sub->team_id)->lockForUpdate()->first();
            $parent = DB::table('fp_submissions')->where('id', $sub->id)->lockForUpdate()->first();
            $doc = DB::table('fp_documents')->where('id', $id)->lockForUpdate()->first();
            if ($parent->status !== 'REJECTED' || ! $doc || $doc->deletion_state === 'DELETED') {
                return;
            }
            $time = \Carbon\CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
            DB::table('fp_documents')->where('id', $id)->update(['deletion_state' => $state, 'deletion_error' => $error, 'updated_at' => $time]);
            app(\App\Application\FP\FpEngine::class)->documentAudit($id, $sub->id, $doc->revision, $parent->rejected_actor_id, $time);
        }, 1);
    }

    public function handle(DocumentStorage $storage): void
    {
        \App\Application\FP\FeatureAccess::requireEnabled();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new \RuntimeException('PostgreSQL required');
        }
        $sub = DB::table('fp_submissions')->where('id', $this->submissionId)->first();
        if (! $sub || $sub->status !== 'REJECTED') {
            return;
        }
        foreach (DB::table('fp_documents')->where('submission_id', $this->submissionId)->whereIn('deletion_state', ['REQUESTED', 'FAILED'])->get() as $doc) {
            $documentStorage = app(\App\Infrastructure\FP\DocumentStorageRegistry::class)->forAdapter($doc->storage_adapter, $storage);
            try {
                $documentStorage->delete($doc->private_path);
            } catch (\Throwable) {
                $this->state($sub, $doc->id, 'FAILED', 'STORAGE_UNAVAILABLE');
                throw new \RuntimeException('Rejected document deletion pending');
            }
            $this->state($sub, $doc->id, 'DELETED', null);
        }
    }
}
