<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Connection extends Model
{

    protected $guarded = [];

    protected $table = "connection_settings";


    public static function addPromoCodeInConnectionSettings($installedCarrierId, $promoCode)
    {
        $connectionSettings = self::where('installed_carrier_id', $installedCarrierId)->first();
        if ($connectionSettings === null) {
            $connectionSettings = new self();
            $value = json_encode(['promo_code' => $promoCode]);
        } else {
            $value = json_decode($connectionSettings->value, true);
            $value['promo_code'] = $promoCode;
            $value = json_encode($value);
        }
        $connectionSettings->installed_carrier_id = $installedCarrierId;
        $connectionSettings->value = $value;
        $connectionSettings->save();


    }

}
