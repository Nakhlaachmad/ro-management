<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\FP\FeatureAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FpEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(FeatureAccess::enabled(), 404);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
