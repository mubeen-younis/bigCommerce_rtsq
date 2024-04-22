<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Helpers\Helpers;
use App\Models\ShippingRule;
use App\Models\CountryState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Connection;
use App\CustomClasses\Functions;
use App\Http\Controllers\AdditionalCarrierTabSettingController;
use App\CustomClasses\Unishippers\small\QuotesResults;
use App\Models\Store;
use App\CurlRequest;
use App\Models\ProductSetting;
use App\Models\Carrier;

class ShippingRuleController extends Controller
{

    public function __construct()
    {
        $this->unishippers = new QuotesResults();
        $this->curlRequest = new CurlRequest();
    }
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
        return Helpers::sendJsonResponse($res['error'], $res['message'], $res['data']);
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

    public function overrideRates($storeId, $lineItemData, $connectionSettings, $quote = [], $carrierName, $originKey = '', $allOrigins = [])
    {
        $isRuletrue = $isSamedayApi = false;
        $carrierType = 0;
        $isOverrideRates = false;
        $shippingRules = ShippingRule::getStoreShippingRules($storeId);
        if(!empty($shippingRules)){
            $cartItems = !empty($lineItemData) ? $lineItemData : [];
            foreach($shippingRules as $key => $rule){
                if(isset($rule['available']) && $rule['available']){
                    
                    $providerSlug = isset($rule['filter_provider']) ? $rule['filter_provider'] : '';
                    $carrierId = isset($connectionSettings[$providerSlug]) ? $connectionSettings[$providerSlug]['creds']['installed_carrier_id'] : null;

                    $settings = Connection::join('installed_carriers', 'installed_carriers.id', 'connection_settings.installed_carrier_id')
                        ->join('carriers', 'carriers.id', 'installed_carriers.carrier_id')
                        ->select('carriers.slug', 'carriers.carrier_type', 'connection_settings.id', 'connection_settings.installed_carrier_id',
                            'connection_settings.value')
                        ->where('connection_settings.installed_carrier_id', $carrierId)->first();
                    if ($settings !== null) {
                        $value = json_decode($settings->value, true);
                        $carrierType = isset($settings['carrier_type']) ? $settings['carrier_type'] : null;
                    }

                    $request = new \Illuminate\Http\Request();

                    if ($providerSlug == 'gtz-ltl'){
                        $request->carrier_type = $value['api_type'] ?? '';
                        $request->store_id = $value['store_id'] ?? '';
                        if (isset($value['api_type']) && $value['api_type'] == 'NEWAPI'){
                            $providerSlug = 'gtz-new';
                        } elseif (isset($value['api_type']) && $value['api_type'] == 'CRS'){
                            $providerSlug = 'cltl';
                        }
                    } else if ($providerSlug == 'unishippers-small'){
                        if (isset($value['api_type']) && $value['api_type'] == 'new_api'){
                            $providerSlug = 'unishippers-small-new';
                        }
                    } else if ($providerSlug == 'dayross-ltl') {
                        if (isset($value['api_type']) && $value['api_type'] == 'sameday'){
                            $isSamedayApi = true;
                        }
                    }

                    $carrIndexName = Functions::getCarrIndexBySlug($providerSlug);
                    $request->installed_carrier_id = $carrierId;
                    $request->store_id = $storeId;
                    if($rule['rule_type'] == 6 && $carrierId != null && $carrierName == $carrIndexName){
                        $isRuletrue = $this->checkIsOverrideRuleApply($rule, $cartItems, $originKey, $allOrigins);
                        if(!$isRuletrue){
                            if($carrierType == 2) {
                                // Update Parcel carriers WS rate with override rate shipping rule
                                $serviceDesc = isset($quote['timeInTransit']['serviceDescription']) ? $quote['timeInTransit']['serviceDescription'] : '';
                                $serviceDesc = isset($quote['serviceDesc']) && !is_array($quote['serviceDesc']) ? $quote['serviceDesc'] : $serviceDesc;
                                $serviceDesc = isset($quote['service_code']) && $quote['service_code'] == 'ups_standard_international' ? $serviceDesc . ' International' : $serviceDesc;
                                $serviceDesc = str_replace(' AM®', ' A.M.' , $serviceDesc) ?? $serviceDesc;
                                $serviceDesc = str_replace('®', '' , $serviceDesc) ?? $serviceDesc;
                                $serviceDesc = str_replace(' Saturday', '' , $serviceDesc) ?? $serviceDesc;
                                $serviceDesc = str_replace('Fedex ', '' , $serviceDesc);
                                $serviceDesc = str_replace('2 Day Am', '2 Day AM' , $serviceDesc);
                                $rule['filter_services'] = str_replace('International Ground', 'Ground' , $rule['filter_services']) ?? $rule['filter_services'];
                                
                                if ($serviceDesc == $rule['filter_services']){
                                    $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                    $quote['NegotiatedRates']['Amount'] = $rule['service_rates'];
                                    $quote['shipping_amount']['amount'] = $rule['service_rates'];
                                    $isOverrideRates = true;
                                } else if ($providerSlug == 'unishippers-small') { 
                                    $serviceTitle = $this->unishippers->getServiceTitleFromServiceType($quote['serviceType']);
                                    if(in_array(ucwords(str_replace('_', ' ' , $serviceTitle)), $rule['filter_services'])){                                    
                                        $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                        $quote['NegotiatedRates']['Amount'] = $rule['service_rates'];
                                        $isOverrideRates = true;
                                    }
                                } else if ($providerSlug == 'purolator-small') {
                                    $serviceDesc = preg_replace('/(?<=[a-zA-Z])(?=\d)|(?<=\d)(?=[a-zA-Z])|(?<=[a-z])(?=[A-Z])/', ' ', $quote['serviceType']);
                                    $serviceDesc = str_replace('Am', 'AM' , $serviceDesc);

                                    if (in_array($serviceDesc, $rule['filter_services'])){
                                        $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                        $isOverrideRates = true;
                                    }
                                    

                                } else if ($providerSlug == 'usps-small') { 
                                    $serviceType = 'USPS ' . $quote['serviceType'];

                                    if(in_array($serviceType, $rule['filter_services'])){                                    
                                        $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                        $isOverrideRates = true;
                                    }
                                }
                            } else if($carrierType == 1) {
                                // Update LTL carriers WS rate with override rate shipping rule
                                $quote = $this->overrideAccessorialsfee($quote, $rule);
                                $isOverrideRates = true;
                            }
                        }
                    }
                }
            }
        }
        return ['data' => $quote, 'isOverrideRates' => $isOverrideRates];
    }

