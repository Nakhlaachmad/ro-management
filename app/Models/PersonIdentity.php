<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\FP\IdentityReconciliationState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PersonIdentity extends Model
{
    protected $table = 'person_identities';

    protected $guarded = ['id'];

    protected $hidden = ['nik_encrypted', 'nik_lookup_hash', 'lookup_key_id'];

    protected $casts = ['nik_encrypted' => 'encrypted', 'reconciliation_state' => IdentityReconciliationState::class];

    public function submissions(): HasMany
    {
        return $this->hasMany(FpSubmission::class, 'identity_id', 'id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(FpMembership::class, 'identity_id', 'id');
    }

    public function currentClaim(): HasOne
    {
        return $this->hasOne(FpIdentityClaim::class, 'identity_id', 'id');
    }
}
