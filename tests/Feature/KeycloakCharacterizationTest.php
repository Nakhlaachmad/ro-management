<?php

namespace Tests\Feature;

use App\Exceptions\Api\ApiException;
use App\Services\Api\KeycloakAccessTokenValidator;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Tests\Support\IntegrationTestCase;

final class KeycloakCharacterizationTest extends IntegrationTestCase
{
    private function reject(string $token, string $code, int $status): void
    {
        try {
            app(KeycloakAccessTokenValidator::class)->validate($token);
            $this->fail('Expected token rejection.');
        } catch (ApiException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($status, $exception->status);
        }
    }

    public function test_valid_key_selection_jwks_cache_and_repeated_discovery_are_preserved(): void
    {
        $this->fakeOidc();
        $validator = app(KeycloakAccessTokenValidator::class);
        JWT::$leeway = 11;
        $claims = $validator->validate($this->apiToken());
        $this->assertSame(['openid', 'users.sync'], $claims['_scopes']);
        $validator->validate($this->apiToken());
        $this->assertSame(11, JWT::$leeway);
        Http::assertSentCount(3); // Discovery is NOT cached; JWKS is cached.
        $this->assertCount(2, Http::recorded(fn ($request) => $request->url() === 'https://keycloak.test/discovery'));
        $this->assertCount(1, Http::recorded(fn ($request) => $request->url() === 'https://keycloak.test/jwks'));
    }

    public function test_unknown_kid_refreshes_jwks_once_and_then_rejects(): void
    {
        $this->fakeOidc();
        $this->reject($this->apiToken([], 'missing-key'), 'unknown_token_kid', 401);
        Http::assertSentCount(4);
    }

    public function test_rotated_kid_is_selected_from_refreshed_jwks(): void
    {
        $rotated = $this->jwks();
        $rotated['keys'][0]['kid'] = 'rotated';
        Http::fake([
            'https://keycloak.test/discovery' => Http::response(['jwks_uri' => 'https://keycloak.test/jwks']),
            'https://keycloak.test/jwks' => Http::sequence()->push($this->jwks())->push($rotated),
        ]);
        $this->assertSame('ro-service', app(KeycloakAccessTokenValidator::class)->validate($this->apiToken([], 'rotated'))['aud']);
        Http::assertSentCount(4);
    }

    public function test_disabling_jwks_cache_fetches_each_time(): void
    {
        config()->set('api.jwks_cache_seconds', 0);
        $this->fakeOidc();
        app(KeycloakAccessTokenValidator::class)->validate($this->apiToken());
        app(KeycloakAccessTokenValidator::class)->validate($this->apiToken());
        Http::assertSentCount(4);
    }

    public static function endpointCases(): array
    {
        return [
            'discovery failure' => ['discovery', [], 500, 'oidc_http_error'],
            'discovery non-json' => ['discovery', 'not-json', 200, 'oidc_invalid_json'],
            'discovery list' => ['discovery', [['jwks_uri' => 'x']], 200, 'oidc_invalid_document'],
            'missing jwks uri' => ['discovery', ['issuer' => 'x'], 200, 'missing_jwks_uri'],
            'jwks failure' => ['jwks', [], 500, 'oidc_http_error'],
            'jwks invalid json' => ['jwks', '{broken', 200, 'oidc_invalid_json'],
            'jwks missing keys' => ['jwks', ['other' => true], 200, 'invalid_jwks'],
            'empty keyset' => ['jwks', ['keys' => []], 200, 'invalid_jwks'],
            'private key material' => ['jwks', ['keys' => [['kty' => 'RSA', 'd' => 'private-test-value']]], 200, 'private_jwk_published'],
        ];
    }

    /** @dataProvider endpointCases */
    public function test_endpoint_error_contracts(string $endpoint, array|string $body, int $status, string $code): void
    {
        Http::fake([
            'https://keycloak.test/discovery' => Http::response($endpoint === 'discovery' ? $body : ['jwks_uri' => 'https://keycloak.test/jwks'], $endpoint === 'discovery' ? $status : 200),
            'https://keycloak.test/jwks' => Http::response($body, $status),
        ]);
        $this->reject($this->apiToken(), $code, 503);
    }