    public function surchargeRates($storeId, $lineItemData, $connectionSettings, $quote = [], $carrierName, $originKey = '', $allOrigins = [])
    {
        $isRuletrue = false;
        $carrierType = 0;
        $surchargeServiceRate = 0;
        $isSurchargeRates = false;
        $shippingRules = ShippingRule::getStoreShippingRules($storeId);
        if(!empty($shippingRules)){
            $cartItems = !empty($lineItemData) ? $lineItemData : [];
            foreach($shippingRules as $key => $rule){
                if(isset($rule['available']) && $rule['available']){
                    $providers = array_keys($connectionSettings);
                    foreach($providers as  $index){
                        $providerSlug = isset($index) ? $index: " ";
                        $carrierId = isset($connectionSettings[$providerSlug]) ? $connectionSettings[$providerSlug]['creds']['installed_carrier_id'] : null;
                        $settings = Connection::join('installed_carriers', 'installed_carriers.id', 'connection_settings.installed_carrier_id')
                            ->join('carriers', 'carriers.id', 'installed_carriers.carrier_id')
                            ->select('carriers.slug', 'carriers.carrier_type', 'connection_settings.id', 'connection_settings.installed_carrier_id',
                                'connection_settings.value')
                            ->where('connection_settings.installed_carrier_id', $carrierId)->first();
                        if ($settings !== null) {
                            $value = json_decode($settings->value, true);
                            $carrierType = isset($settings['carrier_type']) ? $settings['carrier_type'] : null;
                        }
                        
                        $request = new \Illuminate\Http\Request();
    
                        $carrIndexName = Functions::getCarrIndexBySlug($providerSlug);
                        $request->installed_carrier_id = $carrierId;
                        $request->store_id = $storeId;
                        if ($providerSlug == 'gtz-ltl'){
                            $request->carrier_type = $value['api_type'] ?? '';
                            $request->store_id = $value['store_id'] ?? '';
                            if (isset($value['api_type']) && $value['api_type'] == 'NEWAPI'){
                                $providerSlug = 'gtz-new';
                            } elseif (isset($value['api_type']) && $value['api_type'] == 'CRS'){
                                $providerSlug = 'cltl';
                            }
                        }
                        
                        if($carrierType == 2){
                            switch ($rule['apply_to']) {
                                case 0:
                                    $isRuletrue = $this->checkIsSurchargeRuleApply($rule, $cartItems, $originKey, $allOrigins);
                                    break;
                                case 1:
                                    $isRuletrue = $this->hideMethods($rule, $cartItems);
                                    break;
                                case 2:
                                    $applyRuleTo = $rule['apply_rule_to'] ?? 1;
                                    switch ($applyRuleTo) {
                                        case 1:
                                            $isRuletrue = $this->applyRuleOnCategories($rule, $cartItems, $originKey, $allOrigins);
                                            break;
                                        case 2:
                                            $isRuletrue = $this->applyRuleOnBrands($rule, $cartItems, $originKey, $allOrigins);
                                            break;
                                        case 3:
                                            $isRuletrue = $this->applyRuleOnProducts($rule, $cartItems, $originKey, $allOrigins);
                                            break;
                                        default:
                                            break;
                                    }
                                    break;
                                default:
                                    break;
                            }
                            if(!$isRuletrue){
                                $serviceDesc = isset($quote['timeInTransit']['serviceDescription']) ? $quote['timeInTransit']['serviceDescription'] : '';
                                $serviceDesc = isset($quote['serviceDesc']) && !is_array($quote['serviceDesc']) ? str_replace('®', '' , $quote['serviceDesc']) : $serviceDesc;
                                $serviceDesc = str_replace(' Saturday', '' , $serviceDesc) ?? $serviceDesc;
                                if(isset($quote['serviceDesc'])){
                                    if ($serviceDesc == $quote['serviceDesc']){
                                        $quote['totalNetCharge']['Amount'] += (float) $rule['service_rates'] ?? 0;
                                        $quote['NegotiatedRates']['Amount'] += (float) $rule['service_rates'] ?? 0;
                                        $isSurchargeRates = true;
                                    } 
                                } else if ($providerSlug == 'usps-small') { 
                                    $quote['totalNetCharge']['Amount'] += (float) $rule['service_rates'] ?? 0;
                                    $isSurchargeRates = true;
                                } 
                            }
                        }
                        if ($rule['rule_type'] == 8 && $carrierId !== null && $carrierName == $carrIndexName) {
                            switch ($rule['apply_to']) {
                                case 0:
                                    $isRuletrue = $this->checkIsSurchargeRuleApply($rule, $cartItems, $originKey, $allOrigins);
                                    if (!$isRuletrue && $carrierType == 1) {
                                        $quote = $this->surchargeRatesAccessorialsfee($quote, $rule);
                                        $isSurchargeRates = true;
                                    }
                                    break;
                                case 1:
                                    $isRuletrue = $this->hideMethods($rule, $cartItems);
                                    if (!$isRuletrue && $carrierType == 1) {
                                        $quote = $this->surchargeRatesAccessorialsfee($quote, $rule);
                                        $isSurchargeRates = true;
                                    }
                                    break;
                                case 2:
                                    $applyRuleTo = $rule['apply_rule_to'] ?? 1;
                                    switch ($applyRuleTo) {
                                        case 1:
                                            $isRuletrue = $this->applyRuleOnCategories($rule, $cartItems, $originKey, $allOrigins);
                                            break;
                                        case 2:
                                            $isRuletrue = $this->applyRuleOnBrands($rule, $cartItems, $originKey, $allOrigins);
                                            break;
                                        case 3:
                                            $isRuletrue = $this->applyRuleOnProducts($rule, $cartItems, $originKey, $allOrigins);
                                            break;
                                        default:
                                            break;
                                    }
                                    if (!$isRuletrue && $carrierType == 1) {
                                        $quote = $this->surchargeRatesAccessorialsfee($quote, $rule);
                                        $isSurchargeRates = true;
                                    }
                                    break;
                                default:
                                    break;
                            }
                        }  
                    }
                    $surchargeServiceRate = $isSurchargeRates? $rule['service_rates'] : 0;
                }
            }
        }  
        return ['data' => $quote, 'isSurchargeRates' => $isSurchargeRates, 'surchargeServiceRate' => $surchargeServiceRate ];
    }
    

