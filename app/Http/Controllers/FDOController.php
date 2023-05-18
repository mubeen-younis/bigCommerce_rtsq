<?php

namespace App\Http\Controllers;

use App\CurlRequest;
use App\CustomClasses\Functions;
use App\Endpoints\Endpoints;
use App\Helpers\Helpers;
use App\Models\Coupon;
use App\Models\CouponCarrier;
use App\Models\InstalledCarrier;
use App\Models\Store;
use App\Models\Subscription\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FDOController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    public function deleteCoupon(Request $request)
    {
        $coupon = $request->coupon ?? null;
        if (blank($coupon)) {
            return 'Not valid';
        }
        $abc = Coupon::where('code', $coupon)->first();
        if ($abc === null) {
            return 'Not FOund';
        }
        DB::table('coupon_code_carriers')->where('coupon_code_id', $abc->id)->delete();
        Coupon::where('code', $coupon)->delete();
        return 'Deleted';
    }

    public function getFdoCompanyInfo(Request $request)
    {
        $storeId = $request['store_id'];
        $store = optional(Store::where('id', $storeId)->first())->toArray() ?? [];
        $coupon = Coupon::getFDOCoupon($storeId);
        if ($coupon === null) {
            $coupon = $this->getCouponCodeFdo($storeId);
        }
        $store['coupon_code'] = $coupon->code ?? null;
        $store['is_already_user'] = $coupon->is_already_user ?? false;
        $store['used'] = $coupon->used ?? null;
        $store['message'] = $this->getMessageForCoupon($store['used'], $store['coupon_code'], $storeId, $store['freightdesk_company_id'], $store['is_already_user']);
        return response()->json(['error' => false,
            'data' => $store,
            'message' => '',
        ], 200);
    }

    public function getFdoConnectivityInfo(Request $request)
    {
        $storeId = $request['store_id'];
        $store = optional(Store::where('id', $storeId)->first())->toArray() ?? [];
        if (blank($store['freightdesk_company_id'])) {
            return Helpers::sendJsonResponse(true, '',);
        }
        return Helpers::sendJsonResponse(false, '', ['freightdesk_company_id' => $store['freightdesk_company_id']]);
    }


    public function getMessageForCoupon($used, $couponCode, $storeId, $fdoCompanyId, $IsAlrUser = false)
    {
        $registerUrl = Endpoints::getFDORegisterUrl();
        $loginUrl = Endpoints::getFDOLoginUrl();
        $note = "<strong>Note! </strong>";
        $couponCodeHtml = "<strong>[" . $couponCode . "]</strong>";
        $fdoCompanyIdHtml = "<strong>[" . $fdoCompanyId . "]</strong>";
        $congrats = "<strong>Congratulations! </strong>";
        if ($used >= 1) {
            return $congrats . "You have activated your Promo Code  " . $couponCodeHtml . " with FreightDesk Online account " . $fdoCompanyIdHtml . ". Now you can enjoy free shipments with FreightDesk Online for 1-year.";
        }
        if ($IsAlrUser) {
            return "Note! To establish a connection, you must have a FreightDesk Online account. If you don’t have one, get FreightDesk Online free for one year by using promo code " . $couponCodeHtml . ". Click the button below to apply the promo code";
        }
        if ($used === null) {
            $clickHere = "<a target='_blank' rel='noreferrer' href='" . $registerUrl . "'>here</a>";
            return $note . "To establish a connection, you must have a FreightDesk Online account. If you don’t have one, click " . $clickHere . " to register";
        }
        if ($used == 0) {
            $code = $this->makeBase64code($storeId, $couponCode);
            $registerUrl = $registerUrl . '?code=' . $code;
            $loginUrl = $loginUrl . '?code=' . $code;
            $clickHere = "<a target='_blank' rel='noreferrer' href='" . $registerUrl . "'>here</a>";
            $msg = $note . "To establish a connection, you must have a FreightDesk Online account. If you don’t have one, get FreightDesk Online free for one year by using promo code " . $couponCodeHtml . ". Register for FreightDesk Online using the promo code now. Click " . $clickHere . '.<br/>';
            $clickHereLogin = "<a target='_blank' rel='noreferrer' href='" . $loginUrl . "'>here</a>";
            $msg = $msg . "Already have an account. Click " . $clickHereLogin . '.<br><strong>Please refresh the page after registering or logging in. </strong>';
            return $msg;
        }

    }

    public function getFDOCouponInfo(Request $request)
    {
        $storeId = $request['store_id'];
        $store = optional(Store::where('id', $storeId)->first())->toArray() ?? [];
        $coupon = Coupon::getFDOCoupon($storeId);
        if ($coupon === null) {
            $coupon = $this->getCouponCodeFdo($storeId);
        }
        $coupon['freightdesk_company_id'] = $store['freightdesk_company_id'] ?? '';

        return response()->json(['error' => false,
            'data' => $coupon,
            'message' => '',
        ], 200);
    }

    public function getFDOCouponCarrierInfo(Request $request)
    {
        $isCarrPromoExpire = false;
        $carrierInfo = CouponCarrier::getCarrierInfoByName($request);
        $promoCarriersInfo = CouponCarrier::getPromoCarriersInfo($request);
        $registerUrl = Endpoints::getFDORegisterUrl();
        if (isset($request['coupon_code']) && !blank($request['coupon_code'])) {
            $registerUrl = $registerUrl . '?code=' . $this->encodeBase64Code($request);
        }
        if(isset($promoCarriersInfo) && !empty($promoCarriersInfo)){
            foreach($promoCarriersInfo as $carr){
                if(isset($carr['is_enabled']) && $carr['is_enabled'] === 2){
                    $isCarrPromoExpire = true;
                }
            }
        }
        if($carrierInfo) {
            $data = $carrierInfo;
        }
        $data['registerUrl'] = $registerUrl;
        $data['isCarrPromoExpire'] = $isCarrPromoExpire;

        return response()->json(['error' => false,
            'data' => $data,
            'message' => '',
        ], 200);
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

    public function encodeBase64Code($request): string
    {
        $storeId = $request['store_id'];
        $couponCode = $request['coupon_code'] ?? '';
        $storeDetails = Store::getStoreDetailsFromStoreId($storeId);
        $storeUrl = $storeDetails['url'] ?? '';
        $email = $storeDetails['owner_email'] ?? '';
        $apps = Functions::fdoSLugForCarriers($request['carrier_name']) ?? '';
        $encodedCode = base64_encode(http_build_query(['shop' => $storeUrl, 'promocode' => $couponCode, 'email' => $email, 'phone' => '', 'apps' => $apps, 'marketplace' => 'bc', 'one_carrier' => 'true']));

        return $encodedCode;
    }

    public function getProvsSepByPipe($storeId, $sendArray = false)
    {
        $installedProvSlugs = InstalledCarrier::getinstalledProvidersSlug($storeId);
        $slugArr = [];
        foreach ($installedProvSlugs as $installedProvSlug) {
            $slug = Functions::fdoSLugForCarriers($installedProvSlug['slug']);
            if (!blank($slug)) {
                $slugArr[] = $slug;
            }
        }
        if ($sendArray) {
            return $slugArr;
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
    public function getCouponCodeFdo($storeId)
    {
        return Coupon::getCouponFdo($storeId);
    }


    public function applyPromoCode(Request $request)
    {
        $type = $request->type ?? "fdo";
        $storeId = $request->store_id;
        $promoDetail = $type == "av" ? Coupon::getAvCoupon($storeId) : Coupon::getFDOCoupon($storeId);
        if (blank($promoDetail)) {
            return Helpers::sendJsonResponse(true, 'No promo code found');
        }
        $id = $promoDetail->id;
        $coupon = $promoDetail->code;
        $shop = $promoDetail->shop;
        $carriers = $this->getProvsSepByPipe($storeId);
        $queryParams = http_build_query(['coupon' => $coupon, 'shop' => $shop, 'carriers' => $carriers]);
        if ($type == "fdo") {
            $endPoint = Endpoints::applyPromoCodeFdoEndpoint() . $queryParams;
            $curlResponse = (new CurlRequest())->enSingleCurlRequest($endPoint, [], [], 'GET');
            $response = json_decode($curlResponse['response'], true);
            if (!empty($response) && $response['status'] == false) {
                return Helpers::sendJsonResponse(true, $response['message'] ?? 'No promo code found');
            }
            if (isset($response['promo'])) {
                Store::where('id', $storeId)->update(['freightdesk_company_id' => $response['fdo_company_id']]);
                Coupon::updateCouponDetails($id, $response['promo']['start_date'], $response['promo']['end_date']);
                if (isset($response['carriers']) && !empty($response['carriers'])) {
                    $enabledCarriers = $this->savePromoAppliedCarriers($id, $storeId, $response);
                }
                $storeDetails = Store::where('id', $storeId)->first();
                $storeHash = $storeDetails->hash;
                $accessToken = $storeDetails->access_token;
                return response()->json(['error' => false, 'message' => 'Updated coupon details', 'store_hash' => $storeHash, 'access_token' => $accessToken, 'install_carriers' => $enabledCarriers]);
            }
        }


        if ($type == "av") {
            $endPoint = Endpoints::applyPromoCodeAVEndpoint() . $queryParams;
            $curlResponse = (new CurlRequest())->enSingleCurlRequest($endPoint, [], [], 'GET');
            $response = json_decode($curlResponse['response'], true);
            if (!empty($response) && $response['status'] == false) {
                return Helpers::sendJsonResponse(true, $response['message'] ?? 'No promo code found');
            }
            if (isset($response['promo']) && !empty($response['promo'])) {
                Store::where('id', $storeId)->update(['av_company_id' => $response['av_company_id']]);
                Coupon::updateCouponDetails($id, $response['promo']['start_date'], $response['promo']['end_date']);
                $couponDet = Coupon::getAvCoupon($storeId)->toArray();
                $storeDetails = Store::where('id', $storeId)->first();
                $couponDet['av_company_id'] = $storeDetails->av_company_id ?? null;
                $couponDet['coupon_code'] = $couponDet['code'] ?? null;
                $couponDet['message'] = (new AddressValidationController())->getMessageForCoupon($couponDet['used'], $couponDet['coupon_code'], $storeId, $response['av_company_id'], false);
                return response()->json(['error' => false,
                    'data' => $couponDet,
                    'message' => 'Successfully applied promo code',
                ], 200);
            }
        }
        return Helpers::sendJsonResponse(true, 'Something went wrong');
    }


    public function savePromoAppliedCarriers($id, $storeId, $response)
    {
        $enabledCarriers = [];
        foreach ($response['carriers'] as $carrierValue) {
            $slug = Functions::carrierSlugForFdo($carrierValue);
            if (!blank($slug)) {
                $installedCarrier = InstalledCarrier::getInstCarFromSlugANdStore($slug, $storeId, $response['promo']['coupon']);
                if ($installedCarrier) {
                    $enabledCarriers[] = $carrierValue;
                    CouponCarrier::addOrUpdateCarrierInfo($slug, $id, $carrierValue, $response);
                }
            }
        }
        if (!blank($enabledCarriers)) {
            return implode('|', $enabledCarriers);
        }
        return null;
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateCouponDetailsFromFDOAV(Request $request): \Illuminate\Http\JsonResponse
    {
        $request = $request->all();
        Log::info('Request to Update Coupon Detail ' . json_encode($request));
        if (isset($request['av_company_id'])) {
            $platformCompanyId = $request['av_company_id'] ?? '';
            $platform = 'av';
        } else {
            $platformCompanyId = $request['fdo_company_id'] ?? '';
            $platform = 'fdo';
        }
        $couponCode = $request['promo']['coupon'] ?? '';
        $storeUrl = $request['promo']['store_url'] ?? '';
        $startDate = $request['promo']['start_date'] ?? '';
        $endDate = $request['promo']['end_date'] ?? '';

        if (blank($couponCode) || blank($storeUrl)) {
            return Helpers::sendJsonResponseFdo(true, 'Invalid request format', []);
        }
        Log::info('Coupon :' . $couponCode . 'Store Url: ' . $storeUrl . ' Platfoem: ' . $platform . "Request " . json_encode($request));
        $coupon = Coupon::getCouponFromStoreUrlAndCoupCode($couponCode, $storeUrl, $platform);
        if (blank($coupon)) {
            return Helpers::sendJsonResponseFdo(true, 'Coupon not found', []);
        }
        $couponId = $coupon['id'];
        $storeId = $coupon['store_id'];
        Coupon::updateCouponDetails($coupon['id'], $startDate, $endDate);
        if ($platform == "fdo") {
            if (isset($request['carriers']) && !empty($request['carriers'])) {
                $enabledCarriers = $this->savePromoAppliedCarriers($couponId, $storeId, $request);
            }
        }
        $storeDetails = Store::where('id', $storeId)->first();
        $storeHash = $storeDetails->hash;
        $accessToken = $storeDetails->access_token;
        $storeUrl = $storeDetails->url;
        if (!blank($platformCompanyId)) {
            if ($platform == 'av') {
                Store::where('id', $storeId)->update(['av_company_id' => $platformCompanyId]);
            } else {
                Store::where('id', $storeId)->update(['freightdesk_company_id' => $platformCompanyId]);
            }
        }
        $installedProviders = $this->getProvsSepByPipe($storeId, true);

        $toSendCarriers = $platform == "fdo" ? (isset($enabledCarriers) && !empty($enabledCarriers) ? $enabledCarriers : null) : $installedProviders;
        $responseToSend = ['error' => false, 'message' => 'Updated coupon details', 'store_url' => $storeUrl, 'store_hash' => $storeHash, 'access_token' => $accessToken, 'install_carriers' => $toSendCarriers];
        Log::info('Response for promo code ' . json_encode($responseToSend));
        return response()->json($responseToSend);

    }


    /**
     * @param $enabled
     * @param $installedCarrierId
     * @param $storeId
     * @return void|null
     */
    public static function updateProviderCoupon($enabled, $installedCarrierId, $storeId)
    {

        $carrierSlug = InstalledCarrier::getInstalledProviderSlug($installedCarrierId);
        if (blank($carrierSlug)) {
            return null;
        }
        $fdoSlug = Functions::fdoSLugForCarriers($carrierSlug['slug']);
        $promoCodeFDO = Coupon::getCouponCodeFromStoreIdandType($storeId);
        $promoCodeAv = Coupon::getCouponCodeFromStoreIdandType($storeId, 'av');
        if (blank($fdoSlug)) {
            return null;
        }

        $arr['action'] = $enabled ? "install" : "uninstall";
        $arr['carrier'] = $fdoSlug;

        // FOR FDO
        if (!blank($promoCodeFDO)) {
            $arr['promocode'] = $promoCodeFDO;
            $data = http_build_query($arr);
            $endPoint = Endpoints::updateProviderFDOEndpoint() . $data;
            $curlResp = (new CurlRequest())->enSingleCurlRequest($endPoint, [], [], 'GET');
            Log::info('FDO resp for changing carrier status ' . json_encode($curlResp));

        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        $fdoCompanyId = $request['freightdesk_company_id'] ?? '';
        $storeId = $request['store_id'] ?? '';
        $message = 'FreightDesk Online ';
        $store = Store::where('id', $storeId)->first();

        if (!blank($fdoCompanyId)) {
            $fdoConnectivityResp = $this->connectFDO($store, $fdoCompanyId);
            if ($fdoConnectivityResp['error']) {
                return Helpers::sendJsonResponse(true, $fdoConnectivityResp['message']);
            }
            $store->freightdesk_company_id = $fdoCompanyId;
            $message .= 'connected successfully';
        } else {
            $this->disConnectFDO($store);
            $store->freightdesk_company_id = null;
            $message .= 'disconnected successfully';
        }
        $store->save();
        return Helpers::sendJsonResponse(false, $message);


    }

    public function connectFDO($storeDetails, $fdoCompanyId)
    {
        $storeUrl = $storeDetails->url ?? '';
        $storeHash = $storeDetails->hash ?? '';
        $accessToken = $storeDetails->access_token ?? '';
        $request = ['store_url' => $storeUrl, 'company_id' => $fdoCompanyId, 'action' => 'install'];
        $endpoint = Endpoints::verifyFdoCompDetEndpoint();
        $curlResp = (new CurlRequest())->enSingleCurlRequest($endpoint, $request, [], 'POST');
        $curlResp = json_decode($curlResp['response'], true);
        if (isset($curlResp['error']) && $curlResp['error']) {
            return ['error' => true, 'message' => $curlResp['message']];
        }
        Log::info('Curl Response for company validation ' . json_encode($curlResp));
        if (isset($curlResp['error']) && $curlResp['error'] == false) {
            Log::info('Before second call fdo ');
            $request = ['store_url' => $storeUrl, 'action' => 'install', 'store_hash' => $storeHash, 'access_token' => $accessToken, 'company_id' => $fdoCompanyId];
            $endpoint = Endpoints::fdoCredsEndpoint();
            $curlResp = (new CurlRequest())->enSingleCurlRequest($endpoint, $request, [], 'POST');
            $curlResp = json_decode($curlResp['response'], true);
            Log::info('After second call fdo ' . json_encode($curlResp));
            if (isset($curlResp['error']) && $curlResp['error'] == false) {
                return ['error' => false, 'message' => 'Successfully connected to FreightDesk Online'];
            }
        }
        return ['error' => true, 'message' => 'Something went wrong on establishing connection with FreightDesk Online'];


    }

    public function disConnectFDO($storeDetails)
    {
        $storeUrl = $storeDetails->url ?? '';
        $storeHash = $storeDetails->hash ?? '';
        $accessToken = $storeDetails->access_token ?? '';
        $companyId = $storeDetails->freightdesk_company_id ?? '';
        if (blank($companyId)) {
            return null;
        }
        $request = ['store_url' => $storeUrl, 'action' => 'uninstall', 'store_hash' => $storeHash, 'access_token' => $accessToken, 'company_id' => $companyId];
        $endpoint = Endpoints::fdoCredsEndpoint();
        $curlResp = (new CurlRequest())->enSingleCurlRequest($endpoint, $request, [], 'POST');
        Log::info('Response from FDO after Disconnect ' . json_encode($curlResp));

    }


    public function connectionUpdateFromFdo(Request $request)
    {
        $storeUrl = $request->store_url ?? '';
        $companyId = $request->company_id ?? '';
        $status = $request->status ?? false;
        if (blank($storeUrl) || blank($companyId)) {
            return Helpers::sendJsonResponse(true, 'Store Url and Company Id is required');
        }
        if ($status) {
            Store::where('url' , $storeUrl)->update(['freightdesk_company_id' => $companyId]);
            return Helpers::sendJsonResponse(false, 'Connection Activated');
        } else {
            Store::where('url' , $storeUrl)->update(['freightdesk_company_id' => null]);
            return Helpers::sendJsonResponse(false, 'Disconnected from BigCommerce');
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
