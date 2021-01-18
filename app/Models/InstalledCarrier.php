<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstalledCarrier extends Model
{
    protected $guarded = [];
    public $timestamps = false;
    protected $fillable = [
        'store_id',
        'carrier_plan_id',
        'is_enabled'
    ];
}
