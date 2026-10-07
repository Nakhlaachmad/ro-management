<?php

namespace Tests\Feature;

use App\Filament\Resources\FpResource;
use App\Filament\Resources\TeamResource;
use App\Filament\Resources\UserResource;
use App\Models\Fp;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\IntegrationTestCase;

final class AuthAndRoutesCharacterizationTest extends IntegrationTestCase
{
    public function test_protected_route_contract_snapshot(): void
    {
        $expected = [
            'portal.sso.jwt-login' => [['GET', 'HEAD'], 'sso/jwt-login', ['web', 'throttle:20,1']],
            'portal.sso.global-logout' => [['POST'], 'sso/global-logout', ['web', 'auth', 'throttle:10,1']],
            'api.integrations.iwacs.users.update' => [['PUT'], 'api/v1/integrations/iwacs/users/{subject}', ['api', 'api.jwt:users.sync']],
        ];
        foreach ($expected as $name => [$methods, $uri, $middleware]) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertSame($name, $route->getName());
            $this->assertSame($methods, $route->methods());
            $this->assertSame($uri, $route->uri());
            $this->assertSame($middleware, $route->gatherMiddleware());
        }
        $this->assertSame('[A-Za-z0-9._:-]{1,100}', app('router')->getRoutes()->getByName('api.integrations.iwacs.users.update')->wheres['subject']);
    }

    public function test_web_guard_authenticates_even_an_inactive_user_but_panel_denies_access(): void
    {
        $user = $this->user('ro', false);
        $this->assertTrue(Auth::guard('web')->attempt(['email' => $user->email, 'password' => 'Synthetic!123']));
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
        $this->get('/')->assertForbidden();
    }

    public function test_local_filament_login_accepts_active_and_rejects_inactive_user(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $active = $this->user();
        Livewire::test(\Filament\Pages\Auth\Login::class)
            ->fillForm(['email' => $active->email, 'password' => 'Synthetic!123'])
            ->call('authenticate')->assertHasNoFormErrors()->assertRedirect('/');
        $this->assertAuthenticatedAs($active);
        Auth::logout();
        $inactive = $this->user('ro', false);
        Livewire::test(\Filament\Pages\Auth\Login::class)
            ->fillForm(['email' => $inactive->email, 'password' => 'Synthetic!123'])
            ->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest();
    }

    public function test_sanctum_pat_accepts_active_and_inactive_currently_and_hides_credentials(): void
    {
        foreach ([true, false] as $active) {
            $user = $this->user('ro', $active);
            $token = $user->createToken('synthetic-characterization')->plainTextToken;
            $this->withToken($token)->getJson('/api/user')->assertOk()->assertJsonPath('id', $user->id)
                ->assertJsonPath('is_active', $active)->assertJsonMissingPath('password')->assertJsonMissingPath('remember_token');
            // Sanctum memoizes the guard identity within the application instance.
            Auth::forgetGuards();
        }
        $this->withToken('invalid-personal-token')->getJson('/api/user')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withoutToken()->getJson('/api/user')->assertUnauthorized();
    }

    public function test_existing_web_session_can_access_sanctum_user_endpoint(): void
    {
        $user = $this->user('bm');
        $this->actingAs($user)->getJson('/api/user')->assertOk()->assertJsonPath('id', $user->id);
    }

    public function test_panel_and_resources_use_existing_role_and_gate_rules(): void
    {
        $ro = $this->user('ro');
        $hrd = $this->user('hrd');
        foreach (['ro', 'bm', 'hrd', 'admin', 'outsider'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user);
            $this->assertSame($role !== 'outsider', $user->canAccessPanel(Filament::getPanel('admin')));
            $this->assertSame($role === 'admin', UserResource::canViewAny());
            $this->assertSame($role === 'admin', UserResource::canCreate());
            $this->assertSame($role === 'admin', UserResource::canEdit($ro));
            $this->assertFalse(UserResource::canEdit($hrd));
            $this->assertSame($role === 'admin', UserResource::canDelete($ro));
            $this->assertSame($role === 'admin', TeamResource::canViewAny());
            $this->assertSame($role === 'admin', TeamResource::canCreate());
            $this->assertTrue(FpResource::canViewAny());
            $this->assertFalse(FpResource::canCreate());
        }
    }

    public function test_seeded_space_permissions_do_not_grant_dotted_fp_gates(): void
    {
        $this->seed(RolePermissionSeeder::class);
        foreach (['admin', 'ro', 'hrd'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user);
            $this->assertSame($role !== 'hrd', $user->can('create fp'));
            $this->assertFalse(Gate::allows('fp.create'));
            $this->assertFalse(FpResource::canCreate());
        }
    }

    public function test_fp_edit_delete_require_active_status_even_when_dotted_permissions_exist(): void
    {
        $this->actingAs($this->user('admin'));
        Gate::define('fp.edit', fn () => true);
        Gate::define('fp.delete', fn () => true);
        $this->assertTrue(FpResource::canEdit(new Fp(['status' => 'aktif'])));
        $this->assertTrue(FpResource::canDelete(new Fp(['status' => 'aktif'])));
        $this->assertFalse(FpResource::canEdit(new Fp(['status' => 'nonaktif'])));
        $this->assertFalse(FpResource::canDelete(new Fp(['status' => 'nonaktif'])));
    }
}
