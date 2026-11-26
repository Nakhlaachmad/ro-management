<?php

declare(strict_types=1);

namespace App\Application\FP;

use App\Domain\FP\FpPersistenceException;
use App\Infrastructure\FP\Uploads;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Admin-owned document fill/replacement while a submission is still PENDING. */
final class FillSubmissionDocument
{
    public function execute(User $actor, int $submission, string $type, mixed $file): int
    {
        abort_unless(OnboardingRevision::enabled() && VictoryAccess::canManageDocuments($actor), 403);
        if (! in_array($type, ['KTP', 'IJAZAH'], true) || $file === null) {
            throw ValidationException::withMessages(['document' => 'Pilih KTP atau ijazah berupa gambar JPEG/PNG yang aman.']);
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('PostgreSQL required');
        }

        $uploads = app(Uploads::class);
        $documents = $uploads->stage([$type => $file]);
        if (count($documents) !== 1) {
            throw ValidationException::withMessages(['document' => 'Dokumen tidak dapat diproses. Pilih ulang file yang aman.']);
        }
        $metadata = $documents[0];
        unset($metadata['type']);
        $previousLevel = DB::transactionLevel();

        try {
            return DB::transaction(function () use ($actor, $submission, $type, $metadata): int {
                $parent = DB::table('fp_submissions')->where('id', $submission)->lockForUpdate()->first();
                if (! $parent) {
                    throw ValidationException::withMessages(['submission' => 'Pengajuan tidak tersedia.']);
                }
                if ($parent->status !== 'PENDING') {
                    throw ValidationException::withMessages(['submission' => 'Dokumen hanya dapat diisi sebelum persetujuan melalui pengajuan ini.']);
                }

                $revision = (int) $parent->document_revision + 1;
                $time = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
                $current = DB::table('fp_documents')->where('submission_id', $submission)->orderBy('id')->lockForUpdate()->get();
                $target = $current->first(fn ($document) => $document->type === $type);

                DB::table('fp_submissions')->where('id', $submission)->update(['document_revision' => $revision, 'updated_at' => $time]);

                foreach ($current as $document) {
                    $changes = ['revision' => $revision, 'updated_at' => $time];
                    if ($target && (int) $document->id === (int) $target->id) {
                        $changes += [...$metadata, 'processing_state' => 'READY', 'deletion_state' => 'NONE', 'deletion_error' => null];
                    }
                    DB::table('fp_documents')->where('id', $document->id)->update($changes);
                    $this->documentAudit((int) $document->id, $submission, $revision, (int) $actor->id, $time);
                }

                if ($target) {
                    return (int) $target->id;
                }

                $id = (int) DB::table('fp_documents')->insertGetId([...$metadata,
                    'uuid' => (string) Str::uuid(), 'submission_id' => $submission, 'membership_id' => null,
                    'type' => $type, 'revision' => $revision, 'processing_state' => 'READY', 'deletion_state' => 'NONE',
                    'created_at' => $time, 'updated_at' => $time,
                ]);
                $this->documentAudit($id, $submission, $revision, (int) $actor->id, $time);

                return $id;
            }, 1);
        } catch (ValidationException $error) {
            if (DB::transactionLevel() === $previousLevel) {
                $uploads->discard($documents);
            }
            throw $error;
        } catch (QueryException $error) {
            if (in_array($error->errorInfo[0] ?? null, ['42501', 'P0001', '23514', '23503', '23505'], true)
                && DB::transactionLevel() === $previousLevel) {
                $uploads->discard($documents);
                throw ValidationException::withMessages(['document' => 'Perubahan dokumen ditolak. Muat ulang pengajuan dan periksa hak akses.']);
            }
            throw new FpPersistenceException('Status penyimpanan dokumen belum dapat dikonfirmasi. Jangan mengulang sebelum diperiksa.');
        }
    }

    public static function revision(int $submission): int
    {
        return (int) (DB::table('fp_submissions')->where('id', $submission)->value('document_revision') ?? 1);
    }

    private function documentAudit(int $document, int $submission, int $revision, int $actor, string $time): void
    {
        DB::table('fp_audit_events')->insert(['uuid' => (string) Str::uuid(), 'event' => 'DOCUMENT_CHANGED',
            'actor_id' => $actor, 'occurred_at' => $time, 'submission_id' => $submission,
            'document_id' => $document, 'revision' => $revision, 'context' => '{}',
        ]);
    }
}