    public function applyRuleOnCategories($rule, $cartItems, $originKey, $allOrigins)
    {
        
        $restrictedCategories = isset($rule['categories']) ? $rule['categories'] : [];

        if(!empty($restrictedCategories)){
            $categoriesIds = array_column($cartItems, 'categories_id');
            $flattenedCategoriesIds = array_values(array_merge(...$categoriesIds)) ?? [];
            $istrue = true;
  
            $filterCategories = collect($restrictedCategories)->intersect($flattenedCategoriesIds) ?? [];
            foreach($filterCategories as $categoryId){
                $categoriesProducts = collect($cartItems)->filter(function ($item) use ($categoryId) {
                    return in_array($categoryId , $item['categories_id']);
                })->toArray() ?? [];

                if(!empty($categoriesProducts)){
                    $istrue = $this->checkIsSurchargeRuleApply($rule, $categoriesProducts, $originKey, $allOrigins);
                    return $istrue;
                }
            }
            return $istrue;
        }
        return true;
    }

    public function applyRuleOnBrands($rule, $cartItems, $originKey, $allOrigins)
    {
        $restrictedBrands = isset($rule['brands']) ? $rule['brands'] : [];
        $istrue = true;
        if(!empty($restrictedBrands)){
            foreach($restrictedBrands as $rpKey => $brandId){

                $filterBrands = collect($cartItems)->where('brand_id', $brandId)->all() ?? [];
                if(!empty($filterBrands)){
                    $istrue = $this->checkIsSurchargeRuleApply($rule, $filterBrands, $originKey, $allOrigins);
                    return $istrue;
                }
            }
            return $istrue;
        }
        return true;
    }

