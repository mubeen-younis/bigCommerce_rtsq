<?php

namespace App\Models;

use App\CurlRequest;
use App\Endpoints\Endpoints;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    protected $table = "coupon_code";
    protected $fillable = ['store_id', 'valid_from', 'valid_upto', 'used'];


    public static function getCouponFromStoreUrlAndCoupCode($couponCode, $storeUrl): array
    {
        return optional(self::where(['code' => $couponCode, 'shop' => $storeUrl])->first())->toArray() ?? [];
    }

    public static function updateCouponDetails($id, $startDate, $endDate)
    {
        self::where('id', $id)->update(['valid_from' => $startDate, 'valid_upto' => $endDate, 'used' => 1]);

    }

    /**
     * @param $storeId
     * @return array|mixed
     */
    public static function getCouponFdo($storeId)
    {
        $storeUrl = Store::getStoreUrlFromStoreId($storeId);
        if (blank($storeUrl)) {
            return [];
        }
        $fdoEndpoint = Endpoints::getFDOCouponEndpoint() . $storeUrl . '&marketplace=bc';
        $curlResponse = (new CurlRequest())->enSingleCurlRequest($fdoEndpoint, [], [], 'GET');
        $couponResponse = json_decode($curlResponse['response'], true);
        if (isset($couponResponse['promo'])) {
            return self::saveCoupon($couponResponse, $storeId);
        }
        return [];
    }

    /**
     * @param $couponResponse
     * @param $storeId
     * @return mixed
     */
    public static function saveCoupon($couponResponse, $storeId)
    {
        $coupon = Coupon::firstOrCreate([
            'store_id' => $storeId,
        ]);
        $coupon->name = $couponResponse['message'] ?? '';
        $coupon->code = $couponResponse['promo']['coupon'] ?? '';
        $coupon->shop = $couponResponse['promo']['store_url'] ?? '';
        $coupon->store_id = $storeId;
        $coupon->save();
        return self::where('id', $coupon->id)->first();
    }
}

