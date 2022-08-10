<?php

namespace App\Models\DBSC;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DbscShippingProfile extends Model
{
    use HasFactory;

    protected $table = 'dbsc_profiles';
    protected $fillable = [
        'p_nickname',
        'shipping_classes',
    ];


    /**
     * @param $storeId
     * @return array
     */
    protected static function getGeneralProfileSettings($storeId): array
    {
        return optional(self::where(['store_id' => $storeId, 'is_general_profile' => 1])->first())->toArray() ?? [];
    }

    /**
     * @return void
     */
    protected static function getProfileIdOfShippingClass($shippingClass, $storeId)
    {
        $profiles = optional(self::where(['store_id' => $storeId, 'is_general_profile' => 0])->select('id', 'shipping_classes')->get())->toArray() ?? [];
        if (blank($profiles)) {
            return null;
        }
        foreach ($profiles as $profile) {
            $shippingClasses = json_decode($profile['shipping_classes'], true);
            if (blank($shippingClasses)) {
                continue;
            }
            if (in_array($shippingClass, $shippingClasses)) {
                return $profile['id'];
            }
        }
        return null;

    }


    protected static function getProfileRates($profileId, $zoneId, $storeId)
    {
        return optional(DbscShippingRates::join('dbsc_shipping_zone', 'dbsc_shipping_zone.id', 'dbsc_shipping_rates.dbsc_zone_id')
            ->join('dbsc_profiles', 'dbsc_profiles.id', 'dbsc_shipping_zone.profile_id')
            ->join('dbsc_shipping_origin', 'dbsc_shipping_origin.id', 'dbsc_shipping_zone.dbsc_shipping_origin_id')
            ->select('dbsc_shipping_rates.*', 'dbsc_shipping_origin.*', 'dbsc_shipping_origin.id as dbsc_shipping_origin_id', 'dbsc_shipping_rates.id as rate_id')
            ->where(['dbsc_profiles.id' => $profileId,
                'dbsc_profiles.store_id' => $storeId,
                'dbsc_shipping_rates.dbsc_zone_id' => $zoneId,
            ])->get())->toArray() ?? [];
    }
}