    public function applyRuleOnProducts($rule, $cartItems, $originKey, $allOrigins)
    {
        $restrictedProducts = isset($rule['products']) ? $rule['products'] : [];
        $istrue = true;

        if(!empty($restrictedProducts)){
            foreach($restrictedProducts as $rpKey => $productId){
                $filterProducts = collect($cartItems)->where('product_id', $productId['key'])->all() ?? [];
                if(!empty($filterProducts)){
                    $istrue = $this->checkIsSurchargeRuleApply($rule, $cartItems, $originKey, $allOrigins);
                    return $istrue;
                }
            }
            return $istrue;
        }
        return true;
    }


    public function overrideAccessorialsfee($quote, $rule)
    {
        $updateCount = 0;
        $serviceIndex = Functions::$accessorialServices;
        // Update WS accessorials rate with override rates shipping rule accessorials rate
        foreach($serviceIndex as $key => $index){
            if (isset($rule['service_rates']) && $rule['service_rates'] >= 0 && $rule['filter_services'] == $key && isset($quote['surcharges'][$index])){
                
                $quote['totalNetCharge']['Amount'] -= (float) $quote['surcharges'][$index] ?? 0;
                $quote['surcharges'][$index] = $rule['service_rates'];
                $quote['totalNetCharge']['Amount'] += (float) $rule['service_rates'] ?? 0;
                break;
            }
            // Update WS base price with override rate shipping rule base price
            if (isset($rule['service_rates']) && $rule['service_rates'] >= 0 && $rule['filter_services'] == 'transportation'){
                if($updateCount < 1){
                    $quote['totalNetCharge']['Amount'] = $rule['service_rates'] ?? 0;
                }
                // Add WS accessorials rate into override rate shipping rule base price
                $quote['totalNetCharge']['Amount'] += isset($quote['surcharges'][$index]) ? (float) $quote['surcharges'][$index] : 0;
            }
            $updateCount++;
        }

        return $quote;
    }

