<?php

declare(strict_types=1);

namespace App\Http\Controllers\FP;

use App\Application\FP\FeatureAccess;
use App\Http\Controllers\Controller;
use App\Infrastructure\FP\DocumentStorage;
use App\Models\FpDocument;
use Illuminate\Http\Request;

final class DocumentController extends Controller
{
    public function show(Request $request, string $uuid, DocumentStorage $storage): \Illuminate\Http\Response
    {
        FeatureAccess::require($request->user(), 'f2.documents.view');
        abort_unless(\Illuminate\Support\Str::isUuid($uuid), 404);
        $document = FpDocument::query()->where('uuid', $uuid)->first();
        // The active UI uses current versions. Authorized historical UUIDs remain private.
        if (! $document && \App\Application\FP\OnboardingRevision::enabled()) {
            $document = FpDocument::withoutGlobalScope('current_documents')->where('uuid', $uuid)->whereIn('type', ['KTP', 'IJAZAH'])->first();
            if (! $document) {
                $version = \Illuminate\Support\Facades\DB::table('fp_document_amendments')->where('uuid', $uuid)->whereIn('type', ['KTP', 'IJAZAH'])->first();
                if ($version) {
                    $document = (new FpDocument)->newFromBuilder([
                        'id' => -$version->id, 'uuid' => $version->uuid,
                        'submission_id' => $version->submission_id, 'membership_id' => $version->membership_id,
                        'type' => $version->type, 'revision' => $version->revision,
                        'processing_state' => 'READY', 'deletion_state' => 'NONE',
                        'storage_adapter' => $version->storage_adapter, 'private_path' => $version->private_path,
                        'mime' => $version->mime, 'bytes' => $version->bytes, 'sha256' => $version->sha256,
                    ]);
                }
            }
        }
        abort_unless($document, 404);
        abort_if($document->processing_state->value !== 'READY' || $document->deletion_state->value !== 'NONE', 404);
        try {
            $storage = app(\App\Infrastructure\FP\DocumentStorageRegistry::class)->forAdapter($document->storage_adapter, $storage);
            $bytes = $storage->read($document->getRawOriginal('private_path'));
        } catch (\RuntimeException) {
            abort(503, 'Layanan dokumen privat belum tersedia.');
        }
        abort_unless(strlen($bytes) === $document->bytes && hash_equals($document->getRawOriginal('sha256'), hash('sha256', $bytes)), 503);

        return response($bytes, 200, ['Content-Type' => $document->mime, 'Content-Disposition' => 'inline; filename="document.jpg"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    public function viewer(Request $request, string $submission): \Illuminate\View\View
    {
        FeatureAccess::require($request->user(), 'f2.documents.view');
        $sub = \App\Models\FpSubmission::query()->where('uuid', $submission)->firstOrFail();

        return view('fp.viewer', ['documents' => FpDocument::query()->where('submission_id', $sub->id)->orderBy('type')->get(['uuid', 'type'])]);
    }
}
