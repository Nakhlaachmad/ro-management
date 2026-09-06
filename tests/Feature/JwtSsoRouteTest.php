<?php

namespace Tests\Feature;

use Tests\TestCase;

class JwtSsoRouteTest extends TestCase
{
    public function test_jwt_sso_route_rejects_an_empty_token(): void
    {
        $this->get('/sso/jwt-login')->assertForbidden();
    }
}