    public function surchargeRatesAccessorialsfee($quote, $rule)
    {
        $updateCount = 0;
        $serviceIndex = Functions::$accessorialServices;
        // Update WS accessorials rate with Surcharge rates shipping rule accessorials rate
        foreach($serviceIndex as $index){
            if (isset($rule['service_rates']) && $rule['service_rates'] >= 0 && isset($quote['surcharges'][$index])){
                if($updateCount < 1) {
                    $quote['totalNetCharge']['Amount'] += (float) $rule['service_rates'] ?? 0;
                }
                $updateCount++;
            }
        }

        return $quote;
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
        $quoteSettings['autoDetectedResidentialAddressesLfg'] = false;
        $quoteSettings['always_two_man_delivery'] = false;
        $quoteSettings['always_appointment_delivery'] = false;
        
        return $quoteSettings;
    }

    public function hideMethods($shippingRule, $items)
    {
        $isFilterWeight = $isFilterPrice = $isFilterQuantity = false;
        if(isset($shippingRule['isFilterWeight']) && $shippingRule['isFilterWeight']){
            $weight = collect($items)->map(function ($item) {
                return $item['lineItemWeight'] * $item['piecesOfLineItem'] ?? 0;
            }) ?? 0;
            $totalWeight = collect($weight)->sum();
            if(isset($shippingRule['weight_from']) && $totalWeight >= $shippingRule['weight_from'] && isset($shippingRule['weight_to']) && ($totalWeight < $shippingRule['weight_to'] || $shippingRule['weight_to'] === '')){
                $isFilterWeight = true;
            }
        }
        if(isset($shippingRule['isFilterPrice']) && $shippingRule['isFilterPrice']){
            $price = collect($items)->map(function ($item) {
                return $item['lineItemPrice'] * $item['piecesOfLineItem'] ?? 0;
            }) ?? 0;
            $totalPrice = collect($price)->sum() ?? 0;
            if(isset($shippingRule['price_from']) && $totalPrice >= $shippingRule['price_from'] && isset($shippingRule['price_to']) && ($totalPrice < $shippingRule['price_to'] || $shippingRule['price_to'] === '')){
                $isFilterPrice = true;
            }
        }
        if(isset($shippingRule['isFilterQuantity']) && $shippingRule['isFilterQuantity']){
            $totalQuantity = collect($items)->sum('piecesOfLineItem') ?? 0;
            if(isset($shippingRule['quantity_from']) && $totalQuantity >= $shippingRule['quantity_from'] && isset($shippingRule['quantity_to']) && ($totalQuantity < $shippingRule['quantity_to'] || $shippingRule['quantity_to'] === '')){
                $isFilterQuantity = true;
            }
        }

        if($isFilterWeight || $isFilterPrice || $isFilterQuantity){
            return false;
        }

        return true;
    }

