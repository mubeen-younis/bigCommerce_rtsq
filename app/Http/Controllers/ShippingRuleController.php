<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Helpers\Helpers;
use App\Models\ShippingRule;
use App\Models\CountryState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\AdditionalCarrierTabSetting;
use App\Models\Connection;
use App\CustomClasses\Functions;
use App\Http\Controllers\AdditionalCarrierTabSettingController;

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

    public function applyHideMethodRule($storeId, $lineItemData, $connectionSettings)
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

    public function overrideRates($storeId, $lineItemData, $connectionSettings, $quote = [], $carrierName)
    {
        $isRuletrue = false;
        $isOverrideRates = false;
        $shippingRules = ShippingRule::getStoreShippingRules($storeId);
        $carrierProviders = new AdditionalCarrierTabSettingController();
        if(!empty($shippingRules)){
            $cartItems = !empty($lineItemData) ? $lineItemData : [];
            foreach($shippingRules as $key => $rule){
                if(isset($rule['available']) && $rule['available']){

                    $providerSlug = isset($rule['filter_provider']) ? $rule['filter_provider'] : '';
                    $carrierId = isset($connectionSettings[$providerSlug]) ? $connectionSettings[$providerSlug]['creds']['installed_carrier_id'] : null;
                    
                    $request = new \Illuminate\Http\Request();
                    $request->installed_carrier_id = $carrierId;
                    $request->store_id = $storeId;
                    if($rule['rule_type'] == 5 && $carrierId != null){
                        
                        $isRuletrue = $this->hideMethods($rule, $cartItems);
                        if(!$isRuletrue){
                            if(Functions::is3plCarrier($providerSlug)){
                
                                if ($providerSlug == 'gtz-ltl'){
                                    $settings = Connection::join('installed_carriers', 'installed_carriers.id', 'connection_settings.installed_carrier_id')
                                    ->join('carriers', 'carriers.id', 'installed_carriers.carrier_id')
                                    ->select('carriers.slug', 'connection_settings.id', 'connection_settings.installed_carrier_id',
                                        'connection_settings.value')
                                    ->where('connection_settings.installed_carrier_id', $carrierId)->first();
                                    if ($settings !== null) {
                                        $value = json_decode($settings->value, true);
                                    }
                                
                                    $request->carrier_type = $value['api_type'] ?? '';
                                    $request->store_id = $value['store_id'];
                                    if ($value['api_type'] == 'NEWAPI'){
                                        $providerSlug = 'gtz-new';
                                    } elseif ($value['api_type'] == 'CRS'){
                                        $providerSlug = 'cltl';
                                    }
                                }

                                $carrIndexName = Functions::getCarrIndexBySlug($providerSlug);
                                $carrierProviders = $carrierProviders->index($request);
                                $serviceType = $quote['serviceType'] ?? "";
                                $serviceType = $quote['scac'] ?? $quote['CarrierSCAC'] ?? $serviceType;
                                $services = json_decode(json_encode($carrierProviders))->original->data ?? [];
                                $service = array_values(array_filter($services, fn($service) => $service->speed_freight_carrierSCAC == $serviceType))[0] ?? [];

                                if(isset($service->speed_freight_carrierName) && in_array($service->speed_freight_carrierName, $rule['filter_services']) && $carrierName == $carrIndexName){
                                    $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                    $isOverrideRates = true;
                                }
                            }
                        }
                    }
                }
            }
        }
        return ['data' => $quote, 'isOverrideRates' => $isOverrideRates];
    }

    public function disableAllAccessorials($quoteSettings)
    {
        $quoteSettings['offer_inside_delivery'] = false;
        $quoteSettings['offerLiftGateDelivery'] = false;
        $quoteSettings['offer_limited_access_delivery'] = false;
        $quoteSettings['offer_notify_as_option'] = false;
        $quoteSettings['always_inside_delivery'] = false;
        $quoteSettings['alwaysLiftGateDelivery'] = false;
        $quoteSettings['always_quote_notify'] = false;
        $quoteSettings['always_limited_access_delivery'] = false;
        
        return $quoteSettings;
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
