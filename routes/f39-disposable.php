<?php

declare(strict_types=1);

use App\Http\Controllers\FP\RemoteDocumentController;
use App\Http\Middleware\FpEnabled;
use App\Http\Middleware\FpReportAuthenticate;
use Illuminate\Support\Facades\Route;

// The coordinator's verified disposable provider is the only registration point.
Route::middleware(['web', FpReportAuthenticate::class, FpEnabled::class])
    ->get('/fp-disposable/f39/documents/{document}/revisions/{revision}', [RemoteDocumentController::class, 'show'])
    ->name('fp.disposable.f39.document');