    public function checkIsOverrideRuleApply($shippingRule, $items, $shipmentKey, $allOrigins)
    {
        $variants = [];
        $totalWeight = 0;
        $totalQuantity = 0;
        $totalPrice = 0;
        $isFilterWeight = $isFilterPrice = $isFilterQuantity = false;

        if (!empty($allOrigins)) {
            $variants = collect($allOrigins)->filter(function ($origin) use ($shipmentKey) {
            return $origin['locationId'] == $shipmentKey;})->keys()->all() ?? [];
        }

        if (!empty($variants)) {
            foreach($variants as $variantId){
                if(isset($items[$variantId])){
                    $item = $items[$variantId];
                    
                    $totalWeight += $item['lineItemWeight'] * $item['piecesOfLineItem'] ?? 0;
                    $totalPrice += $item['lineItemPrice'] * $item['piecesOfLineItem'] ?? 0;
                    $totalQuantity += $item['piecesOfLineItem'] ?? 0;
                }
                
            }
        }

        
        if(isset($shippingRule['isFilterWeight']) && $shippingRule['isFilterWeight']){
            if(isset($shippingRule['weight_from']) && $totalWeight >= $shippingRule['weight_from'] && isset($shippingRule['weight_to']) && ($totalWeight < $shippingRule['weight_to'] || $shippingRule['weight_to'] === '')){
                $isFilterWeight = true;
            }
        }
        if(isset($shippingRule['isFilterPrice']) && $shippingRule['isFilterPrice']){
            if(isset($shippingRule['price_from']) && $totalPrice >= $shippingRule['price_from'] && isset($shippingRule['price_to']) && ($totalPrice < $shippingRule['price_to'] || $shippingRule['price_to'] === '')){
                $isFilterPrice = true;
            }
        }
        if(isset($shippingRule['isFilterQuantity']) && $shippingRule['isFilterQuantity']){
            if(isset($shippingRule['quantity_from']) && $totalQuantity >= $shippingRule['quantity_from'] && isset($shippingRule['quantity_to']) && ($totalQuantity < $shippingRule['quantity_to'] || $shippingRule['quantity_to'] === '')){
                $isFilterQuantity = true;
            }
        }

        if($isFilterWeight || $isFilterPrice || $isFilterQuantity){
            return false;
        }

        return true;
    }

    public function checkIsSurchargeRuleApply($shippingRule, $items, $shipmentKey, $allOrigins)
    {
        $variants = [];
        $totalWeight = 0;
        $totalQuantity = 0;
        $totalPrice = 0;
        $isFilterWeight = $isFilterPrice = $isFilterQuantity = false;
        
        if (!empty($allOrigins)) {
            $variants = collect($allOrigins)->filter(function ($origin) use ($shipmentKey) {
            return $origin['locationId'] == $shipmentKey;})->keys()->all() ?? [];
        }
        
        if (!empty($variants)) {
            foreach($variants as $variantId){
                if(isset($items[$variantId])){
                    $item = $items[$variantId];
                    $totalWeight += $item['lineItemWeight'] * $item['piecesOfLineItem'] ?? 0;
                    $totalPrice += $item['lineItemPrice'] * $item['piecesOfLineItem'] ?? 0;
                    $totalQuantity += $item['piecesOfLineItem'] ?? 0;
                }
                
            }
        }
        
        if(isset($shippingRule['isFilterWeight']) && $shippingRule['isFilterWeight']){
            if(isset($shippingRule['weight_from']) && $totalWeight >= $shippingRule['weight_from'] && isset($shippingRule['weight_to']) && ($totalWeight < $shippingRule['weight_to'] || $shippingRule['weight_to'] === '')){
                $isFilterWeight = true;
            }
        }
        if(isset($shippingRule['isFilterPrice']) && $shippingRule['isFilterPrice']){
            if(isset($shippingRule['price_from']) && $totalPrice >= $shippingRule['price_from'] && isset($shippingRule['price_to']) && ($totalPrice < $shippingRule['price_to'] || $shippingRule['price_to'] === '')){
                $isFilterPrice = true;
            }
        }
        if(isset($shippingRule['isFilterQuantity']) && $shippingRule['isFilterQuantity']){
            if(isset($shippingRule['quantity_from']) && $totalQuantity >= $shippingRule['quantity_from'] && isset($shippingRule['quantity_to']) && ($totalQuantity < $shippingRule['quantity_to'] || $shippingRule['quantity_to'] === '')){
                $isFilterQuantity = true;
            }
        }

        if($isFilterWeight || $isFilterPrice || $isFilterQuantity){
            return false;
        }

        return true;
    }

