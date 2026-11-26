<?php

declare(strict_types=1);

namespace App\Http\Middleware;

final class FpReportAuthenticate extends \Illuminate\Auth\Middleware\Authenticate
{
    protected function redirectTo(\Illuminate\Http\Request $request): ?string
    {
        return $request->expectsJson() ? null : route('filament.fp-dev.auth.login');
    }
}
