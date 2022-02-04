<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\ShippingGroup;
use Illuminate\Http\Request;

class ShippingGroupController extends Controller
{
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getShippingGroups(Request $request): \Illuminate\Http\JsonResponse
    {
        $shippingGroups = ShippingGroup::getStoreShippingGroups($request['store_id']);
        return Helper::sendJsonResponse(false, "", $shippingGroups);
    }


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveShippingGroup(Request $request): \Illuminate\Http\JsonResponse
    {
        $res = ShippingGroup::saveOrUpdateShippingGroup($request->all());
        return Helper::sendJsonResponse($res['error'], "Shipping Group " . $res['message'], $res['data']);
    }


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteShippingGroup(Request $request): \Illuminate\Http\JsonResponse
    {
        ShippingGroup::deleteShippingGroup($request->uuid);
        return Helper::sendJsonResponse(false, "Shipping Group deleted successfully.", $request->uuid);
    }


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getShippingGroupDetail(Request $request)
    {
        $shippingGroupDetail = ShippingGroup::getShippingGroupDetailByUuid($request->uuid);
        return Helper::sendJsonResponse(false, null, $shippingGroupDetail);
    }
}
