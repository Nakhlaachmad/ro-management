<?php

declare(strict_types=1);

namespace App\Http\Controllers\FP;

use App\Application\FP\FpEngine;
use App\Http\Controllers\Controller;
use App\Http\Requests\FpApplicationRequest;
use App\Infrastructure\FP\Uploads;
use App\Models\FpTeam;

final class OnboardingController extends Controller
{
    public function form(): \Illuminate\View\View
    {
        return view('fp.onboarding', ['uploadLimits' => \App\Infrastructure\FP\UploadLimits::calculate((string) ini_get('upload_max_filesize'), (string) ini_get('post_max_size')), 'teams' => FpTeam::query()->where('is_active', true)->orderBy('display_name')->get(['team_id', 'display_name'])]);
    }

    public function submit(FpApplicationRequest $request, FpEngine $engine, Uploads $uploads): \Illuminate\Http\JsonResponse
    {
        try {
            $files = ['KTP' => $request->file('ktp'), 'IJAZAH' => $request->file('ijazah')];
            if (! \App\Application\FP\OnboardingRevision::enabled()) {
                $files = ['PROFILE_PHOTO' => $request->file('profile_photo'), ...$files];
            }
            $documents = $uploads->stage($files);
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'Dokumen harus berupa gambar JPEG/PNG yang jelas dan sesuai ukuran.'], 422);
        } catch (\RuntimeException) {
            return response()->json(['message' => 'Layanan dokumen belum tersedia. Silakan hubungi HRD.'], 503);
        }
        try {
            $result = $engine->submitFpApplication($request->safe()->except(['profile_photo', 'ktp', 'ijazah', 'idempotency_key']), $documents, $request->validated('idempotency_key'));
        } catch (\Illuminate\Validation\ValidationException $error) {
            // Laravel rethrows callback validation only after successful transaction rollback.
            $uploads->discard($documents);
            throw $error;
        } catch (\Throwable) {
            // A connection/COMMIT failure may have persisted these exact private locators.
            return response()->json(['message' => 'Status pendaftaran belum dapat dikonfirmasi. Hubungi HRD dengan kode permintaan sebelum mengirim ulang.', 'request_key' => $request->validated('idempotency_key')], 503);
        }
        if ($result['replayed'] ?? false) {
            $uploads->discard($documents);
        }

        return response()->json(\App\Domain\FP\PublicReceipt::make($result['receipt']), 202);
    }
}