    public function test_transport_failure_is_wrapped_without_real_network(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('synthetic offline'));
        $this->reject($this->apiToken(), 'oidc_unavailable', 503);
    }

    public function test_issuer_audience_signature_expiry_and_header_errors(): void
    {
        $this->fakeOidc();
        foreach ([
            [$this->apiToken(['iss' => 'https://other.test']), 'wrong_token_issuer', 401],
            [$this->apiToken(['aud' => 'wrong']), 'wrong_token_audience', 403],
            [$this->apiToken(['exp' => now()->timestamp - 6]), 'invalid_token', 401],
            [$this->signed(['iss' => 'x'], 'test-key', true), 'invalid_token', 401],
            ['malformed', 'invalid_token', 401],
            ['@@.a.b', 'invalid_token_header', 401],
            [JWT::encode(['x' => true], str_repeat('synthetic', 8), 'HS256', 'test-key'), 'invalid_token_header', 401],
        ] as [$token, $code, $status]) {
            $this->reject($token, $code, $status);
        }
    }

    public function test_issuer_trailing_slash_audience_array_leeway_and_resource_roles_are_accepted(): void
    {
        $this->fakeOidc();
        $claims = app(KeycloakAccessTokenValidator::class)->validate($this->apiToken([
            'iss' => 'https://keycloak.test/realms/test/', 'aud' => ['other', 'iwacs-api'],
            'scope' => 'openid', 'exp' => now()->timestamp - 4,
            'resource_access' => ['unrelated-client' => ['roles' => ['users.sync']]],
        ]));
        $this->assertSame(['openid', 'users.sync'], $claims['_scopes']);
    }

    public function test_api_does_not_require_exp_or_sub_if_missing_currently(): void
    {
        $this->fakeOidc();
        $claims = ['iss' => 'https://keycloak.test/realms/test', 'aud' => 'ro-service', 'scope' => 'users.sync'];
        $result = app(KeycloakAccessTokenValidator::class)->validate($this->signed($claims));
        $this->assertArrayNotHasKey('exp', $result);
        $this->assertArrayNotHasKey('sub', $result);
    }

    public static function authCases(): array
    {
        return [
            'missing' => [null, [], 401, 'missing_bearer_token'],
            'invalid' => ['not-jwt', [], 401, 'invalid_token'],
            'expired' => ['signed', ['exp' => 1801958394], 401, 'invalid_token'],
            'scope missing' => ['signed', ['scope' => 'openid'], 403, 'insufficient_scope'],
            'issuer' => ['signed', ['iss' => 'wrong'], 401, 'wrong_token_issuer'],
            'audience' => ['signed', ['aud' => 'wrong'], 403, 'wrong_token_audience'],
        ];
    }

    /** @dataProvider authCases */
    public function test_api_authentication_error_response(?string $token, array $changes, int $status, string $code): void
    {
        $this->fakeOidc();
        $headers = $token === null ? [] : ['Authorization' => 'Bearer '.($token === 'signed' ? $this->apiToken($changes) : $token)];
        $response = $this->putJson('/api/v1/integrations/iwacs/users/subject-test', [], $headers)
            ->assertStatus($status)->assertJsonPath('error', $code)
            ->assertJsonStructure(['error', 'message', 'details']);
        if ($status === 401) {
            $response->assertHeader('WWW-Authenticate', 'Bearer');
        } else {
            $response->assertHeaderMissing('WWW-Authenticate');
        }
    }

    public function test_valid_scope_reaches_existing_controller_validation(): void
    {
        $this->fakeOidc();
        $this->withToken($this->apiToken())->putJson('/api/v1/integrations/iwacs/users/subject-test', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['event_id', 'sso_subject', 'expected_version', 'profile_version', 'changes']);
    }
}
