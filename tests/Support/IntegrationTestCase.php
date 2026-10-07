<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::createFromTimestampUTC(1801958400));
        JWT::$timestamp = now()->timestamp;
        JWT::$leeway = 0;
        foreach (['admin', 'hrd', 'bm', 'ro', 'outsider'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        config()->set('services.portal_sso', [
            'issuer' => 'https://iwacs.test', 'audience' => 'ro',
            'public_key_path' => __DIR__.'/../Fixtures/rsa-public.pem',
            'token_ttl' => 120, 'leeway' => 5, 'replay_ttl' => 120,
        ]);
        config()->set('api.issuer', 'https://keycloak.test/realms/test');
        config()->set('api.discovery_url', 'https://keycloak.test/discovery');
        config()->set('api.audiences', ['ro-service', 'iwacs-api']);
        config()->set('api.leeway_seconds', 5);
        config()->set('api.jwks_cache_seconds', 3600);
    }

    protected function tearDown(): void
    {
        JWT::$timestamp = null;
        JWT::$leeway = 0;
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function user(string $role = 'ro', bool $active = true, array $attributes = []): User
    {
        $user = User::create(array_merge([
            'name' => 'Synthetic User', 'email' => 'user-'.uniqid().'@example.test',
            'password' => 'Synthetic!123', 'sso_subject' => 'subject-'.uniqid(),
            'profile_version' => 1, 'is_active' => $active,
        ], $attributes));
        $user->assignRole($role);

        return $user;
    }

    protected function ssoClaims(array $changes = []): array
    {
        return array_merge([
            'iss' => 'https://iwacs.test', 'aud' => 'ro', 'sub' => 'subject-test',
            'jti' => 'jti-'.uniqid(), 'app_role' => 'ro', 'is_global_admin' => false,
            'iat' => now()->timestamp, 'nbf' => now()->timestamp, 'exp' => now()->timestamp + 120,
        ], $changes);
    }

    protected function signed(array $claims, string $kid = 'test-key', bool $wrongKey = false): string
    {
        return JWT::encode($claims, file_get_contents(__DIR__.'/../Fixtures/'.($wrongKey ? 'rsa-other-private.pem' : 'rsa-private.pem')), 'RS256', $kid);
    }

    protected function apiToken(array $changes = [], string $kid = 'test-key'): string
    {
        return $this->signed(array_merge([
            'iss' => 'https://keycloak.test/realms/test', 'aud' => 'ro-service',
            'iat' => now()->timestamp, 'nbf' => now()->timestamp, 'exp' => now()->timestamp + 120,
            'scope' => 'openid users.sync',
        ], $changes), $kid);
    }

    protected function jwks(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/jwks.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function fakeOidc(): void
    {
        Http::fake([
            'https://keycloak.test/discovery' => Http::response(['jwks_uri' => 'https://keycloak.test/jwks']),
            'https://keycloak.test/jwks' => Http::response($this->jwks()),
        ]);
    }

    protected function configureOutbound(): void
    {
        config()->set('services.iwacs_ro', [
            'enabled' => true, 'url' => 'https://iwacs.test/api/v1/integrations/ro/users/',
            'lookup_url' => 'https://iwacs.test/api/v1/integrations/ro/users/lookup',
            'discovery_url' => 'https://keycloak.test/discovery',
            'client_id' => 'ro-service', 'client_secret' => 'synthetic-test-secret',
            'connect_timeout' => 1, 'timeout' => 2,
        ]);
    }
}
