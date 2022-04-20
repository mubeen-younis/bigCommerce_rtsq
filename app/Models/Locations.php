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
        $locationDet = $additionals['origin_for_rates'] ?? null;
        if (blank($locationDet)) {
            return "default";
        }
        if ($locationDet == "default" || $locationDet == "suppress") {
            return $locationDet;
        }
        $location = optional(self::where('id', $locationDet)->select('id', 'city', 'state', 'zip_code', 'country', 'type')->first())->toArray() ?? [];
        if (blank($location)) {
            return "default";
        }
        return $location;
    }
}
