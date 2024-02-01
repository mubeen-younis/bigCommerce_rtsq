<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Helpers\Helpers;
use App\Models\ShippingRule;
use App\Models\CountryState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Store;
use App\CurlRequest;
use App\Models\ProductSetting;

class ShippingRuleController extends Controller
{
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */

    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
    }

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
                    ->get();
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
