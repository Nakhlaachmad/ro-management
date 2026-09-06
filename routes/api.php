<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\UserSyncController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/health', HealthController::class)->name('api.health');

Route::prefix('v1')->middleware('api.jwt:users.sync')->group(function (): void {
    Route::put('/integrations/iwacs/users/{subject}', [UserSyncController::class, 'update'])
        ->where('subject', '[A-Za-z0-9._:-]{1,100}')
        ->name('api.integrations.iwacs.users.update');
});
