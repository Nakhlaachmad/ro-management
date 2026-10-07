<?php

namespace Tests\Feature;

use App\Exceptions\Sso\JwtSsoValidationException;
use App\Services\Sso\JtiReplayGuard;
use App\Services\Sso\JwtSsoValidator;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Tests\Support\IntegrationTestCase;

final class JwtCharacterizationTest extends IntegrationTestCase
{
    public function test_valid_rs256_string_and_array_audiences_are_accepted_and_leeway_restored(): void
    {
        JWT::$leeway = 19;
        foreach (['ro', ['other', 'ro']] as $audience) {
            $claims = app(JwtSsoValidator::class)->validate($this->signed($this->ssoClaims(['aud' => $audience])));
            $this->assertSame('subject-test', $claims->sub);
            $this->assertSame(19, JWT::$leeway);
        }
    }

    public static function rejectionCases(): array
    {
        return [
            'signature' => [[], 'invalid_signature', true],
            'issuer' => [['iss' => 'https://other.test'], 'wrong_issuer'],
            'issuer slash is significant' => [['iss' => 'https://iwacs.test/'], 'wrong_issuer'],
            'audience' => [['aud' => 'hrd'], 'wrong_audience'],
            'array audience' => [['aud' => ['hrd']], 'wrong_audience'],
            'role type' => [['app_role' => 1], 'invalid_claim_app_role'],
            'blank subject' => [['sub' => '  '], 'invalid_claim_sub'],
            'admin type' => [['is_global_admin' => 'false'], 'invalid_claim_is_global_admin'],
            'time type' => [['iat' => '1801958400'], 'invalid_claim_iat'],
            'expiry before issue' => [['exp' => 1801958400], 'invalid_token_lifetime'],
            'nbf before issue' => [['nbf' => 1801958399], 'invalid_token_lifetime'],
            'excess lifetime' => [['exp' => 1801958526], 'token_lifetime_too_long'],
            'expired beyond leeway' => [['iat' => 1801958300, 'nbf' => 1801958300, 'exp' => 1801958395], 'expired_token'],
            'nbf beyond leeway' => [['nbf' => 1801958406], 'token_not_yet_valid'],
            'future iat without nbf' => [['iat' => 1801958406, 'nbf' => null], 'token_not_yet_valid'],
        ];
    }

