<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\FP\IdentityClaimKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class FpIdentityClaim extends Model
{
    protected $table = 'fp_identity_claims';

    protected $primaryKey = 'identity_id';

    protected $guarded = ['*'];

    protected $casts = ['kind' => IdentityClaimKind::class];

    public $incrementing = false;

    public $timestamps = false;

    public function identity(): BelongsTo
    {
        return $this->belongsTo(PersonIdentity::class, 'identity_id', 'id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FpSubmission::class, 'submission_id', 'id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(FpMembership::class, 'membership_id', 'id');
    }

    public function save(array $options = []): never
    {
        throw new LogicException('Occupancy claims are maintained by database triggers.');
    }

    public function delete(): never
    {
        throw new LogicException('Occupancy claims are maintained by database triggers.');
    }
}
