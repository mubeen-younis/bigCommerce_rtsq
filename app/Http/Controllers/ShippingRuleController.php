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

    public function overrideRates($storeId, $lineItemData, $connectionSettings, $quote = [], $carrierName)
    {
        $isRuletrue = $isSamedayApi = false;
        $carrierType = 0;
        $isOverrideRates = false;
        $shippingRules = ShippingRule::getStoreShippingRules($storeId);
        $carrierProviders = new AdditionalCarrierTabSettingController();
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
                        $request->store_id = $value['store_id'];
                        if ($value['api_type'] == 'NEWAPI'){
                            $providerSlug = 'gtz-new';
                        } elseif ($value['api_type'] == 'CRS'){
                            $providerSlug = 'cltl';
                        }
                    } else if ($providerSlug == 'unishippers-small'){
                        if ($value['api_type'] == 'new_api'){
                            $providerSlug = 'unishippers-small-new';
                        }
                    } else if ($providerSlug == 'dayross-ltl') {
                        if ($value['api_type'] == 'sameday'){
                            $isSamedayApi = true;
                        }
                    }

                    $carrIndexName = Functions::getCarrIndexBySlug($providerSlug);
                    $request->installed_carrier_id = $carrierId;
                    $request->store_id = $storeId;
                    if($rule['rule_type'] == 6 && $carrierId != null && $carrierName == $carrIndexName){
                        $isRuletrue = $this->hideMethods($rule, $cartItems);
                        if(!$isRuletrue){
                            if(Functions::is3plCarrier($providerSlug) && $carrierType == 1){

                                $carrierProviders = $carrierProviders->index($request);
                                $serviceType = $quote['serviceType'] ?? "";
                                $serviceType = $quote['scac'] ?? $quote['CarrierSCAC'] ?? $serviceType;
                                $services = json_decode(json_encode($carrierProviders))->original->data ?? [];
                                $service = array_values(array_filter($services, fn($service) => $service->speed_freight_carrierSCAC == $serviceType))[0] ?? [];
                                
                                if(isset($service->speed_freight_carrierName) && in_array($service->speed_freight_carrierName, $rule['filter_services'])){
                                    $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                    $isOverrideRates = true;
                                }
                                // Check: if GTZ cerasis API is selected
                                if($providerSlug == 'cltl'){
                                    $service = array_values(array_filter($services, fn($service) => $service->speed_freight_carrierName == $serviceType))[0] ?? [];
                                    if(isset($service->speed_freight_carrierSCAC) && in_array($service->speed_freight_carrierSCAC, $rule['filter_services'])){
                                        $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                        $isOverrideRates = true;
                                    }
                                }
                            } else if($carrierType == 2) {
                                $serviceDesc = isset($quote['timeInTransit']['serviceDescription']) ? $quote['timeInTransit']['serviceDescription'] : '';
                                $serviceDesc = isset($quote['serviceDesc']) && !is_array($quote['serviceDesc']) ? str_replace('®', '' , $quote['serviceDesc']) : $serviceDesc;

                                if (in_array($serviceDesc, $rule['filter_services'])){
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
                                    

                                } else {
                                    $serviceType = 'Usps ' . $quote['serviceType'];

                                    if(in_array($serviceType, $rule['filter_services'])){                                    
                                        $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                        $isOverrideRates = true;
                                    }
                                }
                            } else if ($providerSlug == 'fedex-ltl') {
                                $serviceType = ucwords(strtolower(str_replace('_', ' ' , $quote['serviceType'])));
                                if(in_array($serviceType, $rule['filter_services'])){                                    
                                    $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                    $isOverrideRates = true;
                                }
                            } else if ($isSamedayApi) {

                            } else if ($providerSlug == 'rl-ltl') {
                                $serviceDesc = isset($quote['serviceDesc']) ? ucwords(strtolower($quote['serviceDesc'])) : '';
                                if(in_array($serviceDesc, $rule['filter_services'])){                                    
                                    $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                    $isOverrideRates = true;
                                }
                            } else if($carrierType == 1) {
                                $serviceType = ucwords(str_replace('-ltl', ' LTL' , $providerSlug));
                                if(in_array($serviceType, $rule['filter_services'])){                                    
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
            
            foreach ($allCategories['data'] as $key => $category) {
                $categories[] = ['key' => $category['id'], 'value' => $category['name']];
            };
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
            
            foreach ($allBrands['data'] as $key => $brand) {
                $brands[] = ['key' => $brand['id'], 'value' => $brand['name']];
            };
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
