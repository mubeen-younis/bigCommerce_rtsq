<?php

namespace App\Http\Controllers;

use App\CurlRequest;
use App\CustomClasses\Functions;
use App\Endpoints\Endpoints;
use App\Helpers\Helpers;
use App\Models\Coupon;
use App\Models\InstalledCarrier;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        if ($used >= 1 && !blank($avCompanyId)) {
            return $congrats . "You have activated your Promo Code  " . $couponCodeHtml . " with Address Validation account " . $avCompanyIdHtml . ". Now you can enjoy free address validations for 1-year.";
        }
        if (!blank($avCompanyId) && $used == 0 && $IsAlrUser) {
            return $note . "Get Address Validation free for one year by using promo code [" . $couponCode . "]. Click the button below to apply the promo code";

        }
        if (!blank($avCompanyId) && $used == 0 && !$IsAlrUser) {
            $code = $this->makeBase64code($storeId, $couponCode);
            $loginUrl = $loginUrl . '?code=' . $code;
            $msg = $note . "Get Address Validation free for one year by using promo code [" . $couponCode . "]. ";
            $clickHereLogin = "<a target='_blank' rel='noreferrer' href='" . $loginUrl . "'>here</a>";
            $msg = $msg . "Click " . $clickHereLogin . ' to log in.<br><strong>Please refresh the page after logging in. </strong>';
            return $msg;
        }

        if ($IsAlrUser) {
            return $note . "Get Address Validation free for one year by using promo code " . $couponCodeHtml . ". Click the button below to apply the promo code";
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


    public function updateVA(Request $request)
    {
        $avCompanyId = $request['av_company_id'] ?? '';
        $storeId = $request['store_id'] ?? '';
        $message = 'Address Validation ';
        $store = Store::where('id', $storeId)->first();

        if (!blank($avCompanyId)) {
            $fdoConnectivityResp = $this->connectVA($store, $avCompanyId);
            if ($fdoConnectivityResp['error']) {
                return Helpers::sendJsonResponse(true, $fdoConnectivityResp['message']);
            }
            $store->av_company_id = $avCompanyId;
            $message .= 'connected successfully';
        } else {
            $this->disConnectVA($store);
            $store->av_company_id = null;
            $message .= 'disconnected successfully';
        }
        $store->save();
        return Helpers::sendJsonResponse(false, $message);


    }

    public function connectVA($storeDetails, $avCompanyId)
    {
        $storeUrl = $storeDetails->url ?? '';
        $storeHash = $storeDetails->hash ?? '';
        $accessToken = $storeDetails->access_token ?? '';
        $request = ['store_name' => $storeUrl, 'company_id' => $avCompanyId, 'action' => 'install'];
        $endpoint = Endpoints::verifyAvCompDetEndpoint();
        $curlResp = (new CurlRequest())->enSingleCurlRequest($endpoint, $request, [], 'POST');
        $curlResp = json_decode($curlResp['response'], true);
        Log::info('Curl Response from VA ' . json_encode($curlResp) . 'Request ' . json_encode($request));
        if (isset($curlResp['is_valid']) && $curlResp['is_valid'] == false) {
            return ['error' => true, 'message' => 'Not a valid company Id'];
        }
        Log::info('Before second call ' . json_encode($curlResp));
        if (isset($curlResp['is_valid']) && $curlResp['is_valid'] == true) {
            Log::info('Comming on second call');
            $request = ['store_url' => $storeUrl, 'action' => 'install', 'store_hash' => $storeHash, 'access_token' => $accessToken, 'company_id' => $avCompanyId];
            $endpoint = Endpoints::avCredsEndpoint();
            $curlResp = (new CurlRequest())->enSingleCurlRequest($endpoint, $request, [], 'POST');
            $curlResp = json_decode($curlResp['response'], true);
            Log::info('Response from AV after Connect ' . json_encode($curlResp));
            if (isset($curlResp['error']) && $curlResp['error'] == false) {
                return ['error' => false, 'message' => 'Successfully connected to Validate Addresses'];
            }

        }
        return ['error' => true, 'message' => 'Something went wrong on establishing connection with Validate Addresses'];


    }

    public function disConnectVA($storeDetails)
    {
        $storeUrl = $storeDetails->url ?? '';
        $storeHash = $storeDetails->hash ?? '';
        $accessToken = $storeDetails->access_token ?? '';
        $companyId = $storeDetails->av_company_id ?? '';
        if (blank($companyId)) {
            return null;
        }
        $request = ['store_url' => $storeUrl, 'action' => 'uninstall', 'store_hash' => $storeHash, 'access_token' => $accessToken, 'company_id' => $companyId];
        $endpoint = Endpoints::disconnectVACompDetEndpoint();
        $curlResp = (new CurlRequest())->enSingleCurlRequest($endpoint, $request, [], 'POST');
        Log::info('Response from AV after Disconnect ' . json_encode($curlResp));

    }

    public function connectionUpdateFromVa(Request $request)
    {
        $storeUrl = $request->store_url ?? '';
        $companyId = $request->company_id ?? '';
        $status = $request->status ?? false;
        if (blank($storeUrl) || blank($companyId)) {
            Helpers::sendJsonResponse(true, 'Store Url and Company Id is required');
        }
        if ($status) {
            Store::where(['url' => $storeUrl, 'av_company_id' => $companyId])->update(['av_company_id' => $companyId]);
            Helpers::sendJsonResponse(false, 'Connection Activated');

        } else {
            Store::where(['url' => $storeUrl, 'av_company_id' => $companyId])->update(['av_company_id' => null]);
            Helpers::sendJsonResponse(false, 'Disconnected from BigCommerce');

        }
    }
}
