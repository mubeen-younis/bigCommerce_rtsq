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
    public function saveShippingGroup(Request $request): \Illuminate\Http\JsonResponse
    {
        $shippingGroup = ShippingGroup::saveShippingGroup($request->all());
        return Helper::sendJsonResponse(false, "Shipping Group saved successfully.", $shippingGroup);
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
