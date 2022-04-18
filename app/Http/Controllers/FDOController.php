<?php

namespace App\Http\Controllers;

use App\CurlRequest;
use App\CustomClasses\Functions;
use App\Endpoints\Endpoints;
use App\Helpers\Helpers;
use App\Models\Coupon;
use App\Models\InstalledCarrier;
use App\Models\Store;
use App\Models\Subscription\Subscription;
use Illuminate\Http\Request;
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
        $abc = Coupon::where('code', $coupon)->delete();
        if ($abc) {
            return 'Deleted';
        }
        return 'Nae delete hua';
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
                $couponDet = Coupon::getFDOCoupon($storeId)->toArray();
                $couponDet['coupon_code'] = $couponDet['code'] ?? null;
                $couponDet['message'] = $this->getMessageForCoupon($couponDet['used'], $couponDet['coupon_code'], $storeId, $response['fdo_company_id'], false);
                return response()->json(['error' => false,
                    'data' => $couponDet,
                    'message' => 'Successfully applied promo code',
                ], 200);
            }
        }


        if ($type == "av") {
            $endPoint = Endpoints::applyPromoCodeAVEndpoint() . $queryParams;
            $curlResponse = (new CurlRequest())->enSingleCurlRequest($endPoint, [], [], 'GET');
            $response = json_decode($curlResponse['response'], true);
            if (!empty($response) && $response['status'] == false) {
                return Helpers::sendJsonResponse(true, $response['message'] ?? 'No promo code found');
            }
            if (isset($response['promo'])) {
                Store::where('id', $storeId)->update(['av_company_id' => $response['av_company_id']]);
                Coupon::updateCouponDetails($id, $response['promo']['start_date'], $response['promo']['end_date']);
                $couponDet = Coupon::getAvCoupon($storeId)->toArray();
                $couponDet['coupon_code'] = $couponDet['code'] ?? null;
                $couponDet['message'] = $this->getMessageForCoupon($couponDet['used'], $couponDet['coupon_code'], $storeId, $response['av_company_id'], false);
                return response()->json(['error' => false,
                    'data' => $couponDet,
                    'message' => 'Successfully applied promo code',
                ], 200);
            }
        }
        return Helpers::sendJsonResponse(true, 'Something went wrong');
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateCouponDetailsFromFDO(Request $request): \Illuminate\Http\JsonResponse
    {
        $request = $request->all();
        Log::info('Request to Update Coupon Detail ',json_encode($request));
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
        $coupon = Coupon::getCouponFromStoreUrlAndCoupCode($couponCode, $storeUrl, $platform);
        if (blank($coupon)) {
            return Helpers::sendJsonResponseFdo(true, 'Coupon not found', []);
        }
        Coupon::updateCouponDetails($coupon['id'], $startDate, $endDate);
        if (!blank($platformCompanyId)) {
            if ($platform == 'av') {
                Store::where('id', $coupon['store_id'])->update(['av_company_id' => $platformCompanyId]);
            } else {
                Store::where('id', $coupon['store_id'])->update(['freightdesk_company_id' => $platformCompanyId]);
            }
        }
        $installedProviders = $this->getProvsSepByPipe($coupon['store_id'], true);
        return response()->json(['error' => false, 'message' => 'Updated coupon details', 'install_carriers' => $installedProviders]);

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

        // FOR AV
        if (!blank($promoCodeAv)) {
            $arr['promocode'] = $promoCodeAv;
            $data = http_build_query($arr);
            $endPoint = Endpoints::updateProviderAvEndpoint() . $data;
            $curlResp = (new CurlRequest())->enSingleCurlRequest($endPoint, [], [], 'GET');
            Log::info('Av resp for changing carrier status ' . json_encode($curlResp));

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
        $store = Store::where('id', $request['store_id'])->first();
        $messgae = 'FreightDesk Online ';

        if ($store) {
            if ($request['freightdesk_company_id'] && isset($request['freightdesk_company_id'])) {
                $store->freightdesk_company_id = $request['freightdesk_company_id'];
                $messgae .= 'connected successfully';
            } else {
                $store->freightdesk_company_id = null;
                $messgae .= 'disconnected successfully';
            }
            $store->save();

            return response()->json(['error' => false,
                'data' => [],
                'message' => $messgae,
            ], 200);
        } else {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Store not found',
            ], 404);
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
