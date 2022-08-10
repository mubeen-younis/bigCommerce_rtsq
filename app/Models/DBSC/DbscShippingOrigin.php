<?php

namespace App\Models\DBSC;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DbscShippingOrigin extends Model
{
    use HasFactory;
    protected $table = 'dbsc_shipping_origin';
    protected $fillable = [
        'nickname',
        'street_address',
        'city',
        'state_or_province',
        'postal_code',
        'country',
        'profile_id',
    ];
}
