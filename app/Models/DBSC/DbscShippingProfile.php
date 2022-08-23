<?php

namespace App\Models\DBSC;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\DBSC\ShippingClass;

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
        $shippingClass = ShippingClass::where(['id' => $shippingClass])->select('class_name')->first() ?? [];
        if (blank($profiles)) {
            return null;
        }
        foreach ($profiles as $profile) {
            $shippingClasses = json_decode($profile['shipping_classes'], true);
            if (blank($shippingClasses)) {
                continue;
            }
            if (in_array($shippingClass['class_name'], $shippingClasses)) {
                return $profile['id'];
            }
        }
        return null;

    }


    protected static function getProfileRates($profileId, $zoneId, $storeId)
    {
        return optional(DbscShippingRates::join('dbsc_shipping_zone', 'dbsc_shipping_rates.dbsc_shipping_zone_id', 'dbsc_shipping_zone.id')
            ->join('dbsc_profiles', 'dbsc_profiles.id', 'dbsc_shipping_zone.profile_id')
            ->join('dbsc_origins', 'dbsc_origins.id', 'dbsc_shipping_zone.dbsc_origin_id')
            ->select('*')
            ->where([
                'dbsc_profiles.id' => $profileId,
                'dbsc_profiles.store_id' => $storeId,
                'dbsc_shipping_rates.dbsc_shipping_zone_id' => $zoneId,
            ])->get())->toArray() ?? [];
    }
}
