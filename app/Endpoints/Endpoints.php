<?php

namespace App\Endpoints;

class Endpoints
{
    public static $qaUrl = "https://ws001.eniture-qa.com/";

    public static $prodUrl = "https://eniture.com/";

    public static function getBCComEndpoint()
    {
        return "https://api.bigcommerce.com/stores/";
    }


    public static function testConnectionEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return self::$qaUrl;
        }
        return self::$prodUrl . "ws/index.php";
    }
    public static function PurolatorTtestEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return self::$qaUrl . "index.php";
        }
        return self::$prodUrl . "index.php";
    }

    public static function wweSmallTestEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return self::$qaUrl . "ws/carriers/wwe-small/speedshipTest.php";
        }
        return self::$prodUrl . "ws/carriers/wwe-small/speedshipTest.php";
    }

    public static function wweLtlTestEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return self::$qaUrl . "ws/carriers/wwe-freight/speedfreightTest.php";
        }
        return self::$prodUrl . "ws/carriers/wwe-freight/speedfreightTest.php";
    }

    public static function upsSmallTestEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return self::$qaUrl . "ws/s/ups/auth.php";
        }
        return self::$prodUrl . "ws/s/ups/auth.php";
    }

    public static function getFDOCouponEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return "https://freightdesk.eniture-qa.com/use_coupon?shop=";

        }
        return "https://freightdesk.online/use_coupon?shop=";
    }

    public static function applyPromoCodeFdoEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return "https://freightdesk.eniture-qa.com/apply_promo_code?";

        }
        return "https://freightdesk.online/apply_promo_code?";
    }

    public static function updateProviderFDOEndpoint()
    {

        if (env('APP_ENV') == 'staging') {
            return "https://freightdesk.eniture-qa.com/change_promo_code_status?";
        }
        return "https://freightdesk.online/change_promo_code_status?";

    }

    public static function getFDORegisterUrl()
    {

        if (env('APP_ENV') == 'staging') {
            return "https://freightdesk.eniture-qa.com/register";
        }
        return "https://freightdesk.online/register";

    }


    public static function verifyFdoCompDetEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return "https://freightdesk.eniture-qa.com/validate-bc-company";
        }
        return "https://freightdesk.online/validate-bc-company";

    }


    public static function fdoCredsEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return "https://freightdesk.eniture-qa.com/fdo-bc-connection";
        }
        return "https://freightdesk.online/fdo-bc-connection";

    }

    public static function getFDOLoginUrl()
    {

        if (env('APP_ENV') == 'staging') {
            return "https://freightdesk.eniture-qa.com/login";
        }
        return "https://freightdesk.online/login";

    }

    public static function verifyAvCompDetEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return "https://address-validation.eniture-dev3.com/validateCompany";
        }
        return "https://validate-addresses.com/validateCompany";

    }

    public static function avCredsEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return "https://address-validation.eniture-dev3.com/connectionFromBC";
        }
        return "https://validate-addresses.com/connectionFromBC";

    }


    public static function disconnectVACompDetEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return "https://address-validation.eniture-dev3.com/disconnectConnectionBC";
        }
        return "https://validate-addresses.com/disconnectConnectionBC";

    }


    public static function getAvCouponEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return "https://address-validation.eniture-dev3.com/use_coupon?shop=";
        }
        return "https://validate-addresses.com/use_coupon?shop=";
    }


    public static function getAvRegisterUrl()
    {

        if (env('APP_ENV') == 'staging') {
            return "https://address-validation.eniture-dev3.com/register";
        }
        return "https://validate-addresses.com/register";

    }

    public static function getAvLoginUrl()
    {

        if (env('APP_ENV') == 'staging') {
            return "https://address-validation.eniture-dev3.com/login";
        }
        return "https://validate-addresses.com/login";

    }

    public static function applyPromoCodeAVEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return "https://address-validation.eniture-dev3.com/apply_promo_code?";

        }
        return "https://validate-addresses.com/apply_promo_code?";
    }

    public static function updateProviderAvEndpoint()
    {

        if (env('APP_ENV') == 'staging') {
            return "https://address-validation.eniture-dev3.com/change_promo_code_status?";
        }
        return "https://validate-addresses.com/change_promo_code_status?";

    }

    public static function orderWebhookEndpoint()
    {

        if (env('APP_ENV') == 'staging') {
            return "https://bc.eniture-qa.com/api/order/webhooks";
        }
        return "https://bc.eniture.com/api/order/webhooks";
    }

    public static function getUnpackedBoxUrl()
    {
        return "http://eu.api.3dbinpacking.com/images/29283f1d530b350d166b6ddc31fa2bfa/20171206/86407cf14c6c451d191d2e0b555eb9f5/1512573750-1033-8129432.png";
    }
}
