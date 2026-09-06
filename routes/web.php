<?php

use App\Http\Controllers\JwtSsoController;
use App\Http\Controllers\GlobalLogoutController;

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/sso/jwt-login', JwtSsoController::class)
    ->middleware('throttle:20,1')
    ->name('portal.sso.jwt-login');

Route::post('/sso/global-logout', GlobalLogoutController::class)
    ->middleware(['auth', 'throttle:10,1'])
    ->name('portal.sso.global-logout');


Route::get('/up', static fn () => response()->noContent())
    ->name('health');
