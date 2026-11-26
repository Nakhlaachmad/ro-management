<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable;
    use HasRoles;
    use Authorizable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'sso_subject',
        'profile_version',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'profile_version' => 'integer',
        'is_active' => 'boolean',
    ];
    public function canAccessPanel(\Filament\Panel $panel): bool
    {
        // Semua role resmi dapat masuk panel, tetapi menu dan tindakan tetap
        // dibatasi oleh permission masing-masing di resource terkait.
        // Opt-in Victory-managed local accounts stay within the Victory panel.
        // Does not change SSO-linked or legacy-account eligibility.
        if (config('fp.user_roles_enabled') && $this->getAttribute('fp_app_managed') && $this->sso_subject === null && $panel->getId() !== 'fp-dev') {
            return false;
        }
        return $this->is_active && $this->hasAnyRole(['admin', 'hrd', 'bm', 'ro']);
    }

}