    /** @dataProvider rejectionCases */
    public function test_rejection_reasons_are_frozen(array $changes, string $reason, bool $wrongKey = false): void
    {
        JWT::$leeway = 17;
        try {
            app(JwtSsoValidator::class)->validate($this->signed($this->ssoClaims($changes), 'test-key', $wrongKey));
            $this->fail('Expected JWT rejection.');
        } catch (JwtSsoValidationException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
        $this->assertSame(17, JWT::$leeway);
    }

    public function test_all_required_claims_are_rejected_when_missing(): void
    {
        foreach (['iss', 'aud', 'sub', 'jti', 'app_role', 'iat', 'nbf', 'exp', 'is_global_admin'] as $claim) {
            $claims = $this->ssoClaims();
            unset($claims[$claim]);
            $reason = match ($claim) {
                'iss' => 'wrong_issuer', 'aud' => 'wrong_audience', default => 'invalid_claim_'.$claim,
            };
            try {
                app(JwtSsoValidator::class)->validate($this->signed($claims));
                $this->fail('Missing '.$claim.' accepted.');
            } catch (JwtSsoValidationException $exception) {
                $this->assertSame($reason, $exception->reason);
            }
        }
    }

    public function test_empty_malformed_and_wrong_algorithm_are_rejected(): void
    {
        foreach (['' => 'empty_token', 'not.a.jwt' => 'jwt_configuration_error', 'not-jwt' => 'invalid_token',
            JWT::encode($this->ssoClaims(), str_repeat('test-only', 8), 'HS256') => 'invalid_token'] as $token => $reason) {
            try {
                app(JwtSsoValidator::class)->validate($token);
                $this->fail('Expected rejection.');
            } catch (JwtSsoValidationException $exception) {
                $this->assertSame($reason, $exception->reason, 'Rejected token reason must remain stable.');
            }
        }
    }

    public function test_leeway_is_clamped_to_five_seconds(): void
    {
        config()->set('services.portal_sso.leeway', 99);
        $this->assertNotNull(app(JwtSsoValidator::class)->validate($this->signed($this->ssoClaims([
            'iat' => now()->timestamp - 100, 'nbf' => now()->timestamp - 100, 'exp' => now()->timestamp - 4,
        ]))));
        $this->assertNotNull(app(JwtSsoValidator::class)->validate($this->signed($this->ssoClaims(['nbf' => now()->timestamp + 5]))));
    }

    public function test_jti_is_trimmed_hashed_consumed_once_and_expires_at_configured_ttl(): void
    {
        $guard = app(JtiReplayGuard::class);
        $this->assertFalse($guard->consume(' '));
        $this->assertTrue($guard->consume(' test-jti '));
        $this->assertTrue(Cache::has('sso:ro:jti:'.hash('sha256', 'test-jti')));
        $this->assertFalse($guard->consume('test-jti'));
        $this->travel(119)->seconds();
        $this->assertFalse($guard->consume('test-jti'));
        $this->travel(2)->seconds();
        $this->assertTrue($guard->consume('test-jti'));
    }

    public function test_sso_accepts_each_existing_role_with_matching_local_identity(): void
    {
        foreach (['ro', 'bm', 'hrd', 'admin'] as $role) {
            $user = $this->user($role);
            $token = $this->signed($this->ssoClaims(['sub' => $user->sso_subject, 'app_role' => $role]));
            $this->get('/sso/jwt-login?token='.urlencode($token))->assertRedirect('/')
                ->assertSessionHas('password_hash_web', $user->getAuthPassword());
            $this->assertAuthenticatedAs($user, 'web');
            auth()->logout();
        }
    }

    public function test_rejected_identity_still_consumes_the_jti(): void
    {
        $claims = $this->ssoClaims(['sub' => 'missing', 'jti' => 'consumed-before-user-lookup']);
        $token = $this->signed($claims);
        $this->get('/sso/jwt-login?token='.urlencode($token))->assertForbidden();
        $this->user('ro', true, ['sso_subject' => 'missing']);
        $this->get('/sso/jwt-login?token='.urlencode($token))->assertForbidden()
            ->assertSee('Token SSO sudah pernah digunakan.');
        $this->assertGuest();
    }

    public function test_successful_sso_rotates_session_and_repeated_token_is_forbidden(): void
    {
        $user = $this->user();
        $this->withSession(['synthetic_marker' => 'preserved']);
        $oldId = session()->getId();
        $token = $this->signed($this->ssoClaims(['sub' => $user->sso_subject]));
        $this->get('/sso/jwt-login?token='.urlencode($token))->assertRedirect('/')
            ->assertSessionHas('synthetic_marker', 'preserved');
        $this->assertNotSame($oldId, session()->getId());
        $this->get('/sso/jwt-login?token='.urlencode($token))->assertForbidden();
    }

    public function test_sso_rejects_inactive_mismatched_and_unauthorized_roles(): void
    {
        foreach ([['ro', false, 'ro', false], ['ro', true, 'bm', false], ['outsider', true, 'outsider', false],
            ['ro', true, 'ro', true], ['admin', true, 'outsider', true]] as [$local, $active, $claimRole, $global]) {
            $user = $this->user($local, $active);
            $this->get('/sso/jwt-login?token='.urlencode($this->signed($this->ssoClaims([
                'sub' => $user->sso_subject, 'app_role' => $claimRole, 'is_global_admin' => $global,
            ]))))->assertForbidden();
            $this->assertGuest();
        }
    }

    public function test_global_admin_still_requires_an_allowed_claim_role_but_not_matching_local_role(): void
    {
        $user = $this->user('admin');
        $this->get('/sso/jwt-login?token='.urlencode($this->signed($this->ssoClaims([
            'sub' => $user->sso_subject, 'app_role' => 'bm', 'is_global_admin' => true,
        ]))))->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }
}
