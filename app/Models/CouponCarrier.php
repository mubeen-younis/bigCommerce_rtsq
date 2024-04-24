<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CouponCarrier extends Model
{
    use HasFactory;

    protected $table = "coupon_code_carriers";
    public $timestamps = false;

    public static function getCarrierInfoByName($request)
    {
        $couponCarriers = [
            'small-package' => 'WWE_PL',
            'ltl-quotes' => 'WWE_LTL',
            'gtz-ltl' => 'GTZ',
            'unishippers-small' => 'UNI_PL',
        ];

        $carrierName = $request['carrier_name'] ?? '';
        $code = $request['coupon_code'] ?? '';
        $coupon = Coupon::where('code', $code)->first();
        if (in_array($carrierName, array_keys($couponCarriers)) && !empty($coupon)) {
            return self::where('carrier_name', $carrierName)->where('carrier_code', $couponCarriers[$carrierName])->where('coupon_code_id', $coupon->id)->first();
        }

        return null;
    }

    public static function getPromoCarriersInfo($request)
    {
        $couponCarriers = [
            'small-package' => 'WWE_PL',
            'ltl-quotes' => 'WWE_LTL',
            'gtz-ltl' => 'GTZ',
            'unishippers-small' => 'UNI_PL',
            'unishipper-ltl' => 'UNI_LTL'
        ];

        $code = $request['coupon_code'] ?? '';        
        if(!empty($code)){
            $coupon = Coupon::where('code', $code)->where('store_id', $request['store_id'])->first();
            return self::where('coupon_code_id', $coupon->id)->get()->toArray();
        }

        return null;
    }

    public static function addOrUpdateCarrierInfo($slug, $id, $code, $response)
    {
        /* $carrier = self::getCarrierInfoByName($slug);

         if (blank($carrier) || blank($id) || !$id) {
             $carrier = new self();
         }*/
        $carrier = self::where('coupon_code_id', $id)->where('carrier_name', $slug)->first();
        if ($carrier === null) {
            $carrier = new self();
        }
        $carrier->coupon_code_id = $id;
        $carrier->carrier_name = $slug;
        $carrier->carrier_code = $code ?? null;
        $carrier->is_enabled = $response['promo']['status'];
        $carrier->start_date = $response['promo']['start_date'];
        $carrier->end_date = $response['promo']['end_date'];
        $carrier->save();

        return $carrier;
    }
}
