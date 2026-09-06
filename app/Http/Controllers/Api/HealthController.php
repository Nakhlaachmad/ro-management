<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\Response;

final class HealthController
{
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
