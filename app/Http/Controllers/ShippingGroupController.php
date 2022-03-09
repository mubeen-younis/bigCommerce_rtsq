<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Helpers\Helpers;
use App\Models\ShippingGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShippingGroupController extends Controller
{
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getShippingGroups(Request $request)
    {
        $shippingGroups = ShippingGroup::getStoreShippingGroups($request['store_id']);
        Log::info('Shipping Group Store Id '.$request['store_id']. ' Shipping Groups '.json_encode($shippingGroups));
        return Helpers::sendJsonResponse(false, "", $shippingGroups);
    }


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveShippingGroup(Request $request): \Illuminate\Http\JsonResponse
    {
        $res = ShippingGroup::saveOrUpdateShippingGroup($request->all());
        return Helpers::sendJsonResponse($res['error'], "Shipping Group " . $res['message'], $res['data']);
    }


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteShippingGroup(Request $request): \Illuminate\Http\JsonResponse
    {
        ShippingGroup::deleteShippingGroup($request->uuid);
        return Helpers::sendJsonResponse(false, "Shipping Group deleted successfully.", $request->uuid);
    }


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getShippingGroupDetail(Request $request)
    {
        $shippingGroupDetail = ShippingGroup::getShippingGroupDetailByUuid($request->uuid);
        return Helpers::sendJsonResponse(false, null, $shippingGroupDetail);
    }
}
