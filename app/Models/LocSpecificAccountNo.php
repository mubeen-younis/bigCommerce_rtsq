<?php

namespace App\Models\LocSpecificAccountNo;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LocSpecificAccountNo extends Model
{
    use HasFactory;
    protected $table = 'account_number_associated_to_carriers';
    protected $fillable = [
        'location_id',
        'carriers_acc_number',
    ];

    public static function saveLocAccNo($request, $locatio)
    {


    }
}
