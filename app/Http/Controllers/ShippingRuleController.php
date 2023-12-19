<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Helpers\Helpers;
use App\Models\ShippingRule;
use App\Models\CountryState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShippingRuleController extends Controller
{

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getShippingRules(Request $request)
    {
        $shippingRules = ShippingRule::getStoreShippingRules($request['store_id']);
        return Helpers::sendJsonResponse(false, "", $shippingRules);
    }


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveShippingRule(Request $request): \Illuminate\Http\JsonResponse
    {
        $res = ShippingRule::saveOrUpdateShippingRule($request->all());
        return Helpers::sendJsonResponse($res['error'], "Shipping Rule is " . $res['message'], $res['data']);
    }

    public function updateAvaiableStatus(Request $request): \Illuminate\Http\JsonResponse
    {
        $res = ShippingRule::updateAvaiableShippingRuleStatus($request->all());
        return Helpers::sendJsonResponse($res['error'], "Shipping Rule is " . $res['message'], $res['data']);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteShippingRule(Request $request): \Illuminate\Http\JsonResponse
    {
        ShippingRule::deleteShippingRule($request->uuid);
        return Helpers::sendJsonResponse(false, "Shipping Rule is deleted successfully.", $request->uuid);
    }


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getShippingRuleDetail(Request $request)
    {
        $shippingRuleDetail = ShippingRule::getShippingRuleDetailByUuid($request->uuid);
        return Helpers::sendJsonResponse(false, null, $shippingRuleDetail);
    }

    public function applyShippingRule($storeId, $lineItemData, $connectionSettings)
    {    
        $is_true = false;
        $shippingRules = ShippingRule::getStoreShippingRules($storeId);
        if(!empty($shippingRules)){
            $cartItems = !empty($lineItemData) ? $lineItemData : [];
            foreach($shippingRules as $key => $rule){
                if(isset($rule['available']) && $rule['available']){
                    $provider = isset($rule['filter_provider']) ? $rule['filter_provider'] : '';
                    switch ($rule['rule_type']) {
                        case 2:
                            $is_true = $this->hideMethods($rule, $cartItems);
                            if(!$is_true){
                                foreach($connectionSettings as $key => $carrier){
                                    if($key == $provider){
                                        unset($connectionSettings[$key]);
                                    }
                                }
                            }
                        break;
                    }
                }
            }
        }
        return $connectionSettings;
    }

    public function hideMethods($shippingRule, $items)
    {
        if(isset($shippingRule['isFilterWeight']) && $shippingRule['isFilterWeight']){
            $weight = collect($items)->map(function ($item) {
                return $item['lineItemWeight'] * $item['piecesOfLineItem'] ?? 0;
            }) ?? 0;
            $totalWeight = collect($weight)->sum();
            if(isset($shippingRule['weight_from']) && $shippingRule['weight_to'] && $totalWeight >= $shippingRule['weight_from'] && $totalWeight < $shippingRule['weight_to']){
                return false;
            }
        }
        if(isset($shippingRule['isFilterPrice']) && $shippingRule['isFilterPrice']){
            $price = collect($items)->map(function ($item) {
                return $item['lineItemPrice'] * $item['piecesOfLineItem'] ?? 0;
            }) ?? 0;
            $totalPrice = collect($price)->sum() ?? 0;
            if(isset($shippingRule['price_from']) && $shippingRule['price_to'] && $totalPrice >= $shippingRule['price_from'] && $totalPrice < $shippingRule['price_to']){
                return false;
            }
        }
        if(isset($shippingRule['isFilterQuantity']) && $shippingRule['isFilterQuantity']){
            $totalQuantity = collect($items)->sum('piecesOfLineItem') ?? 0;
            if(isset($shippingRule['quantity_from']) && $shippingRule['quantity_to'] && $totalQuantity >= $shippingRule['quantity_from'] && $totalQuantity < $shippingRule['quantity_to']){
                return false;
            }
        }

        return true;
    }

    public function getCountryStates(Request $request)
    {
        $shippingRuleDetail = CountryState::getCountryStatesProvinces($request->countryCode);
        return Helpers::sendJsonResponse(false, null, $shippingRuleDetail);
    }
}
