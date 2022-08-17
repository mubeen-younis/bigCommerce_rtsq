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
            return "https://address-validation.eniture-qa.com/validateCompany";
        }
        return "https://validate-addresses.com/validateCompany";

    }

    public static function avCredsEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return "https://address-validation.eniture-qa.com/connectionFromBC";
        }
        return "https://validate-addresses.com/connectionFromBC";

    }


    public static function disconnectVACompDetEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return "https://address-validation.eniture-qa.com/disconnectConnectionBC";
        }
        return "https://validate-addresses.com/disconnectConnectionBC";

    }


    public static function getAvCouponEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return "https://address-validation.eniture-qa.com/use_coupon?shop=";
        }
        return "https://validate-addresses.com/use_coupon?shop=";
    }


    public static function getAvRegisterUrl()
    {

        if (env('APP_ENV') == 'staging') {
            return "https://address-validation.eniture-qa.com/register";
        }
        return "https://validate-addresses.com/register";

    }

    public static function getAvLoginUrl()
    {

        if (env('APP_ENV') == 'staging') {
            return "https://address-validation.eniture-qa.com/login";
        }
        return "https://validate-addresses.com/login";

    }

    public static function applyPromoCodeAVEndpoint()
    {
        if (env('APP_ENV') == 'staging') {
            return "https://address-validation.eniture-qa.com/apply_promo_code?";
        }
        return "https://validate-addresses.com/apply_promo_code?";
    }

    public static function updateProviderAvEndpoint()
    {

        if (env('APP_ENV') == 'staging') {
            return "https://address-validation.eniture-qa.com/change_promo_code_status?";
        }
        return "https://validate-addresses.com/change_promo_code_status?";

    }
}
