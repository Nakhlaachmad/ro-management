<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\FP\SubmissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FpSubmission extends Model
{
    protected $table = 'fp_submissions';

    protected $guarded = ['id'];

    protected $hidden = ['bank_account_encrypted'];

    protected $casts = [
        'status' => SubmissionStatus::class, 'bank_account_encrypted' => 'encrypted',
        'birth_date' => 'immutable_date', 'reference_date' => 'immutable_date',
        'submitted_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime',
        'biodata_verified_at' => 'immutable_datetime', 'documents_verified_at' => 'immutable_datetime',
        'biodata_revision' => 'integer', 'document_revision' => 'integer',
        'biodata_verified_revision' => 'integer', 'documents_verified_revision' => 'integer', 'graduation_year' => 'integer',
    ];

    public function identity(): BelongsTo
    {
        return $this->belongsTo(PersonIdentity::class, 'identity_id', 'id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id', 'id');
    }

    public function canonicalTeam(): BelongsTo
    {
        return $this->belongsTo(FpTeam::class, 'team_id', 'team_id');
    }

    public function membership(): HasOne
    {
        return $this->hasOne(FpMembership::class, 'submission_id', 'id');
    }

    public function biodataVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'biodata_verified_by', 'id');
    }

    public function documentVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'documents_verified_by', 'id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'id');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by', 'id');
    }

    public function documents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FpDocument::class, 'submission_id', 'id');
    }
}
