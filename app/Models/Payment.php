<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id',
        'transaction_id',
        'payment_method',
        'amount',
        'payment_status',
        'paid_at'
    ];
}
