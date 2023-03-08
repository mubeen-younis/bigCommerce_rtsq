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


    public static function getZoneIdFromDestinationAndProfile($destination, $profileId)
    {
        $profileZones = optional(self::where('profile_id', $profileId)->get())->toArray() ?? [];
        
        if (blank($profileZones)) {
            return [];
        }
        foreach ($profileZones as $profileZone) {
            $selectedRegion = preg_replace('/\\\"/',"\"", $profileZone['selected_region']);
            $selectedZones = json_decode($selectedRegion, true);
            $zonesDetail = BcZones::getZonesDetail($selectedZones);
            
            if (blank($zonesDetail)) {
                continue;
            }

            foreach ($zonesDetail as $zoneDetail) {
                if (self::compareDestinationWithZone($zoneDetail, $destination)) {
                    return $profileZone['id'];
                }
            }
        }
        return null;
    }


    /**
     * Comparing destination with zone to fetch the zone ID
     * @param $zoneDetail
     * @param $destination
     * @return bool|void
     */
    protected static function compareDestinationWithZone($zoneDetail, $destination)
    {
        // Match with Country
        if ($zoneDetail['country'] == $destination['country']) {
            // Returning true if all things are blank
            //Means Zone is for complete country
            if (blank($zoneDetail['state_or_province']) && blank($zoneDetail['city']) && blank($zoneDetail['postal_code'])) {
                return true;
            }

            // Comparing it with state
            if ($zoneDetail['state_or_province'] == $destination['state']) {
                // Returning true if all things are blank
                //Means Zone is for complete State
                if (blank($zoneDetail['city']) && blank($zoneDetail['postal_code'])) {
                    return true;
                }
            }

            if ($zoneDetail['city'] == $destination['city']) {
                return true;
            }

            if ($zoneDetail['postal_code'] == $destination['zip']) {
                return true;
            }
        }

        return false;

    }


}
