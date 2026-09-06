<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Receiver for one signed IWACS user event.  It only manages RO identities;
 * no FP, Team, or other RO business data is read or written here.
 */
final class UserSyncController extends Controller
{
    /** @var list<string> */
    private const FIELDS = ['name', 'email', 'role', 'is_active', 'password_hash'];

    /** @var list<string> */
    private const RO_ROLES = ['admin', 'hrd', 'bm', 'ro'];

    public function update(Request $request, string $subject): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'string', 'max:100'],
            'event_type' => ['sometimes', 'string', 'in:user.created,user.updated,user.baseline'],
            'sso_subject' => ['required', 'string', 'max:100'],
            'expected_version' => ['required', 'integer', 'min:0'],
            'profile_version' => ['required', 'integer', 'min:1'],
            'changes' => ['required', 'array'],
        ]);

        if (! hash_equals($subject, (string) $data['sso_subject'])) {
            return response()->json(['error' => 'subject_mismatch', 'message' => 'Subject path dan payload berbeda.'], 422);
        }

        $eventType = (string) ($data['event_type'] ?? 'user.updated');

        // A baseline is an explicitly requested one-time reconciliation of
        // RO login identities.  Unlike ordinary events it intentionally
        // adopts IWACS' current version rather than requiring a prior RO
        // version, so legacy accounts without an sso_subject can be linked.
        if ($eventType === 'user.baseline') {
            return $this->baselineUser($data, $subject);
        }

        $user = User::query()->where('sso_subject', $subject)->first();

        if (! $user instanceof User && $eventType === 'user.created') {
            return $this->createUser($data, $subject);
        }
        if (! $user instanceof User) {
            return response()->json(['error' => 'user_not_found', 'message' => 'User RO tidak ditemukan.'], 404);
        }

        $expectedVersion = (int) $data['expected_version'];
        $nextVersion = (int) $data['profile_version'];
        if ($nextVersion !== $expectedVersion + 1) {
            return response()->json(['error' => 'invalid_profile_version_transition', 'message' => 'profile_version harus tepat satu tingkat di atas expected_version.'], 422);
        }

        $normalized = $this->normaliseChanges((array) $data['changes']);
        if ($normalized instanceof JsonResponse) {
            return $normalized;
        }
        unset($normalized['password_hash']); // credential hashes are create-only.

        $currentVersion = (int) ($user->profile_version ?? 1);
        if ($currentVersion === $nextVersion) {
            return response()->json([
                'status' => 'duplicate',
                'event_id' => $data['event_id'],
                'sso_subject' => $subject,
                'profile_version' => $currentVersion,
            ]);
        }
        if ($currentVersion !== $expectedVersion) {
            return response()->json(['error' => 'profile_version_conflict', 'message' => 'Versi user RO tidak sesuai.', 'current_version' => $currentVersion], 409);
        }

        if (isset($normalized['email']) && User::query()
            ->whereRaw('LOWER(email) = ?', [$normalized['email']])
            ->where($user->getKeyName(), '<>', $user->getKey())
            ->exists()) {
            return response()->json(['error' => 'email_already_exists', 'message' => 'Email sudah digunakan user RO lain.'], 409);
        }

        $updated = DB::transaction(function () use ($user, $normalized, $expectedVersion, $nextVersion): bool {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            if ((int) ($locked->profile_version ?? 1) !== $expectedVersion) {
                return false;
            }
            $role = $normalized['role'] ?? null;
            unset($normalized['role']);
            $locked->fill($normalized);
            $locked->profile_version = $nextVersion;
            if (array_key_exists('email', $normalized)) {
                $locked->email_verified_at = null;
            }
            $locked->save();
            if (is_string($role)) {
                $locked->syncRoles([$role]);
            }

            return true;
        });

        if (! $updated) {
            return response()->json(['error' => 'profile_version_conflict', 'message' => 'profile_version berubah saat event diproses.'], 409);
        }

        return response()->json([
            'status' => 'applied',
            'event_id' => $data['event_id'],
            'sso_subject' => $subject,
            'profile_version' => $nextVersion,
            'applied_fields' => array_keys($normalized),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function createUser(array $data, string $subject): JsonResponse
    {
        if ((int) $data['expected_version'] !== 0 || (int) $data['profile_version'] !== 1) {
            return response()->json(['error' => 'invalid_initial_profile_version', 'message' => 'User baru wajib dimulai dari versi 1.'], 422);
        }
        $normalized = $this->normaliseChanges((array) $data['changes']);
        if ($normalized instanceof JsonResponse) {
            return $normalized;
        }
        foreach (['name', 'email', 'role', 'is_active'] as $required) {
            if (! array_key_exists($required, $normalized)) {
                return response()->json(['error' => 'missing_creation_fields', 'message' => 'Data user baru belum lengkap.', 'fields' => [$required]], 422);
            }
        }

        $existingEmail = User::query()->whereRaw('LOWER(email) = ?', [(string) $normalized['email']])->first();
        if ($existingEmail instanceof User) {
            if (blank($existingEmail->sso_subject)) {
                $existingEmail->sso_subject = $subject;
                $existingEmail->profile_version = 1;
                $existingEmail->fill(collect($normalized)->except(['role', 'password_hash'])->all());
                $existingEmail->save();
                $existingEmail->syncRoles([(string) $normalized['role']]);

                return response()->json(['status' => 'linked', 'event_id' => $data['event_id'], 'sso_subject' => $subject, 'profile_version' => 1], 201);
            }

            return response()->json(['error' => 'email_already_exists', 'message' => 'Email sudah digunakan user RO lain.'], 409);
        }

        $user = DB::transaction(function () use ($normalized, $subject): User {
            $role = (string) $normalized['role'];
            $passwordHash = $normalized['password_hash'] ?? null;
            unset($normalized['role'], $normalized['password_hash']);
            $user = new User();
            $user->fill($normalized);
            $user->sso_subject = $subject;
            $user->profile_version = 1;
            $user->password = is_string($passwordHash) && Hash::isHashed($passwordHash)
                ? $passwordHash
                : Hash::make(Str::random(64));
            $user->email_verified_at = null;
            $user->save();
            $user->syncRoles([$role]);

            return $user;
        });

        return response()->json([
            'status' => 'created',
            'event_id' => $data['event_id'],
            'sso_subject' => $subject,
            'profile_version' => (int) $user->profile_version,
        ], 201);
    }

    /** @param array<string, mixed> $data */
    private function baselineUser(array $data, string $subject): JsonResponse
    {
        $normalized = $this->normaliseChanges((array) $data['changes']);
        if ($normalized instanceof JsonResponse) {
            return $normalized;
        }

        foreach (['name', 'email', 'role', 'is_active'] as $required) {
            if (! array_key_exists($required, $normalized)) {
                return response()->json(['error' => 'missing_creation_fields', 'message' => 'Data baseline user belum lengkap.', 'fields' => [$required]], 422);
            }
        }

        $email = (string) $normalized['email'];
        $passwordHash = $normalized['password_hash'] ?? null;
        $user = User::query()->where('sso_subject', $subject)->first();

        if (! $user instanceof User) {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($user instanceof User && filled($user->sso_subject) && ! hash_equals((string) $user->sso_subject, $subject)) {
                return response()->json(['error' => 'email_already_exists', 'message' => 'Email sudah terhubung ke user RO lain.'], 409);
            }
        }

        $user = DB::transaction(function () use ($user, $normalized, $subject, $passwordHash, $data): User {
            $role = (string) $normalized['role'];
            $attributes = collect($normalized)->except(['role', 'password_hash'])->all();

            if (! $user instanceof User) {
                $user = new User();
                $user->sso_subject = $subject;
                $user->email_verified_at = null;
            }

            $emailChanged = strtolower((string) $user->email) !== (string) ($attributes['email'] ?? $user->email);
            $user->fill($attributes);
            $user->sso_subject = $subject;
            $user->profile_version = (int) $data['profile_version'];
            if (is_string($passwordHash) && Hash::isHashed($passwordHash)) {
                // IWACS is the canonical credential source for this explicit
                // reconciliation.  Plaintext credentials are never sent.
                $user->password = $passwordHash;
            } elseif (! $user->exists) {
                $user->password = Hash::make(Str::random(64));
            }
            if ($emailChanged) {
                $user->email_verified_at = null;
            }
            $user->save();
            $user->syncRoles([$role]);

            return $user;
        });

        return response()->json([
            'status' => 'baseline_applied',
            'event_id' => $data['event_id'],
            'sso_subject' => $subject,
            'profile_version' => (int) $user->profile_version,
        ], 200);
    }

    /** @param array<string, mixed> $changes
     *  @return array<string, mixed>|JsonResponse
     */
    private function normaliseChanges(array $changes): array|JsonResponse
    {
        $unknown = array_diff(array_keys($changes), self::FIELDS);
        if ($unknown !== []) {
            return response()->json(['error' => 'unsupported_fields', 'message' => 'Field sinkronisasi tidak didukung RO.', 'fields' => array_values($unknown)], 422);
        }
        foreach (['name', 'email', 'role'] as $field) {
            if (isset($changes[$field]) && is_string($changes[$field])) {
                $changes[$field] = trim($changes[$field]);
            }
        }
        if (isset($changes['email'])) {
            $changes['email'] = strtolower((string) $changes['email']);
            if (! filter_var($changes['email'], FILTER_VALIDATE_EMAIL)) {
                return response()->json(['error' => 'invalid_email', 'message' => 'Email user tidak valid.'], 422);
            }
        }
        if (isset($changes['name']) && ((string) $changes['name'] === '' || mb_strlen((string) $changes['name']) > 255)) {
            return response()->json(['error' => 'invalid_name', 'message' => 'Nama user wajib diisi.'], 422);
        }
        if (isset($changes['role']) && ! in_array(strtolower((string) $changes['role']), self::RO_ROLES, true)) {
            return response()->json(['error' => 'invalid_role', 'message' => 'Role tidak memiliki akses RO.'], 422);
        }
        if (isset($changes['is_active']) && ! is_bool($changes['is_active'])) {
            return response()->json(['error' => 'invalid_is_active', 'message' => 'Status user harus boolean.'], 422);
        }
        if (isset($changes['password_hash']) && (! is_string($changes['password_hash']) || ! Hash::isHashed($changes['password_hash']))) {
            return response()->json(['error' => 'invalid_password_hash', 'message' => 'Credential sinkronisasi tidak valid.'], 422);
        }

        return $changes;
    }
}
