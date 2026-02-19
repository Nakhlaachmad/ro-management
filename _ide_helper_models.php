<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $alamat
 * @property string $no_tlfn
 * @property string $lulusan
 * @property \Illuminate\Support\Carbon $tanggal_masuk
 * @property \Illuminate\Support\Carbon|null $tanggal_keluar
 * @property int $team_id
 * @property string $status
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $comment
 * @property-read \App\Models\User|null $creator
 * @property-read \App\Models\Team $team
 * @method static \Illuminate\Database\Eloquent\Builder|Fp newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Fp newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Fp query()
 * @method static \Illuminate\Database\Eloquent\Builder|Fp whereAlamat($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Fp whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Fp whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Fp whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Fp whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Fp whereLulusan($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Fp whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Fp whereNoTlfn($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Fp whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Fp whereTanggalKeluar($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Fp whereTanggalMasuk($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Fp whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Fp whereUpdatedAt($value)
 */
	class Fp extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Fp> $fps
 * @property-read int|null $fps_count
 * @method static \Illuminate\Database\Eloquent\Builder|Team newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Team newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Team query()
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Team whereUpdatedAt($value)
 */
	class Team extends \Eloquent {}
}

namespace App\Models{
/**
 * @method bool can(string $ability, mixed $arguments = [])
 * @method bool hasPermissionTo(string $permission)
 * @method bool hasRole(string|array $roles)
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property mixed $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Permission\Models\Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Permission\Models\Role> $roles
 * @property-read int|null $roles_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|User permission($permissions, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder|User query()
 * @method static \Illuminate\Database\Eloquent\Builder|User role($roles, $guard = null, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|User withoutPermission($permissions)
 * @method static \Illuminate\Database\Eloquent\Builder|User withoutRole($roles, $guard = null)
 */
	class User extends \Eloquent {}
}

