<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Fp;

class Team extends Model
{
    protected $fillable = [
        'name',
        'team',
    ];

    public function fps(): HasMany
    {
        return $this->hasMany(Fp::class);
    }
    
}

