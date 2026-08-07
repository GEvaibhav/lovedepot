<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarrotJewelleryGoldRate extends Model
{
    protected $fillable = [
        'rate_per_gram',
        'source',
        'fetched_at',
    ];

    protected $casts = [
        'rate_per_gram' => 'float',
        'fetched_at' => 'datetime',
    ];
}
