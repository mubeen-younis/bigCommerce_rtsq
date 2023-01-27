<?php

namespace App\Endpoints;

use App\Constants\Constant;

class Endpoints
{


    public static function getBCComEndpoint()
    {
        return "https://api.bigcommerce.com/stores/";
    }


    public static function testConnectionEndpoint()
    {
        return Constant::BASEURL . "/index.php";
    }

    public static function wweSmallTestEndpoint()
    {
        return Constant::BASEURL . "/carriers/wwe-small/speedshipTest.php";
    }

    public static function wweLtlTestEndpoint()
    {
        return Constant::BASEURL . "/carriers/wwe-freight/speedfreightTest.php";
    }

    public static function upsSmallTestEndpoint()
    {
        return Constant::BASEURL . "/s/ups/auth.php";
    }

    public static function getFDOCouponEndpoint()
    {

        return Constant::FDO_BASE_URL . "/use_coupon?shop=";
    }

    public static function applyPromoCodeFdoEndpoint()
    {

        return Constant::FDO_BASE_URL . "/apply_promo_code?";
    }

    public static function updateProviderFDOEndpoint()
    {


        return Constant::FDO_BASE_URL . "/change_promo_code_status?";

    }

    public static function getFDORegisterUrl()
    {

        return Constant::FDO_BASE_URL . "/register";

    }


    public static function verifyFdoCompDetEndpoint()
    {

        return Constant::FDO_BASE_URL . "/validate-bc-company";

    }


    public static function fdoCredsEndpoint()
    {

        return Constant::FDO_BASE_URL . "/fdo-bc-connection";

    }

    public static function getFDOLoginUrl()
    {

        return Constant::FDO_BASE_URL . "/login";

    }

    public static function verifyAvCompDetEndpoint()
    {

        return Constant::AV_BASE_URL . "/validateCompany";

    }

    public static function avCredsEndpoint()
    {

        return Constant::AV_BASE_URL . "/connectionFromBC";

    }


    public static function disconnectVACompDetEndpoint()
    {

        return Constant::AV_BASE_URL . "/disconnectConnectionBC";

    }


    public static function getAvCouponEndpoint()
    {

        return Constant::AV_BASE_URL . "/use_coupon?shop=";
    }


    public static function getAvRegisterUrl()
    {

        return Constant::AV_BASE_URL . "/register";

    }

    public static function getAvLoginUrl()
    {

        return Constant::AV_BASE_URL . "/login";

    }

    public static function applyPromoCodeAVEndpoint()
    {
        return Constant::AV_BASE_URL . "/apply_promo_code?";
    }

    public static function updateProviderAvEndpoint()
    {

        return Constant::AV_BASE_URL . "/change_promo_code_status?";

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

    public static function fedexSmallTestEndpoint()
    {
        return Constant::BASEURL . "/s/fedex/fedex_shipment_rates_test.php";
    }
}
