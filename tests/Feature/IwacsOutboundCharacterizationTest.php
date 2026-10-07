<?php

namespace Tests\Feature;

use App\Services\Api\IwacsRoClient;
use App\Services\Api\IwacsRoException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\IntegrationTestCase;

final class IwacsOutboundCharacterizationTest extends IntegrationTestCase
{
    private function fakeService(callable $api): void
    {
        $this->configureOutbound();
        Http::fake(function (Request $request) use ($api) {
            if ($request->url() === 'https://keycloak.test/discovery') {
                return Http::response(['token_endpoint' => 'https://keycloak.test/token']);
            }
            if ($request->url() === 'https://keycloak.test/token') {
                return Http::response(['access_token' => 'synthetic-service-token']);
            }

            return $api($request);
        });
    }

    public function test_create_preserves_exact_payload_key_headers_and_service_authentication(): void
    {
        $this->fakeService(fn () => Http::response(['sso_subject' => 'created-subject', 'profile_version' => 1], 201));
        $profile = ['name' => 'Synthetic', 'email' => 'synthetic@example.test', 'password' => 'Synthetic!123', 'role' => 'ro'];
        $this->assertSame(['sso_subject' => 'created-subject', 'profile_version' => 1], app(IwacsRoClient::class)->createUser($profile, 'synthetic-idempotency'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://iwacs.test/api/v1/integrations/ro/users'
            && $r->data() === $profile && $r->hasHeader('Authorization', 'Bearer synthetic-service-token')
            && $r->hasHeader('Idempotency-Key', 'synthetic-idempotency') && $r->hasHeader('Accept', 'application/json')
            && $r->hasHeader('Content-Type', 'application/json'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://keycloak.test/token'
            && $r->method() === 'POST' && $r->data() === ['grant_type' => 'client_credentials', 'scope' => 'openid']
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('ro-service:synthetic-test-secret')));
        Http::assertSentCount(3);
    }

    public function test_update_encodes_subject_and_normalizes_source_email_without_rewriting_changes(): void
    {
        $this->fakeService(fn () => Http::response(['sso_subject' => 'subject/a', 'profile_version' => 5]));
        app(IwacsRoClient::class)->updateUser('subject/a', 4, ['role' => 'bm', 'is_active' => false], ' SOURCE@EXAMPLE.TEST ');
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://iwacs.test/api/v1/integrations/ro/users/subject%2Fa'
            && $r->data() === ['expected_version' => 4, 'source_email' => 'source@example.test', 'changes' => ['role' => 'bm', 'is_active' => false]]
            && str_starts_with($r->header('Idempotency-Key')[0], 'ro-update-'));
    }

    public function test_conflict_retries_once_with_new_idempotency_key_and_reauthenticates(): void
    {
        $requests = [];
        $this->fakeService(function (Request $request) use (&$requests) {
            $requests[] = $request;

            return count($requests) === 1
                ? Http::response(['error' => 'profile_version_conflict', 'current_version' => 9], 409)
                : Http::response(['sso_subject' => 'subject', 'profile_version' => 10]);
        });
        $result = app(IwacsRoClient::class)->updateUser('subject', 1, ['name' => 'Synthetic'], 'source@example.test');
        $this->assertSame(10, $result['profile_version']);
        $this->assertSame(1, $requests[0]['expected_version']);
        $this->assertSame(9, $requests[1]['expected_version']);
        $this->assertNotSame($requests[0]->header('Idempotency-Key'), $requests[1]->header('Idempotency-Key'));
        $this->assertStringStartsWith('ro-retry-', $requests[1]->header('Idempotency-Key')[0]);
        Http::assertSentCount(6);
    }

    public function test_second_conflict_is_not_retried_again(): void
    {
        $this->fakeService(fn () => Http::response(['error' => 'profile_version_conflict', 'current_version' => 9], 409));
        try {
            app(IwacsRoClient::class)->updateUser('subject', 1, ['name' => 'Synthetic'], 'source@example.test');
            $this->fail('Expected conflict.');
        } catch (IwacsRoException $e) {
            $this->assertSame('IWACS menolak perubahan akun RO.', $e->getMessage());
        }
        Http::assertSentCount(6);
    }

    public function test_lookup_is_query_encoded_and_logout_ticket_is_trimmed(): void
    {
        $this->fakeService(fn (Request $r) => Http::response(str_contains($r->url(), '/lookup')
            ? ['data' => ['sso_subject' => 'subject', 'profile_version' => 7]]
            : ['sso_subject' => 'subject', 'logout_url' => 'https://iwacs.test/logout?ticket=synthetic']));
        $this->assertSame(7, app(IwacsRoClient::class)->lookupByEmail(' A+B@EXAMPLE.TEST ')['profile_version']);
        app(IwacsRoClient::class)->issueLogoutTicket(' subject ');
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://iwacs.test/api/v1/integrations/ro/users/lookup?email=a%2Bb%40example.test'
            && str_starts_with($r->header('Idempotency-Key')[0], 'ro-lookup-'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://iwacs.test/api/v1/integrations/ro/users/logout-ticket'
            && $r->data() === ['sso_subject' => 'subject'] && str_starts_with($r->header('Idempotency-Key')[0], 'ro-logout-'));
    }

    public function test_successful_response_without_identity_fails_closed(): void
    {
        $this->fakeService(fn () => Http::response(['status' => 'ok']));
        $this->expectException(IwacsRoException::class);
        $this->expectExceptionMessage('Respons IWACS tidak menyertakan identitas akun.');
        app(IwacsRoClient::class)->createUser([], 'synthetic');
    }

    public function test_disabled_client_has_no_http_side_effects(): void
    {
        Http::fake();
        try {
            app(IwacsRoClient::class)->createUser([], 'synthetic');
            $this->fail('Expected disabled client.');
        } catch (IwacsRoException $e) {
            $this->assertStringContainsString('belum diaktifkan', $e->getMessage());
        }
        Http::assertNothingSent();
    }
}
