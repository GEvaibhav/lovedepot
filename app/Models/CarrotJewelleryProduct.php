<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarrotJewelleryProduct extends Model
{
    protected $fillable = [
        'shopify_product_id',
        'shopify_product_numeric_id',
        'title',
        'gold_weight',
        'making_charge',
        'variants',
        'metafield_hash',
    ];

    protected $casts = [
        'variants' => 'array',
    ];
}
