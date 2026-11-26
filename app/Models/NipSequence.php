<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NipSequence extends Model
{
    protected $table = 'nip_sequences';

    protected $guarded = ['id'];

    protected $casts = ['next_value' => 'integer'];

    public $incrementing = false;

    public $timestamps = false;
}
