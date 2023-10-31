<?php

namespace App\Models;

use App\Constants\Constant;
use App\Helpers\Helper;
use App\Helpers\Helpers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Psy\Util\Str;

class CountryState extends Model
{


    protected $guarded = [];
    protected $table = "country_states";


    /**
     * @param $storeId
     * @return array
     */
    public static function getCountryStatesProvinces($countryCode): array
    {
        $rules = optional(self::where('country_code', $countryCode)->first())->toArray() ?? [];
        return json_decode($rules['country_states']);
    }


}
