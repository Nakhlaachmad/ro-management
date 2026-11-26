<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\FP\VictoryUsers;
use App\Filament\FP\Login;
use App\Filament\FP\RecruitmentChoice;
use App\Filament\FP\SubmissionUpdates;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class PackageFixesTest extends TestCase
{
    /**
     * Install only the schema this test class needs, using migrations from
     * this repository. No workspace outside the project is required.
     */
    private function installLocalSchema(): void
    {
        foreach ([
            '2014_10_12_000000_create_users_table.php',
            '2026_09_03_233000_add_iwacs_sync_fields_to_users_table.php',
            '2026_02_11_041952_create_permission_tables.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    private function actor(string $role = 'hrd'): \App\Models\User
    {
        config(['fp.enabled' => true, 'fp.core_schema_only' => false, 'fp.user_management_enabled' => true]);
        $actor = new \App\Models\User(['is_active' => true]);
        $actor->id = 99;
        $actor->setRelation('roles', new \Illuminate\Database\Eloquent\Collection([new \Spatie\Permission\Models\Role(['name' => $role, 'guard_name' => 'web'])]));
        auth()->setUser($actor);

        return $actor;
    }

    private function panel(): void
    {
        $panel = \Filament\Panel::make()->id('fp-dev')->path('fp-dev')->login(Login::class)->authGuard('web')->homeUrl('/fp-dev');
        \Filament\Facades\Filament::registerPanel($panel);
        \Filament\Facades\Filament::setCurrentPanel($panel);
        \Illuminate\Support\Facades\Route::get('/test/submissions/{record}', fn () => 'test')->name('filament.fp-dev.resources.fp-submissions.view');
    }

    public function test_local_login_rejects_uppercase_before_authentication(): void
    {
        $this->panel();
        Livewire::test(Login::class)->fillForm(['email' => 'UPPER@EXAMPLE.TEST', 'password' => 'Synthetic-password'])->call('authenticate')->assertHasFormErrors(['email']);
        self::assertGuest();
    }

    public function test_lowercase_login_reaches_normal_password_verification(): void
    {
        $this->panel();
        // Mock only the guard to prove credentials remain lowercase and the normal Filament flow is used.
        $guard = \Mockery::mock(\Illuminate\Contracts\Auth\StatefulGuard::class);
        $guard->shouldReceive('check')->andReturn(false);
        $guard->shouldReceive('attempt')->once()->with(['email' => 'lower@example.test', 'password' => 'Synthetic-password'], false)->andReturn(false);
        \Illuminate\Support\Facades\Auth::shouldReceive('guard')->with('web')->andReturn($guard);
        Livewire::test(Login::class)->fillForm(['email' => 'lower@example.test', 'password' => 'Synthetic-password'])->call('authenticate')->assertHasFormErrors(['email']);
    }

    public function test_lowercase_login_authenticates_a_synthetic_hrd_user(): void
    {
        $this->panel();
        config(['fp.enabled' => true, 'fp.core_schema_only' => false]);
        $this->installLocalSchema();
        $role = \Spatie\Permission\Models\Role::findOrCreate('hrd', 'web');
        $user = \App\Models\User::create(['name' => 'Synthetic login', 'email' => 'lower@example.test', 'password' => 'Synthetic-password', 'is_active' => true]);
        $user->assignRole($role);
        $this->app->bind(\Filament\Http\Responses\Auth\Contracts\LoginResponse::class, \Filament\Http\Responses\Auth\LoginResponse::class);
        Livewire::test(Login::class)->fillForm(['email' => 'lower@example.test', 'password' => 'Synthetic-password'])->call('authenticate')->assertHasNoFormErrors();
        self::assertAuthenticatedAs($user);
    }

    public function test_email_is_normalized_before_the_scoped_database_command(): void
    {
        $actor = $this->actor();
        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback, $attempts) {
            $vars = (new \ReflectionFunction($callback))->getStaticVariables();
            self::assertSame('synthetic@example.test', $vars['valid']['email']);
            self::assertSame(1, $attempts);
            self::assertArrayNotHasKey('role', $vars['valid']);
            self::assertArrayNotHasKey('password', $vars['valid']);
            self::assertTrue(\Illuminate\Support\Facades\Hash::check('Synthetic-password', $vars['valid']['password_hash']));

            return 101;
        });
        self::assertSame(101, (new VictoryUsers)->command($actor, 'CREATE', null, ['name' => 'Synthetic', 'email' => 'SYNTHETIC@EXAMPLE.TEST', 'role' => 'hrd', 'password' => 'Synthetic-password', 'password_confirmation' => 'Synthetic-password']));
    }

    public function test_non_hrd_role_is_rejected_before_any_database_command(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        (new VictoryUsers)->command($this->actor('admin'), 'CREATE', null, ['name' => 'Synthetic', 'email' => 'synthetic@example.test', 'role' => 'admin', 'password' => 'Synthetic-password', 'password_confirmation' => 'Synthetic-password']);
    }

    public function test_bm_still_cannot_create_users(): void
    {
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        (new VictoryUsers)->command($this->actor('bm'), 'CREATE', null, []);
    }

    public function test_combined_recruitment_preserves_both_existing_contracts(): void
    {
        self::assertSame(['recruitment_path_id' => 1, 'source_type' => 'HRD', 'source_team_id' => null, 'source_fp_id' => null], RecruitmentChoice::decode('1:HRD:0'));
        self::assertSame(8, RecruitmentChoice::decode('2:TEAM:8')['source_team_id']);
        self::assertSame(9, RecruitmentChoice::decode('3:FP:9')['source_fp_id']);
        foreach (['1:HRD:8', '1:TEAM:0', '1:MGM:1', '1:FP:0', 'bad'] as $bad) {
            try {
                RecruitmentChoice::decode($bad);
                self::fail('Malformed choice accepted');
            } catch (\Illuminate\Validation\ValidationException $e) {
                self::assertArrayHasKey('recruitment_choice', $e->errors());
            }
        }
        $fields = \App\Filament\FP\FpForms::source();
        self::assertCount(2, $fields);
        self::assertSame(['approval_mm_name_snapshot', 'recruitment_path_id'], array_map(fn ($field) => $field->getName(), $fields));
    }

    public function test_polling_uses_real_rows_and_deduplicates_status_changes(): void
    {
        $this->actor();
        $this->panel();
        \Illuminate\Support\Facades\Schema::create('fp_submissions', function ($t) {
            $t->id();
            $t->string('uuid');
            $t->string('status');
            $t->integer('biodata_revision')->default(1);
            $t->integer('document_revision')->default(1);
            $t->integer('biodata_verified_revision')->nullable();
            $t->integer('documents_verified_revision')->nullable();
            $t->timestamp('updated_at');
        });
        $component = Livewire::test(SubmissionUpdates::class)->assertSee('Belum ada pengajuan')->assertSeeHtml('wire:poll.15s');
        DB::table('fp_submissions')->insert(['id' => 1, 'uuid' => '00000000-0000-4000-8000-000000000001', 'status' => 'PENDING', 'updated_at' => '2026-10-13 00:00:00']);
        $component->call('$refresh')->assertSee('PENDING');
        \Filament\Notifications\Notification::assertNotified('Pengajuan baru menunggu validasi');
        $first = count(session('filament.notifications', []));
        $component->call('$refresh');
        self::assertCount($first, session('filament.notifications', []));
        DB::table('fp_submissions')->where('id', 1)->update(['status' => 'APPROVED']);
        $component->call('$refresh')->assertSee('APPROVED');
        \Filament\Notifications\Notification::assertNotified('Pengajuan disetujui');
        $second = count(session('filament.notifications', []));
        $component->call('$refresh');
        self::assertCount($second, session('filament.notifications', []));
        DB::table('fp_submissions')->where('id', 1)->update(['status' => 'REJECTED']);
        $component->call('$refresh')->assertSee('REJECTED');
        \Filament\Notifications\Notification::assertNotified('Pengajuan ditolak');
    }

    public function test_notification_component_denies_guest(): void
    {
        Livewire::test(SubmissionUpdates::class)->assertForbidden();
    }

    public function test_dates_and_document_viewer_keep_existing_storage_contracts(): void
    {
        $picker = \App\Filament\FP\FpForms::biodata()[8];
        self::assertInstanceOf(\Filament\Forms\Components\DatePicker::class, $picker);
        self::assertSame('d/m/Y', $picker->getDisplayFormat());
        self::assertSame('Y-m-d', $picker->getFormat());
        foreach (['app/Filament/Resources/FpSubmissionResource.php', 'app/Filament/Resources/FpMembershipResource.php', 'app/Filament/Resources/FpMembershipResource/Pages/ViewFpMembership.php'] as $file) {
            self::assertStringNotContainsString('openUrlInNewTab', file_get_contents(base_path($file)));
        }
        $teams = file_get_contents(base_path('app/Filament/Resources/FpTeamResource/Pages/ListFpTeams.php'));
        self::assertStringNotContainsString('Tambahkan profil Team', $teams);
    }

    public function test_active_directory_hides_exit_columns_and_table_document_action(): void
    {
        $this->actor();
        $this->panel();
        $table = \App\Filament\Resources\FpMembershipResource::table(\Filament\Tables\Table::make(new \App\Filament\Resources\FpMembershipResource\Pages\ListActiveFp));
        foreach (['exit.exit_date', 'exit.duration_days', 'exit.type', 'exit.reason'] as $column) {
            self::assertFalse($table->getColumns()[$column]->isVisible());
        }
        $record = new \App\Models\FpMembership(['submission_id' => 1]);
        $documents = collect($table->getActions())->first(fn ($action) => $action->getName() === 'documents');
        self::assertNotNull($documents);
        self::assertFalse($documents->record($record)->isVisible());
    }
}
