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

    public static function getCouponFdo($storeId)
    {
        $storeUrl = Store::getStoreUrlFromStoreId($storeId);
        if (blank($storeUrl)) {
            return [];
        }
        $fdoEndpoint = Endpoints::getFDOCouponEndpoint() . $storeUrl;
        $curlResponse = (new CurlRequest())->enSingleCurlRequest($fdoEndpoint, [], [], 'GET');
        dd(123, $fdoEndpoint, $curlResponse);
    }
}

