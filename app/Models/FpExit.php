<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FpExit extends Model
{
    protected $table = 'fp_exits';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = ['reference_date' => 'immutable_date', 'exit_date' => 'immutable_date', 'processed_at' => 'immutable_datetime', 'type' => \App\Domain\FP\ExitType::class, 'duration_days' => 'integer'];

    public function membership(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FpMembership::class, 'membership_id', 'id');
    }
}
