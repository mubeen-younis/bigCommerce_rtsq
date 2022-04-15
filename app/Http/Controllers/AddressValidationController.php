<?php

namespace App\Http\Controllers;

use App\CustomClasses\Functions;
use App\Endpoints\Endpoints;
use App\Models\Coupon;
use App\Models\InstalledCarrier;
use App\Models\Store;
use Illuminate\Http\Request;

class AddressValidationController extends Controller
{
    public function getAvCompanyInfo(Request $request)
    {
        $storeId = $request['store_id'];
        $store = optional(Store::where('id', $storeId)->first())->toArray() ?? [];
        $coupon = Coupon::getAvCoupon($storeId);
        if ($coupon === null) {
            $coupon = $this->getCouponCodeAv($storeId);
        }
        $store['coupon_code'] = $coupon->code ?? null;
        $store['is_already_user'] = $coupon->is_already_user ?? false;
        $store['used'] = $coupon->used ?? null;
        $store['message'] = $this->getMessageForCoupon($store['used'], $store['coupon_code'], $storeId, $store['av_company_id'], $store['is_already_user']);
        return response()->json(['error' => false,
            'data' => $store,
            'message' => '',
        ], 200);
    }


    public function getMessageForCoupon($used, $couponCode, $storeId, $avCompanyId, $IsAlrUser = false)
    {
        $registerUrl = Endpoints::getAvRegisterUrl();
        $loginUrl = Endpoints::getAvLoginUrl();
        $note = "<strong>Note! </strong>";
        $congrats = "<strong>Congratulations! </strong>";
        $couponCodeHtml = "<strong>[" . $couponCode . "]</strong>";
        $avCompanyIdHtml = "<strong>[" . $avCompanyId . "]</strong>";
        if ($used >= 1) {
            return $congrats . "You have activated your Promo Code  " . $couponCodeHtml . " with Address Validation account " . $avCompanyIdHtml . ". Now you can enjoy free shipments with Address Validation for 1-year.";
        }
        if ($IsAlrUser) {
            return "Note! To establish a connection, you must have a Address Validation account. If you don’t have one, get Address Validation free for one year by using promo code " . $couponCodeHtml . ". Click the button below to apply the promo code";
        }
        if ($used === null) {
            $clickHere = "<a target='_blank' rel='noreferrer' href='" . $registerUrl . "'>here</a>";
            return $note . "To establish a connection, you must have a Address Validation account. If you don’t have one, click " . $clickHere . " to register";
        }
        if ($used == 0) {
            $code = $this->makeBase64code($storeId, $couponCode);
            $registerUrl = $registerUrl . '?code=' . $code;
            $loginUrl = $loginUrl . '?code=' . $code;
            $clickHere = "<a target='_blank' rel='noreferrer' href='" . $registerUrl . "'>here</a>";
            $msg = $note . "To establish a connection, you must have a Address Validation account. If you don’t have one, get Address Validation free for one year by using promo code [" . $couponCode . "]. Register for Address Validation using the promo code now. Click " . $clickHere . '.<br/>';
            $clickHereLogin = "<a target='_blank' rel='noreferrer' href='" . $loginUrl . "'>here</a>";
            $msg = $msg . "Already have an account. Click " . $clickHereLogin . '.<br><strong>Please refresh the page after registering or logging in. </strong>';
            return $msg;
        }
    }


    /**
     * @param $storeId
     * @param $couponCode
     * @return string
     */
    public function makeBase64code($storeId, $couponCode): string
    {
        $storeDetails = Store::getStoreDetailsFromStoreId($storeId);
        $storeUrl = $storeDetails['url'] ?? '';
        $email = $storeDetails['owner_email'] ?? '';
        $phone = '';
        $apps = $this->getProvsSepByPipe($storeId);
        $encodedCode = base64_encode(http_build_query(['shop' => $storeUrl, 'promocode' => $couponCode,
            'email' => $email, 'phone' => $phone, 'apps' => $apps, 'marketplace' => 'bc']));
        return $encodedCode;
    }


    public function getProvsSepByPipe($storeId): string
    {
        $installedProvSlugs = InstalledCarrier::getinstalledProvidersSlug($storeId);
        $slugArr = [];
        foreach ($installedProvSlugs as $installedProvSlug) {
            $slug = Functions::fdoSLugForCarriers($installedProvSlug['slug']);
            if (!blank($slug)) {
                $slugArr[] = $slug;
            }
        }
        if (!blank($slugArr)) {
            return implode('|', $slugArr);
        }
        return "";
    }

    /**
     * @param $storeId
     * @return array|mixed
     */
    public function getCouponCodeAv($storeId)
    {
        return Coupon::getCouponAv($storeId);
    }
}
