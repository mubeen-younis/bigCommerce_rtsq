<?php

namespace App\Models\DBSC;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DbscShippingZone extends Model
{
    use HasFactory;

    protected $table = 'dbsc_shipping_zone';
    protected $fillable = [
        'zone_name',
        'selected_region',
        'postcode',
        'profile_id',
    ];


    public static function getZoneIdFromDestination($destination)
    {
        return 5;
    }

}
