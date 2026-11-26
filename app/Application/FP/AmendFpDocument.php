<?php

declare(strict_types=1);

namespace App\Application\FP;

use App\Infrastructure\FP\Uploads;
use App\Jobs\FP\PublishAmendedFpDocuments;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class AmendFpDocument
{
    public function execute(User $actor, int $membership, string $type, int $expectedRevision, mixed $file): int
    {
        abort_unless(OnboardingRevision::enabled() && VictoryAccess::canManageDocuments($actor), 403);
        if (! in_array($type, ['KTP', 'IJAZAH'], true) || $expectedRevision < 0 || $file === null) {
            throw ValidationException::withMessages(['document' => 'Pilih KTP atau ijazah berupa gambar JPEG/PNG yang aman.']);
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new \LogicException('PostgreSQL required');
        }
        $uploads = app(Uploads::class);
        $documents = $uploads->stage([$type => $file]);
        $metadata = $documents[0];
        unset($metadata['type']);
        $previousLevel = DB::transactionLevel();
        try {
            return DB::transaction(function () use ($actor, $membership, $type, $expectedRevision, $metadata): int {
                try {
                    $amendment = (int) DB::selectOne(
                        'SELECT public.fp_amend_membership_document(?,?,?,?,?::jsonb) AS id',
                        [$actor->id, $membership, $type, $expectedRevision, json_encode($metadata, JSON_THROW_ON_ERROR)]
                    )->id;
                    DB::afterCommit(static function () use ($amendment, $membership, $type): void {
                        try {
                            PublishAmendedFpDocuments::dispatch($amendment);
                        } catch (\Throwable $error) {
                            Log::warning('FP Nextcloud amendment publication dispatch failed', [
                                'amendment_id' => $amendment,
                                'membership_id' => $membership,
                                'type' => $type,
                                'error' => get_class($error),
                            ]);
                            report(new \RuntimeException('FP Nextcloud amendment publication pending dispatch'));
                        }
                    });

                    return $amendment;
                } catch (\Illuminate\Database\QueryException $error) {
                    // This expected domain rejection uses 40001. Translate it before Laravel's
                    // nested-transaction deadlock handler; the normal validation path rolls back
                    // our savepoint. Other serialization/deadlock errors are never blessed.
                    if (($error->errorInfo[0] ?? null) === '40001'
                        && preg_match('/ERROR:\s+F37H stale document revision(?:\r?\n|$)/', $error->errorInfo[2] ?? '') === 1) {
                        throw ValidationException::withMessages(['document' => 'Revisi dokumen sudah berubah. Muat ulang sebelum menyimpan.']);
                    }
                    throw $error;
                }
            }, 1);
        } catch (ValidationException $error) {
            if (DB::transactionLevel() === $previousLevel) {
                $uploads->discard($documents);
            }
            throw $error;
        } catch (\Illuminate\Database\QueryException $error) {
            // A reported server rejection is definite; connection/COMMIT uncertainty preserves staged evidence.
            if (in_array($error->errorInfo[0] ?? null, ['42501', 'P0001', '23514', '23503', '23505'], true) && DB::transactionLevel() === $previousLevel) {
                $uploads->discard($documents);
                throw ValidationException::withMessages(['document' => 'Perubahan ditolak. Muat ulang revisi terbaru dan periksa hak akses.']);
            }
            throw new \App\Domain\FP\FpPersistenceException('Status perubahan dokumen belum dapat dikonfirmasi. Jangan mengulang sebelum diperiksa.');
        } catch (\Illuminate\Database\DeadlockException) {
            throw new \App\Domain\FP\FpPersistenceException('Konflik transaksi dokumen. Periksa status sebelum tindakan berikutnya.');
        }
    }

    public static function revision(int $membership, string $type): int
    {
        return (int) DB::table('fp_document_amendments')->where('membership_id', $membership)->where('type', $type)->max('revision');
    }
}
