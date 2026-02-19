<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingTier extends Model
{
    protected $fillable = [
        'name',
        'discount_type',
        'discount_value',
    ];

    public function customers()
    {
        return $this->hasMany(Customer::class, 'tier_id');
    }
}
