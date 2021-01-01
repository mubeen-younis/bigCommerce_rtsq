<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdditionalCarrierTabSetting extends Model
{
    //
    protected $fillable = [
        'carrier_id',
        'store_id',
        'value'
    ];
}
