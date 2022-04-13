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


    public static function getCouponFromStoreUrlAndCoupCode($couponCode, $storeUrl, $platform = 'fdo'): array
    {
        return optional(self::where(['code' => $couponCode, 'shop' => $storeUrl, 'type' => $platform])->first())->toArray() ?? [];
    }

    public static function getCouponCodeFromStoreIdandType($storeId, $type = 'fdo')
    {
        return optional(self::where('store_id', $storeId)->where('type', $type)->where('used', 1)->first())->code ?? null;
    }

    public static function updateCouponDetails($id, $startDate, $endDate)
    {
        self::where('id', $id)->update(['valid_from' => $startDate, 'valid_upto' => $endDate, 'used' => 1]);

    }

    public static function getFDOCoupon($storeId)
    {
        return Coupon::where('store_id', $storeId)->where('type', 'fdo')->first();

    }

    public static function getAvCoupon($storeId)
    {
        return Coupon::where('store_id', $storeId)->where('type', 'av')->first();

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
     * @param $storeId
     * @return array|mixed
     */
    public static function getCouponAv($storeId)
    {
        $storeUrl = Store::getStoreUrlFromStoreId($storeId);
        if (blank($storeUrl)) {
            return [];
        }
        $avEndpoint = Endpoints::getAvCouponEndpoint() . $storeUrl . '&marketplace=bc';
        $curlResponse = (new CurlRequest())->enSingleCurlRequest($avEndpoint, [], [], 'GET');
        $couponResponse = json_decode($curlResponse['response'], true);
        if (isset($couponResponse['promo'])) {
            return self::saveCoupon($couponResponse, $storeId, 'av');
        }
        return [];
    }

    /**
     * @param $couponResponse
     * @param $storeId
     * @return mixed
     */
    public static function saveCoupon($couponResponse, $storeId, $type = 'fdo')
    {
        $coupon = self::where(['store_id' => $storeId, 'type' => $type])->first();
        if ($coupon === null) {
            $coupon = new self();
        }
        $coupon->name = $couponResponse['message'] ?? '';
        $coupon->type = $type;
        $coupon->code = $couponResponse['promo']['coupon'] ?? '';
        $coupon->shop = $couponResponse['promo']['store_url'] ?? '';
        $coupon->store_id = $storeId;
        $coupon->save();
        return self::where('id', $coupon->id)->first();
    }
}

