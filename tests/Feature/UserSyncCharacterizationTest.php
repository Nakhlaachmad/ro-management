<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Support\IntegrationTestCase;

final class UserSyncCharacterizationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeOidc();
        $this->withToken($this->apiToken());
    }

    private function event(array $override = []): array
    {
        return array_replace([
            'event_id' => 'event-test', 'event_type' => 'user.created', 'sso_subject' => 'sync-subject',
            'expected_version' => 0, 'profile_version' => 1,
            'changes' => ['name' => ' Synthetic User ', 'email' => ' SYNC@EXAMPLE.TEST ', 'role' => 'ro', 'is_active' => true],
        ], $override);
    }

    private function sync(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->putJson('/api/v1/integrations/iwacs/users/sync-subject', $payload);
    }

    public function test_create_normalization_response_and_all_four_role_assignments(): void
    {
        foreach (['ro', 'bm', 'hrd', 'admin'] as $role) {
            $subject = 'created-'.$role;
            $payload = $this->event(['sso_subject' => $subject, 'changes' => [
                'name' => ' Synthetic User ', 'email' => ' '.$role.'@EXAMPLE.TEST ', 'role' => $role, 'is_active' => true,
                'password_hash' => Hash::make('Synthetic!123'),
            ]]);
            $this->putJson('/api/v1/integrations/iwacs/users/'.$subject, $payload)->assertCreated()->assertExactJson([
                'status' => 'created', 'event_id' => 'event-test', 'sso_subject' => $subject, 'profile_version' => 1,
            ]);
            $user = User::where('sso_subject', $subject)->firstOrFail();
            $this->assertSame('Synthetic User', $user->name);
            $this->assertSame($role.'@example.test', $user->email);
            $this->assertSame([$role], $user->getRoleNames()->all());
            $this->assertNull($user->email_verified_at);
            $this->assertTrue(Hash::check('Synthetic!123', $user->password));
        }
    }

    public function test_link_by_existing_email_preserves_password(): void
    {
        $user = $this->user('hrd', true, ['email' => 'sync@example.test', 'sso_subject' => null]);
        $password = $user->password;
        $this->sync($this->event())->assertCreated()->assertExactJson([
            'status' => 'linked', 'event_id' => 'event-test', 'sso_subject' => 'sync-subject', 'profile_version' => 1,
        ]);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($password, $user->fresh()->password);
        $this->assertSame(['ro'], $user->fresh()->getRoleNames()->all());
    }

    public function test_create_with_email_already_bound_to_other_subject_conflicts(): void
    {
        $this->user('ro', true, ['email' => 'sync@example.test', 'sso_subject' => 'other']);
        $this->sync($this->event())->assertConflict()->assertExactJson([
            'error' => 'email_already_exists', 'message' => 'Email sudah digunakan user RO lain.',
        ]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_update_deactivates_syncs_role_resets_email_verification_and_ignores_password_hash(): void
    {
        $user = $this->user('ro', true, ['sso_subject' => 'sync-subject']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $password = $user->password;
        $payload = $this->event(['event_type' => 'user.updated', 'expected_version' => 1, 'profile_version' => 2,
            'changes' => ['name' => ' Updated ', 'email' => ' UPDATED@EXAMPLE.TEST ', 'role' => 'bm', 'is_active' => false, 'password_hash' => Hash::make('Other!123')]]);
        $this->sync($payload)->assertOk()->assertExactJson([
            'status' => 'applied', 'event_id' => 'event-test', 'sso_subject' => 'sync-subject', 'profile_version' => 2,
            'applied_fields' => ['name', 'email', 'role', 'is_active'],
        ]);
        $updated = $user->fresh();
        $this->assertSame(2, $updated->profile_version);
        $this->assertFalse($updated->is_active);
        $this->assertSame(['bm'], $updated->getRoleNames()->all());
        $this->assertSame($password, $updated->password);
        $this->assertNull($updated->email_verified_at);
    }

    public function test_duplicate_is_version_based_even_with_different_event_id_and_payload(): void
    {
        $user = $this->user('ro', true, ['sso_subject' => 'sync-subject', 'profile_version' => 2]);
        $this->sync($this->event(['event_id' => 'different-event', 'event_type' => 'user.updated',
            'expected_version' => 1, 'profile_version' => 2, 'changes' => ['name' => 'Ignored']]))->assertExactJson([
                'status' => 'duplicate', 'event_id' => 'different-event', 'sso_subject' => 'sync-subject', 'profile_version' => 2,
            ]);
        $this->assertSame('Synthetic User', $user->fresh()->name);
    }

    public function test_stale_version_transition_and_missing_user_responses(): void
    {
        $this->sync($this->event(['event_type' => 'user.updated']))->assertNotFound()->assertExactJson([
            'error' => 'user_not_found', 'message' => 'User RO tidak ditemukan.',
        ]);
        $this->user('ro', true, ['sso_subject' => 'sync-subject', 'profile_version' => 3]);
        $this->sync($this->event(['event_type' => 'user.updated', 'expected_version' => 1, 'profile_version' => 2]))
            ->assertConflict()->assertExactJson(['error' => 'profile_version_conflict', 'message' => 'Versi user RO tidak sesuai.', 'current_version' => 3]);
        $this->sync($this->event(['event_type' => 'user.updated', 'expected_version' => 3, 'profile_version' => 5]))
            ->assertUnprocessable()->assertJsonPath('error', 'invalid_profile_version_transition');
    }

    public function test_baseline_can_replace_higher_version_and_password_for_existing_identity(): void
    {
        $user = $this->user('ro', true, ['sso_subject' => 'sync-subject', 'profile_version' => 8]);
        $payload = $this->event(['event_type' => 'user.baseline', 'changes' => [
            'name' => 'Baseline', 'email' => $user->email, 'role' => 'hrd', 'is_active' => false,
            'password_hash' => Hash::make('Baseline!123'),
        ]]);
        $this->sync($payload)->assertExactJson(['status' => 'baseline_applied', 'event_id' => 'event-test',
            'sso_subject' => 'sync-subject', 'profile_version' => 1]);
        $this->assertSame(1, $user->fresh()->profile_version);
        $this->assertTrue(Hash::check('Baseline!123', $user->fresh()->password));
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(['hrd'], $user->fresh()->getRoleNames()->all());
    }

    public function test_baseline_links_by_email_and_creates_when_identity_missing(): void
    {
        $user = $this->user('ro', true, ['email' => 'sync@example.test', 'sso_subject' => null]);
        $this->sync($this->event(['event_type' => 'user.baseline', 'expected_version' => 99, 'profile_version' => 7]))
            ->assertOk()->assertJsonPath('status', 'baseline_applied');
        $this->assertSame('sync-subject', $user->fresh()->sso_subject);
        $this->assertSame(7, $user->fresh()->profile_version);
        $payload = $this->event(['event_type' => 'user.baseline', 'sso_subject' => 'new-baseline',
            'changes' => ['name' => 'New', 'email' => 'new@example.test', 'role' => 'admin', 'is_active' => true]]);
        $this->putJson('/api/v1/integrations/iwacs/users/new-baseline', $payload)->assertOk()->assertJsonPath('status', 'baseline_applied');
        $this->assertDatabaseCount('users', 2);
    }

    public static function invalidChanges(): array
    {
        return [
            'unknown' => [['unknown' => 1], 'unsupported_fields'],
            'email' => [['email' => 'invalid'], 'invalid_email'],
            'name too long' => [['name' => str_repeat('x', 256)], 'invalid_name'],
            'whitespace name becomes null in middleware' => [['name' => ' '], 'missing_creation_fields'],
            'role' => [['role' => 'manager'], 'invalid_role'],
            'active string' => [['is_active' => 'false'], 'invalid_is_active'],
            'plaintext password' => [['password_hash' => 'plaintext'], 'invalid_password_hash'],
        ];
    }

    /** @dataProvider invalidChanges */
    public function test_changes_validation_response(array $changes, string $code): void
    {
        $this->sync($this->event(['changes' => $changes]))->assertUnprocessable()->assertJsonPath('error', $code);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_subject_initial_version_creation_fields_and_top_level_validation(): void
    {
        $this->sync($this->event(['sso_subject' => 'other']))->assertUnprocessable()->assertExactJson([
            'error' => 'subject_mismatch', 'message' => 'Subject path dan payload berbeda.',
        ]);
        $this->sync($this->event(['expected_version' => 1]))->assertUnprocessable()->assertJsonPath('error', 'invalid_initial_profile_version');
        $this->sync($this->event(['changes' => ['email' => 'a@example.test']]))->assertUnprocessable()
            ->assertJsonPath('error', 'missing_creation_fields')->assertJsonPath('fields', ['name']);
        $this->sync([])->assertUnprocessable()->assertJsonValidationErrors(['event_id', 'sso_subject', 'expected_version', 'profile_version', 'changes']);
    }

    public function test_update_email_conflict_does_not_mutate_identity(): void
    {
        $user = $this->user('ro', true, ['sso_subject' => 'sync-subject']);
        $this->user('ro', true, ['email' => 'used@example.test']);
        $this->sync($this->event(['event_type' => 'user.updated', 'expected_version' => 1, 'profile_version' => 2,
            'changes' => ['email' => 'used@example.test']]))->assertConflict()->assertJsonPath('error', 'email_already_exists');
        $this->assertSame(1, $user->fresh()->profile_version);
    }

    public function test_uppercase_role_passes_normalization_but_fails_role_lookup_currently(): void
    {
        $payload = $this->event(['changes' => ['name' => 'Test', 'email' => 'test@example.test', 'role' => 'RO', 'is_active' => true]]);
        $this->sync($payload)
            ->assertStatus(500);
        $this->assertDatabaseCount('users', 0); // Create transaction rolls back.
        $this->withoutExceptionHandling();
        $this->expectException(\Spatie\Permission\Exceptions\RoleDoesNotExist::class);
        $this->sync($payload);
    }
}
