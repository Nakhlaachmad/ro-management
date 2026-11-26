<?php

declare(strict_types=1);

namespace App\Http\Controllers\FP;

use App\Application\FP\ReadRemoteDocument;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class RemoteDocumentController extends Controller
{
    public function show(Request $request, string $document, string $revision, ReadRemoteDocument $reader): Response
    {
        abort_unless($request->user(), 401);
        $result = $reader->read($request->user(), $document, $revision);

        return response($result['bytes'], 200, [
            'Content-Type' => $result['mime'],
            'Content-Disposition' => 'inline; filename="'.$result['filename'].'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
