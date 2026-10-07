<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Tests\Support\IntegrationTestCase;

final class LogoutCharacterizationTest extends IntegrationTestCase
{
    private function fakeLogout(array $response, int $status = 200): void
    {
        $this->configureOutbound();
        Http::fake([
            'https://keycloak.test/discovery' => Http::response(['token_endpoint' => 'https://keycloak.test/token']),
            'https://keycloak.test/token' => Http::response(['access_token' => 'synthetic-service-token']),
            'https://iwacs.test/api/v1/integrations/ro/users/logout-ticket' => Http::response($response, $status),
        ]);
    }

    public function test_global_logout_handoff_invalidates_local_session(): void
    {
        $user = $this->user();
        $this->fakeLogout(['sso_subject' => $user->sso_subject, 'logout_url' => 'https://iwacs.test/logout?ticket=synthetic']);
        $this->actingAs($user)->withSession(['synthetic_marker' => 'clear-me']);
        $oldId = session()->getId();
        $oldToken = session()->token();
        $this->post('/sso/global-logout')->assertRedirect('https://iwacs.test/logout?ticket=synthetic')
            ->assertSessionMissing('synthetic_marker');
        $this->assertGuest();
        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
        Http::assertSentCount(3);
    }

    public function test_unlinked_user_logs_out_locally_without_external_requests(): void
    {
        Http::fake();
        $this->actingAs($this->user('ro', true, ['sso_subject' => null]))->withSession(['marker' => 'clear']);
        $this->post('/sso/global-logout')->assertRedirect('/login')->assertSessionMissing('marker');
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_external_failure_preserves_authenticated_session_and_flashes_error(): void
    {
        $user = $this->user();
        $this->fakeLogout(['message' => 'Synthetic upstream failure'], 503);
        $this->actingAs($user)->withSession(['marker' => 'keep']);
        $this->post('/sso/global-logout')->assertRedirect('/')->assertSessionHas('marker', 'keep')
            ->assertSessionHas('global_logout_error', 'Synthetic upstream failure');
        $this->assertAuthenticatedAs($user);
    }

    public function test_missing_or_invalid_logout_url_preserves_session(): void
    {
        $user = $this->user();
        $this->fakeLogout(['sso_subject' => $user->sso_subject, 'logout_url' => 'not-a-url']);
        $this->actingAs($user)->post('/sso/global-logout')->assertRedirect('/')
            ->assertSessionHas('global_logout_error', 'Respons logout IWACS tidak lengkap.');
        $this->assertAuthenticatedAs($user);
    }

    public function test_filament_builtin_logout_only_redirects_to_portal_login_without_ticket(): void
    {
        Http::fake();
        $this->actingAs($this->user())->withSession(['marker' => 'clear']);
        $this->post(route('filament.admin.auth.logout', [], false))->assertRedirect('https://iwacs.test/login')->assertSessionMissing('marker');
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_user_menu_renders_post_form_to_global_logout_not_a_get_link(): void
    {
        $this->actingAs($this->user());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $html = view('filament-panels::components.user-menu', ['attributes' => new \Illuminate\View\ComponentAttributeBag])->render();
        $this->assertStringContainsString('action="'.route('portal.sso.global-logout').'"', $html);
        $this->assertStringContainsString('method="post"', strtolower($html));
        $this->get('/sso/global-logout')->assertStatus(405);
    }

    public function test_guest_html_global_logout_fails_on_missing_named_login_route_currently(): void
    {
        $this->post('/sso/global-logout')->assertStatus(500);
        $this->withoutExceptionHandling();
        $this->expectException(\Symfony\Component\Routing\Exception\RouteNotFoundException::class);
        $this->expectExceptionMessage('Route [login] not defined.');
        $this->post('/sso/global-logout');
    }
}
