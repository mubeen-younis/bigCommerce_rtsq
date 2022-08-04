<?php

namespace App\Models\DBSC;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DbscShippingRates extends Model
{
    use HasFactory;
    protected $table = 'dbsc_shipping_rates';
    protected $fillable = [
        'display_as',
        'rate',
    ];
}
