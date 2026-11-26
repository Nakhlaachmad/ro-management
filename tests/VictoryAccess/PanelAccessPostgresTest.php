<?php

declare(strict_types=1);

namespace Tests\VictoryAccess;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

final class PanelAccessPostgresTest extends TestCase
{
    public function createApplication()
    {
        $root = dirname(__DIR__, 2);
        $app = require $root.'/bootstrap/app.php';
        $app->addAbsoluteCachePathPrefix('C:\\');
        $app->addAbsoluteCachePathPrefix('C:/');
        $private = dirname($root, 2).'/f3-6d/runtime';
        $app->useEnvironmentPath($private)->loadEnvironmentFrom('absent.env');
        $app->useStoragePath($private.'/storage');
        $app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app) {
            $c = $app['config'];
            $app->instance('env', 'local');
            $c->set('app.env', 'local');
            $c->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
            $c->set('app.debug', false);
            $c->set('app.url', 'http://127.0.0.1');
            // No privileged credentials in Laravel request configuration. Bootstrap supplies restricted PDO.
            $c->set('database.default', 'pgsql');
            $c->set('database.connections.pgsql', ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55432, 'database' => 'fp_f2_test_20261009', 'username' => 'fp_dev_app', 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public']);
            $c->set('fp.enabled', true);
            $c->set('fp.core_schema_only', false);
            $c->set('fp.user_management_enabled', true);
            $c->set('fp.nik_lookup_key', 'synthetic-test-only-key-0000000000000000');
            $c->set('fp.nik_lookup_key_id', 'synthetic-test');
            $c->set('fp.business_timezone', 'UTC');
            $c->set('queue.default', 'sync');
            $c->set('session.driver', 'array');
            $c->set('cache.default', 'array');
            $c->set('logging.default', 'null');
            $c->set('services.iwacs_ro.enabled', false);
            $providers = $c->get('app.providers');
            $providers[] = \App\Providers\FpServiceProvider::class;
            $providers[] = \App\Providers\Filament\FpDevelopmentPanelProvider::class;
            $c->set('app.providers', $providers);
        });
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $pdo = new \PDO('pgsql:host=127.0.0.1;port=55432;dbname=fp_f2_test_20261009', (string) getenv('FP_TEST_USER'), (string) getenv('FP_TEST_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET ROLE fp_dev_app; SET search_path TO public');
        $id = $pdo->query('SELECT current_database() db,current_user role,host(inet_server_addr()) host,inet_server_port() port')->fetch(\PDO::FETCH_ASSOC);
        if ($id !== ['db' => 'fp_f2_test_20261009', 'role' => 'fp_dev_app', 'host' => '127.0.0.1', 'port' => 55432]) {
            throw new \RuntimeException('Restricted request connection mismatch');
        }
        $app['db']->connection()->setPdo($pdo)->setReadPdo($pdo);
        \Illuminate\Support\Facades\Http::preventStrayRequests();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Test-case sessions and in-process requests; CSRF behavior is covered by prior onboarding tests.
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('fp-dev'));
        \Filament\Facades\Filament::bootCurrentPanel();
    }

    private function fixture(string $key)
    {
        return $GLOBALS['victory_disposable_fixture'][$key];
    }

    private function asRole(string $role): User
    {
        session()->flush();
        $actor = User::findOrFail($this->fixture($role));
        $this->actingAs($actor, 'web');

        return $actor;
    }

    public function test_all_four_roles_can_read_every_victory_menu_and_user_detail(): void
    {
        foreach (['admin', 'hrd', 'ro', 'bm'] as $role) {
            $this->asRole($role);
            foreach (['/fp-dev', '/fp-dev/victory-users', '/fp-dev/fp-team-profiles', '/fp-dev/fp-memberships', '/fp-dev/fp-memberships/active', '/fp-dev/fp-memberships/exited-normal', '/fp-dev/fp-memberships/exited-abnormal', '/fp-dev/fp-submissions', '/fp-dev/fp-recruitment-paths', '/fp-dev/reports'] as $url) {
                $r = $this->get($url);
                self::assertSame(200, $r->status(), $role.' '.$url);
            }
            $this->get('/fp-dev/victory-users/'.$this->fixture('user'))->assertOk()->assertSee('HTTP managed target');
            $this->get('/fp-dev')->assertSee('Dashboard')->assertSee('User')->assertSee('Team')->assertSee('FP Aktif')->assertSee('Validasi Pendaftar')->assertSee('Master Jalur')->assertSee('Laporan');
        }
    }

    public function test_bm_full_biodata_nip_private_document_and_no_store(): void
    {
        $this->asRole('bm');
        $response = $this->get('/fp-dev/fp-memberships/'.$this->fixture('membership'))->assertOk();
        $response->assertSee($this->fixture('nik'))->assertSee($this->fixture('bank'))->assertSee('Synthetic address');
        $response->assertSee($this->fixture('nip'))->assertSee('Disposable exit history');
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->get('/fp-dev/fp-submissions/'.$this->fixture('submission'))->assertOk()->assertSee($this->fixture('nik'));
        foreach (DB::table('fp_documents')->where('membership_id', $this->fixture('membership'))->pluck('uuid') as $uuid) {
            $preview = $this->get('/fp-private/documents/'.$uuid)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
            self::assertTrue($preview->headers->hasCacheControlDirective('no-store'));
        }
        $uuid = DB::table('fp_submissions')->where('id', $this->fixture('submission'))->value('uuid');
        $viewer = $this->get('/fp-private/review/'.$uuid)->assertOk();
        self::assertTrue($viewer->headers->hasCacheControlDirective('no-store'));
    }

    public function test_bm_csv_xlsx_exports_forbidden_hrd_ro_admin_allowed(): void
    {
        $this->asRole('bm');
        foreach (['csv', 'xlsx'] as $format) {
            $this->get('/fp-private/reports/export?format='.$format)->assertForbidden();
        }
        foreach (['admin', 'hrd', 'ro'] as $role) {
            $this->asRole($role);
            $this->get('/fp-private/reports/export?format=csv')->assertOk();
        }
    }

    public function test_bm_livewire_user_and_domain_mutations_return_403(): void
    {
        $this->asRole('bm');
        $before = DB::table('fp_user_events')->count();
        $nip = DB::table('nip_sequences')->value('next_value');
        Livewire::test(\App\Filament\Resources\VictoryUserResource\Pages\ListVictoryUsers::class)->assertActionHidden('createLocal')->assertTableActionHidden('editLocal', (string) $this->fixture('user'))->assertTableActionHidden('permissionsLocal', (string) $this->fixture('user'));
        Livewire::test(\App\Filament\Resources\VictoryUserResource\Pages\ListVictoryUsers::class)->call('mountAction', 'createLocal')->assertStatus(403);
        Livewire::test(\App\Filament\Resources\VictoryUserResource\Pages\ListVictoryUsers::class)->call('mountTableAction', 'editLocal', (string) $this->fixture('user'))->assertStatus(403);
        foreach (['correct', 'verifyBiodata', 'verifyDocuments', 'approve', 'reject'] as $action) {
            Livewire::test(\App\Filament\Resources\FpSubmissionResource\Pages\ListFpSubmissions::class)->call('mountTableAction', $action, (string) $this->fixture('pending'))->assertStatus(403);
        }
        Livewire::test(\App\Filament\Resources\FpMembershipResource\Pages\ListFpMemberships::class)->call('mountTableAction', 'exit', (string) $this->fixture('membership'))->assertStatus(403);
        Livewire::test(\App\Filament\Resources\FpTeamResource\Pages\ListFpTeams::class)->call('mountAction', 'newTeam')->assertStatus(403);
        Livewire::test(\App\Filament\Resources\FpRecruitmentPathResource\Pages\ListRecruitmentPaths::class)->call('mountAction', 'createPath')->assertStatus(403);
        Livewire::test(\App\Filament\Resources\VictoryUserResource\Pages\ListVictoryUsers::class)->call('mountTableAction', 'permissionsLocal', (string) $this->fixture('user'))->assertStatus(403);
        Livewire::test(\App\Filament\Resources\FpSubmissionResource\Pages\ViewFpSubmission::class, ['record' => $this->fixture('pending')])->call('mountAction', 'approve')->assertStatus(403);
        Livewire::test(\App\Filament\Resources\VictoryUserResource\Pages\ListVictoryUsers::class)->set('mountedActions', ['createLocal'])->call('callMountedAction')->assertStatus(403);
        Livewire::test(\App\Filament\Resources\FpSubmissionResource\Pages\ListFpSubmissions::class)->set('mountedTableActions', ['approve'])->call('callMountedTableAction')->assertStatus(403);
        self::assertSame($before, DB::table('fp_user_events')->count());
        self::assertSame($nip, DB::table('nip_sequences')->value('next_value'));
    }

    public function test_hrd_ro_livewire_create_edit_and_admin_only_permission_mutation(): void
    {
        foreach (['hrd', 'ro'] as $role) {
            $this->asRole($role);
            $secret = bin2hex(random_bytes(16));
            $email = \Illuminate\Support\Str::uuid().'@example.invalid';
            Livewire::test(\App\Filament\Resources\VictoryUserResource\Pages\ListVictoryUsers::class)->callAction('createLocal', ['name' => 'Livewire '.$role, 'email' => $email, 'password' => $secret, 'password_confirmation' => $secret])->assertHasNoActionErrors();
            $target = DB::table('users')->where('email', $email)->value('id');
            self::assertNotNull($target);
            Livewire::test(\App\Filament\Resources\VictoryUserResource\Pages\ListVictoryUsers::class)->callTableAction('editLocal', (string) $target, ['name' => 'Edited '.$role, 'email' => $email])->assertHasNoTableActionErrors();
            self::assertSame('Edited '.$role, DB::table('users')->where('id', $target)->value('name'));
            Livewire::test(\App\Filament\Resources\VictoryUserResource\Pages\ListVictoryUsers::class)->call('mountTableAction', 'permissionsLocal', (string) $target)->assertStatus(403);
        }
        $this->asRole('admin');
        Livewire::test(\App\Filament\Resources\VictoryUserResource\Pages\ListVictoryUsers::class)->callTableAction('permissionsLocal', (string) $this->fixture('user'), ['permissions' => ['f2.view']])->assertHasNoTableActionErrors();
        self::assertSame(1, DB::table('model_has_permissions')->where('model_id', $this->fixture('user'))->where('model_type', User::class)->count());
    }

    public function test_legacy_target_hidden_guest_denied_public_does_not_expose_private_information(): void
    {
        $this->asRole('admin');
        $this->get('/fp-dev/victory-users/'.$this->fixture('ro'))->assertNotFound();
        // A full-page Livewire mount aborted by the prior 404 leaves its test redirector bound.
        // Restore the normal HTTP redirector between requests, as a fresh runtime request would.
        $redirector = new \Illuminate\Routing\Redirector($this->app['url']);
        $redirector->setSession($this->app['session.store']);
        $this->app->instance('redirect', $redirector);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('redirect');
        auth()->logout();
        $guest = $this->get('/fp-dev/victory-users');
        if ($guest->status() === 500) {
            throw new \RuntimeException('Guest panel error: '.get_class($guest->exception).' '.($guest->exception instanceof \Illuminate\Database\QueryException ? $guest->exception->getCode() : $guest->exception->getMessage()));
        } $guest->assertRedirect();
        $this->get('/fp-private/documents/'.$this->fixture('document'))->assertRedirect();
        $this->get('/fp-onboarding')->assertOk()->assertDontSee($this->fixture('nik'))->assertDontSee($this->fixture('bank'))->assertDontSee('NIP')->assertDontSee('Grade')->assertDontSee('Jalur');
    }
}
