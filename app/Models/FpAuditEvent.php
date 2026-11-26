<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FpAuditEvent extends Model
{
    protected $table = 'fp_audit_events';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = ['context' => 'array', 'occurred_at' => 'immutable_datetime', 'event' => \App\Domain\FP\AuditEventType::class];

    public function delete(): never
    {
        throw new \LogicException('Audit append-only');
    }
}
