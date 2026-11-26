<?php

use App\Http\Controllers\FP\DocumentController;
use App\Http\Controllers\FP\OnboardingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', \App\Http\Middleware\FpEnabled::class])->prefix('fp-onboarding')->group(function () {
    Route::get('/', [OnboardingController::class, 'form'])->middleware('throttle:fp-form')->name('fp.form');
    Route::post('/', [OnboardingController::class, 'submit'])->middleware('throttle:fp-submit')->name('fp.submit');
});
Route::middleware(['web', \App\Http\Middleware\FpReportAuthenticate::class, \App\Http\Middleware\FpEnabled::class])->prefix('fp-private')->group(function () {
    Route::get('/documents/{uuid}', [DocumentController::class, 'show'])->middleware('throttle:60,1')->name('fp.document');
    Route::get('/review/{submission}', [DocumentController::class, 'viewer'])->name('fp.viewer');
});

Route::middleware(['web', \App\Http\Middleware\FpReportAuthenticate::class, \App\Http\Middleware\FpEnabled::class, 'throttle:fp-report-export'])->get('/fp-private/reports/export', [\App\Http\Controllers\FP\ReportController::class, 'download'])->name('fp.report.export');
