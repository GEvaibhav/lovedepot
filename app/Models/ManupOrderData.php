<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManupOrderData extends Model
{
    protected $table = 'manup_order_data';

    protected $fillable = [
        'order_id',
        'request_payload',
        'webhook_payload',
        'status',
        'prescription_image',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'webhook_payload' => 'array',
    ];
}
