<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Locations extends Model
{
    protected $guarded = [];

    public static function getLocationAdditionalDetail($locationId)
    {
        $location = optional(self::where('id', $locationId)->first())->toArray() ?? [];
        if (blank($location)) {
            return "default";
        }
        $additionals = json_decode($location['additionals'], true);
        $locationDet = $additionals['instore_pickup_data']['default_location'] ?? null;
        if (blank($locationDet)) {
            return "default";
        }
        if ($locationDet == "default" || $locationDet == "suppress") {
            return $locationDet;
        }
        if ($locationDet == "other") {
            $otherLocDet = $additionals['instore_pickup_data'];
            return ['id' => "other", 'city' => $otherLocDet['instore_city'], 'state' => $otherLocDet['instore_state'], 'zip_code' => $otherLocDet['instore_postalCode'], 'country' => $otherLocDet['instore_country'], 'type' => "other"];
        }
        $location = optional(self::where('id', $locationDet)->select('id', 'city', 'state', 'zip_code', 'country', 'type')->first())->toArray() ?? [];
        if (blank($location)) {
            return "default";
        }
        return $location;
    }
}
