<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FpTeam extends Model
{
    protected $table = 'team_profiles';

    protected $primaryKey = 'team_id';

    protected $guarded = ['team_id'];

    protected $casts = ['is_active' => 'boolean'];

    public $incrementing = false;

    public function legacyTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id', 'id');
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(FpMembership::class, 'leader_fp_id', 'id');
    }

    public function mm(): BelongsTo
    {
        return $this->belongsTo(FpMembership::class, 'mm_fp_id', 'id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(FpMembership::class, 'team_id', 'team_id');
    }
}
