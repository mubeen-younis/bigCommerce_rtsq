<?php

namespace App\Endpoints;

class Endpoints
{

    public static function getBCComEndpoint()
    {
        return "https://api.bigcommerce.com/stores/";
    }

    public static function getFDOCouponEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return "https://freightdesk.eniture-qa.com/use_coupon?shop=";

        }
        return "https://freightdesk.online/use_coupon?shop=";
    }

    public static function updateProviderFDOEndpoint()
    {

        if (env('APP_ENV') == 'staging') {
            return "https://freightdesk.eniture-qa.com/change_promo_code_status?";
        }
        return "https://freightdesk.eniture-qa.com/change_promo_code_status?";

    }
}
