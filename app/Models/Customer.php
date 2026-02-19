<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'shopify_id',
        'email',
        'first_name',
        'last_name',
        'discount_percentage',
        'shopify_discount_id',
        'discount_target_type',
        'discount_target_ids',
        'shopify_tags'
    ];

    protected $casts = [
        'discount_target_ids' => 'array'
    ];
}
