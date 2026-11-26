<?php

declare(strict_types=1);

namespace App\Application\FP;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class VictoryUsers
{
    public static function creationRoles(User $actor): array
    {
        if (! config('fp.user_roles_enabled')) {
            return VictoryAccess::role($actor) && VictoryAccess::role($actor) !== 'bm' ? ['hrd' => 'HRD'] : [];
        }

        return match (VictoryAccess::role($actor)) {
            'admin' => ['admin' => 'IT / Admin', 'hrd' => 'HRD', 'ro' => 'RO', 'bm' => 'BM'],
            'hrd','ro' => ['hrd' => 'HRD', 'ro' => 'RO', 'bm' => 'BM'],
            default => [],
        };
    }

    public static function enabled(): bool
    {
        return config('fp.user_management_enabled') === true;
    }

    public static function readable(?User $actor): bool
    {
        return self::enabled() && VictoryAccess::allowed($actor, 'f3.users.view');
    }

    public static function managed(User $target): bool
    {
        if (! $target->getAttribute('fp_app_managed') || $target->sso_subject !== null) {
            return false;
        }
        $roles = $target->roles->pluck('name')->all();

        return $target->roles->every(fn ($role) => $role->guard_name === 'web') && $roles !== [] && array_diff($roles, ['admin', 'hrd', 'ro', 'bm']) === [];
    }

    public function command(User $actor, string $action, ?int $target, array $data): int
    {
        abort_unless(self::enabled(), 404);
        $operation = match ($action) {
            'CREATE' => 'f3.users.create','EDIT' => 'f3.users.edit','PERMISSIONS' => 'f3.users.permissions',default => throw new \Illuminate\Auth\Access\AuthorizationException('Unknown user action')
        };
        VictoryAccess::require($actor, $operation);
        $allowed = match ($action) {
            'CREATE' => ['name', 'email', 'password', 'password_confirmation', 'role'],'EDIT' => ['name', 'email'],'PERMISSIONS' => ['permissions']
        };
        if (array_diff(array_keys($data), $allowed)) {
            throw ValidationException::withMessages(['data' => 'Unsupported user fields']);
        }
        $rules = $action === 'PERMISSIONS' ? ['permissions' => 'present|array', 'permissions.*' => ['string', \Illuminate\Validation\Rule::in(FeatureAccess::PERMISSIONS)]] : ['name' => 'required|string|max:255', 'email' => 'required|email:rfc|max:255'];
        if ($action === 'CREATE') {
            $rules += ['password' => 'required|string|min:12|max:128|confirmed'];
            // Role selection is also independently checked by the scoped database command.
            $rules['role'] = ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(self::creationRoles($actor)))];
        }
        if (isset($data['email']) && is_string($data['email'])) {
            $data['email'] = \Illuminate\Support\Str::lower($data['email']);
        }
        $valid = Validator::make($data, $rules)->validate();
        if ($action === 'CREATE') {
            $valid['password_hash'] = Hash::make($valid['password']);
            unset($valid['password'],$valid['password_confirmation']);
            if (! config('fp.user_roles_enabled')) {
                unset($valid['role']);
            }

        }
        try {
            // PDO bindings avoid Laravel QueryException SQL/binding interpolation of password hashes.
            return DB::transaction(function () use ($actor, $action, $target, $valid) {
                $s = DB::connection()->getPdo()->prepare('SELECT public.fp_victory_user_command(?, ?, ?, ?::jsonb)');
                $s->execute([$actor->id, $action, $target, json_encode($valid, JSON_THROW_ON_ERROR)]);

                return (int) $s->fetchColumn();
            }, 1);
        } catch (\PDOException $e) {
            $state = $e->errorInfo[0] ?? $e->getCode();
            if ($state === '42501') {
                throw new \Illuminate\Auth\Access\AuthorizationException('User action not authorized');
            }
            if ($state === '23505') {
                throw ValidationException::withMessages(['email' => 'Email sudah digunakan. Gunakan email lain.']);
            }
            if ($state === '22023') {
                throw ValidationException::withMessages(['data' => 'User data rejected; check email and allowed fields.']);
            }
            throw new \RuntimeException('User command failed; no automatic retry.');
        } finally {
            unset($valid);
        }
    }
}
