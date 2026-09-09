<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Api\IwacsRoClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class IwacsRoClientTest extends TestCase
{
    public function test_delete_user_sends_a_permanent_delete_request_to_iwacs(): void
    {
        config()->set('services.iwacs_ro', [
            'enabled' => true,
            'url' => 'http://iwacs.test/api/v1/integrations/ro/users',
            'lookup_url' => 'http://iwacs.test/api/v1/integrations/ro/users/lookup',
            'discovery_url' => 'http://keycloak.test/.well-known/openid-configuration',
            'client_id' => 'ro-service',
            'client_secret' => 'test-secret',
            'connect_timeout' => 1,
            'timeout' => 2,
        ]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/.well-known/openid-configuration')) {
                return Http::response(['token_endpoint' => 'http://keycloak.test/token']);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/token')) {
                return Http::response(['access_token' => 'service-token']);
            }
            if ($request->method() === 'DELETE' && str_ends_with($request->url(), '/subject-ro-delete')) {
                return Http::response([
                    'status' => 'deleted',
                    'sso_subject' => 'subject-ro-delete',
                    'permanent' => true,
                ]);
            }

            return Http::response(['error' => 'unexpected_request'], 500);
        });

        $result = app(IwacsRoClient::class)->deleteUser(
            'subject-ro-delete',
            6,
            'ro@example.test',
        );

        $this->assertTrue($result['permanent']);
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/subject-ro-delete')
            && $request['expected_version'] === 6
            && $request['source_email'] === 'ro@example.test'
            && str_starts_with((string) $request->header('Idempotency-Key')[0], 'ro-delete-')
        );
    }

    public function test_password_update_retries_with_the_canonical_profile_version(): void
    {
        config()->set('services.iwacs_ro', [
            'enabled' => true,
            'url' => 'http://iwacs.test/api/v1/integrations/ro/users',
            'lookup_url' => 'http://iwacs.test/api/v1/integrations/ro/users/lookup',
            'discovery_url' => 'http://keycloak.test/.well-known/openid-configuration',
            'client_id' => 'ro-service',
            'client_secret' => 'test-secret',
            'connect_timeout' => 1,
            'timeout' => 2,
        ]);

        $apiCalls = 0;
        Http::fake(function (Request $request) use (&$apiCalls) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/.well-known/openid-configuration')) {
                return Http::response(['token_endpoint' => 'http://keycloak.test/token']);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/token')) {
                return Http::response(['access_token' => 'service-token']);
            }
            if ($request->method() === 'PUT' && str_contains($request->url(), '/subject-ro/password')) {
                $apiCalls++;

                return $apiCalls === 1
                    ? Http::response([
                        'error' => 'profile_version_conflict',
                        'details' => ['current_version' => 3],
                    ], 409)
                    : Http::response([
                        'status' => 'synchronized',
                        'sso_subject' => 'subject-ro',
                        'profile_version' => 3,
                    ]);
            }

            return Http::response(['error' => 'unexpected_request'], 500);
        });

        $result = app(IwacsRoClient::class)->updatePassword(
            'subject-ro',
            1,
            'Changed!123',
            'ro@example.test',
        );

        $this->assertSame(3, $result['profile_version']);
        $this->assertSame(2, $apiCalls);
        Http::assertSentCount(6);
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/subject-ro/password')
            && $request['expected_version'] === 3
            && $request['password'] === 'Changed!123'
        );
    }
}
