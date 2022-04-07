<?php

namespace App\Http\Controllers;

use App\Helpers\Helpers;
use App\Models\Coupon;
use App\Models\Store;
use Illuminate\Http\Request;

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

    public function getFdoCompanyInfo(Request $request)
    {
        $store = optional(Store::where('id', $request['store_id'])->first())->toArray() ?? [];
        $coupon = Coupon::where('store_id', $request['store_id'])->first();
        if ($coupon === null) {
            $coupon = $this->getCouponCodeFdo($request['store_id']);
        }
        // TODO: Need to CHeck COupon Validity as well in future
        $store['coupon_code'] = $coupon->code ?? null;
        $store['used'] = $coupon->used ?? null;
        return response()->json(['error' => false,
            'data' => $store,
            'message' => '',
        ], 200);
    }

    /**
     * @param $storeId
     * @return array|mixed
     */
    public function getCouponCodeFdo($storeId)
    {
        return Coupon::getCouponFdo($storeId);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateCouponDetailsFromFDO(Request $request): \Illuminate\Http\JsonResponse
    {
        $request = $request->all();
        $couponCode = $request['promo']['coupon'] ?? '';
        $storeUrl = $request['promo']['store_url'] ?? '';
        $startDate = $request['promo']['start_date'] ?? '';
        $endDate = $request['promo']['end_date'] ?? '';
        if (blank($couponCode) || blank($storeUrl)) {
            return Helpers::sendJsonResponseFdo(true, 'Invalid request format', []);
        }
        $coupon = Coupon::getCouponFromStoreUrlAndCoupCode($couponCode, $storeUrl);
        if (blank($coupon)) {
            return Helpers::sendJsonResponseFdo(true, 'Coupon not found', []);
        }
        Coupon::updateCouponDetails($coupon['id'], $startDate, $endDate);
        return Helpers::sendJsonResponseFdo(false, 'Updated coupon details', []);


    }

    // TODO:  Will be called when carrier is enabled or disabled
    public static function updateProviderCouponFDO()
    {
        // promocode=FD014GW&action=install&carrier=GTZ
        /*        'WWE_PL' => 'Worldwide Express (Parcel)
        ',
        'WWE_LTL' => 'Worldwide Express (LTL)',
         'GTZ' => 'GlobalTranz',
         'Unishiper' => 'Unishippers (LTL)',
        'UNI_PL' => 'Unishippers (Parcel)
        ',*/
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
