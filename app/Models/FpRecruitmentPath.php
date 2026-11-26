<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class FpRecruitmentPath extends Model
{
    protected $table = 'fp_recruitment_paths';

    protected $guarded = ['id', 'code', 'created_at'];

    protected $casts = ['is_active' => 'boolean'];
}
