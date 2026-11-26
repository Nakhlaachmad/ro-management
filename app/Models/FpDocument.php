<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FpDocument extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('current_documents', function (\Illuminate\Database\Eloquent\Builder $query): void {
            if (\App\Application\FP\OnboardingRevision::enabled()) {
                $query->from('fp_current_documents as fp_documents');
            }
        });
    }

    protected $table = 'fp_documents';

    protected $guarded = ['id'];

    protected $hidden = ['private_path', 'sha256'];

    protected $casts = ['revision' => 'integer', 'bytes' => 'integer', 'processing_state' => \App\Domain\FP\DocumentProcessingState::class, 'deletion_state' => \App\Domain\FP\DocumentDeletionState::class, 'type' => \App\Domain\FP\DocumentType::class];

    public function submission(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FpSubmission::class, 'submission_id', 'id');
    }

    public function membership(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FpMembership::class, 'membership_id', 'id');
    }
}