    public function getCountryStates(Request $request)
    {
        $shippingRuleDetail = CountryState::getCountryStatesProvinces($request->countryCode);
        return Helpers::sendJsonResponse(false, null, $shippingRuleDetail);
    }

    public function getProductsCategories(Request $request)
    {

        $store = Store::where('hash', $request['store_hash'])->first();
        if (empty($store)) {
            return [];
        }
        $headers[] = 'X-Auth-Token: ' . $store->access_token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = "https://api.bigcommerce.com/stores/" . $request['store_hash'] . "/v3/catalog/categories";
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $allCategories = json_decode($response['response'], true);

            $categories = [];
            if(isset($allCategories['data']) && !empty($allCategories['data'])){
                foreach ($allCategories['data'] as $key => $category) {
                    $categories[] = ['key' => $category['id'], 'value' => $category['name']];
                };
            }
        }
        return Helpers::sendJsonResponse(false, null, $categories);;
    }

    public function getProductsBrands(Request $request)
    {

        $store = Store::where('hash', $request['store_hash'])->first();
        if (empty($store)) {
            return [];
        }
        $headers[] = 'X-Auth-Token: ' . $store->access_token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = "https://api.bigcommerce.com/stores/" . $request['store_hash'] . "/v3/catalog/brands";
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $allBrands = json_decode($response['response'], true);
    
            $brands = [];
            if(isset($allBrands['data']) && !empty($allBrands['data'])){
                foreach ($allBrands['data'] as $key => $brand) {
                    $brands[] = ['key' => $brand['id'], 'value' => $brand['name']];
                };
            }
        }
        return Helpers::sendJsonResponse(false, null, $brands);
    }

    public function getshippingRuleProductsFromDb(Request $request)
    {
        try {
            
            $search = $request['search'] ?? null;
            $perPage = 50;
            
            if ($search != null || $search == '') {
                $count = ProductSetting::where('store_id', $request->store_id)
                    ->where(function ($query) use ($search) {
                        $query->where('name', 'LIKE', '%' . $search . '%')
                            ->orWhere('sku', 'LIKE', '%' . $search . '%')
                            ->orWhere('variant_id', $search)
                            ->orWhere('source_product_id', $search);
                    })
                    ->orderBy('name', 'ASC')
                    ->get();
            }

            if ($count->count()) {
                $count = $count->groupBy('source_product_id')->count();
            } else {
                $count = 0;
            }
            
            if ($search != null || $search == '') {
                $products = ProductSetting::where(function ($query) use ($search) {
                    $query->where('name', 'LIKE', '%' . $search . '%')
                        ->orWhere('sku', 'LIKE', '%' . $search . '%')
                        ->orWhere('variant_id', $search)
                        ->orWhere('source_product_id', $search);
                })->where('store_id', $request->store_id)
                    ->orderBy('name', 'ASC')
                    ->groupBy('source_product_id')
                    ->take($perPage)->get();
            }

            if ($products->isEmpty()) {
                return response()->json(['error' => true,
                    'data' => [],
                    'message' => 'No Products Available',
                ], 200);
            }

            $resp = response()->json(['error' => false,
                'data' => $products,
                'message' => '',
            ], 200);
            return $resp;
        } catch (\Exception $exception) {
            Log::info('catch: ' . json_encode($exception->getMessage()));
        }

    }
}
