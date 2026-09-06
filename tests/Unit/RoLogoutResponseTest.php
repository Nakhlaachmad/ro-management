<?php

namespace Tests\Unit;

use App\Http\Responses\RoLogoutResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class RoLogoutResponseTest extends TestCase
{
    public function test_return_to_iwacs_uses_the_configured_portal_login(): void
    {
        config()->set('services.portal_sso.issuer', 'http://localhost:8000');

        $response = app(RoLogoutResponse::class)->toResponse(Request::create('/'));

        $this->assertSame('http://localhost:8000/login', $response->getTargetUrl());
    }
}
