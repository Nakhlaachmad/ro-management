<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\FP\MembershipOrigin;
use App\Domain\FP\MembershipState;
use App\Domain\FP\RecruitmentSourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FpMembership extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('effective_fp_profile', function (\Illuminate\Database\Eloquent\Builder $query): void {
            if (config('fp.organization_enabled')) {
                $query->from('fp_current_memberships as financial_planners');
            }
        });
    }
    protected $table = 'financial_planners';

    protected $guarded = ['id', 'active_leader_key'];

    protected $hidden = ['bank_account_encrypted', 'active_leader_key'];

    protected $casts = [
        'origin' => MembershipOrigin::class, 'lifecycle_state' => MembershipState::class,
        'source_type' => RecruitmentSourceType::class, 'bank_account_encrypted' => 'encrypted', 'nip' => 'integer',
        'birth_date' => 'immutable_date', 'reference_date' => 'immutable_date', 'legacy_reference_date' => 'immutable_date',
        'approved_at' => 'immutable_datetime', 'graduation_year' => 'integer',
    ];

    public function recruitmentPath(): BelongsTo
    {
        return $this->belongsTo(FpRecruitmentPath::class, 'recruitment_path_id');
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(PersonIdentity::class, 'identity_id', 'id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FpSubmission::class, 'submission_id', 'id');
    }

    public function legacyFp(): BelongsTo
    {
        return $this->belongsTo(Fp::class, 'legacy_fp_id', 'id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(FpTeam::class, 'team_id', 'team_id');
    }

    public function sourceTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'source_team_id', 'id');
    }

    public function sourceFp(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_fp_id', 'id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'id');
    }

    public function currentClaim(): HasOne
    {
        return $this->hasOne(FpIdentityClaim::class, 'membership_id', 'id');
    }

    public function documents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FpDocument::class, 'membership_id', 'id');
    }

    public function exit(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(FpExit::class, 'membership_id', 'id');
    }
}
