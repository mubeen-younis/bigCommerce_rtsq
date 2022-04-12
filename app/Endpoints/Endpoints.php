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

    public static function getFDORegisterUrl()
    {

        if (env('APP_ENV') == 'staging') {
            return "https://freightdesk.eniture-qa.com/register";
        }
        return "https://freightdesk.online/register";

    }

    public static function getFDOLoginUrl()
    {

        if (env('APP_ENV') == 'staging') {
            return "https://freightdesk.eniture-qa.com/login";
        }
        return "https://freightdesk.online/login";

    }

    public static function getAvCouponEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return "https://freightdesk.eniture-qa.com/use_coupon?shop=";

        }
        return "https://freightdesk.online/use_coupon?shop=";
    }


    public static function getAvRegisterUrl()
    {

        if (env('APP_ENV') == 'staging') {
            return "https://freightdesk.eniture-qa.com/register";
        }
        return "https://freightdesk.online/register";

    }

    public static function getAvLoginUrl()
    {

        if (env('APP_ENV') == 'staging') {
            return "https://freightdesk.eniture-qa.com/login";
        }
        return "https://freightdesk.online/login";

    }

    public static function updateProviderAvEndpoint()
    {

        if (env('APP_ENV') == 'staging') {
            return "https://freightdesk.eniture-qa.com/change_promo_code_status?";
        }
        return "https://freightdesk.eniture-qa.com/change_promo_code_status?";

    }
}
