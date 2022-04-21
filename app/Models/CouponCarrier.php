<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CouponCarrier extends Model
{
    use HasFactory;

    protected $table = "coupon_code_carriers";

    public static function getCarrierInfoByName($carrierName)
    {
        $couponCarriers = [
            'small-package' => 'WWE_PL',
            'ltl-quotes' => 'WWE_LTL',
            'gtz-ltl' => 'GTZ',
            'unishippers-small' => 'UNI_PL',
        ];

        if (in_array($carrierName, array_keys($couponCarriers))) {
            return self::where('carrier_name', $carrierName)->where('carrier_code', $couponCarriers[$carrierName])->first();
        }

        return null;
    }

    public static function addOrUpdateCarrierInfo($slug, $id, $code, $response)
    {
        $carrier = self::getCarrierInfoByName($slug);
        if (blank($carrier)) {
            $carrier = new self();
        }

        $carrier->coupon_code_id = $id;
        $carrier->carrier_name = $slug;
        $carrier->carrier_code = $code ?? null;
        $carrier->is_enabled = 1;
        $carrier->start_date = $response['promo']['start_date'];
        $carrier->end_date = $response['promo']['end_date'];
        $carrier->save();

        return $carrier;
    }
}
