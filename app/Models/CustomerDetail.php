<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerDetail extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'customer_id',
        'shopify_customer_id',
        'metaobject_id',
        'company_name',
        'physician_name',
        'npi',
        'contact_name',
        'contact_email',
        'contact_phone_number',
        'sales_rep',
        'message',
        'po',
        'department',
    ];

    /**
     * Get the customer that owns the details.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
