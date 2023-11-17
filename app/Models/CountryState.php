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

    public static function getStateCode($states, $statesName)
    {
        $statesCode = [];
        if(!empty($states)){
            foreach($states as $state){
                if(in_array($state->name, $statesName)){
                    $statesCode[] = $state->code;
                }
            }
            return $statesCode;
        }
        return [];
    }

    public static function isSamePostalCode($postalCode, $postalCodesArray)
    {
        $ispCodeExist = false;
        if(!empty($postalCodesArray)){
            foreach($postalCodesArray as $pCode){
                
                if (ctype_digit($pCode) && strlen($pCode) == 5 && $postalCode == $pCode){
                // Checks: US postal code exist in shipping rule postal codes array
                    $ispCodeExist = true;
                } elseif (ctype_alnum($pCode) && strlen($pCode) == 6 && $postalCode == $pCode){
                // Checks: CA postal code exist in shipping rule postal codes array
                    $ispCodeExist = true;
                } elseif (strpos($pCode, '...') !== false && substr_count($pCode, '.') == 3) {
                // Checks: US and CA postal code exist in shipping rule postal code range like '10000...10009' , 'LK4M3C...LK4M4W'
                    $range = explode('...', $pCode);
                    if(ctype_digit($range[0]) && ctype_digit($range[1]) && strlen($range[0]) == 5 && strlen($range[1]) == 5){
                        $ispCodeExist = self::isInNumricRange($postalCode, $range[0], $range[1]);
                    }
                    
                    if(ctype_alnum($range[0]) && ctype_alnum($range[1]) && strlen($range[0]) == 6 && strlen($range[1]) == 6){
                        $ispCodeExist = self::isInAlphaNumricRange($postalCode, $range[0], $range[1]);
                    }
                } elseif (strpos($pCode, '*') !== false && substr_count($pCode, '*') == 1) {
                // Checks: US and CA postal code begins with define postal code in shipping rule like '1000*', 'Lk2*'
                    $range = explode('*', $pCode);
                    if(ctype_digit($range[0]) && strpos($postalCode, $range[0]) === 0 && strlen($range[0]) < 5){
                        $ispCodeExist = true;
                    }

                    if(preg_match('/[a-zA-Z].*[0-9]|[0-9].*[a-zA-Z]/', $range[0]) && strpos($postalCode, $range[0]) === 0 && strlen($range[0]) < 6){
                        $ispCodeExist = true;
                    }
                }
            }
        }
        return $ispCodeExist;
    }

    public static function isInNumricRange($number, $start, $end) {
        return $number >= $start && $number <= $end;
    }

    public static function isInAlphaNumricRange($value, $start, $end) {
        return strcmp($value, $start) >= 0 && strcmp($value, $end) <= 0;
    }
}
