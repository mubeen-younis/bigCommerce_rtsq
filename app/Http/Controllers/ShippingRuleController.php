<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Helpers\Helpers;
use App\Models\ShippingRule;
use App\Models\CountryState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\CurlRequest;

class ShippingRuleController extends Controller
{
    public $curlRequest;
    public $mainController;

    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
        $this->mainController = new MainController();
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

    public function getStoreCategories(Request $request)
    {
        $categoriesArray = [];
        $storeCategories = [];
        $storeId = $request['store_id'] ?? '';
        $storeHash = $request['store_hash'] ?? '';
        $storeToken = $this->mainController->getCustAccessTok($storeId);
        $data['store_token'] = $storeToken;
        $data['store_id'] = $storeId;
        $data['store_hash'] = $storeHash;
        if (isset($storeToken['status']) && $storeToken['status'] == false) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Token Not Found',
            ], 200);
        }
       
        $storeUrl = 'https://api.bigcommerce.com/stores/' . $data['store_hash'] . '/v3/catalog/categories';
        $headers[] = 'X-Auth-Token: ' . $storeToken;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $response = $this->curlRequest->enSingleCurlRequest($storeUrl, [], $headers, 'GET', true);
        if(isset($response['status']) && $response['status'] == true){
            $response = json_decode($response['response'], true);
            $categoriesArray = isset($response['data']) && !empty($response['data']) ? $response['data'] : [];
        }

        foreach($categoriesArray as $category){
            $storeCategories[] = ['id' => isset($category['id']) ? $category['id'] : '', 'name' => isset($category['name']) ? $category['name'] : ''];
        }
        
        return Helpers::sendJsonResponse(false, null, $storeCategories);
    }

    public function getStoreBrands(Request $request)
    {
        $brandsArray = [];
        $storeBrands = [];
        $storeId = $request['store_id'] ?? '';
        $storeHash = $request['store_hash'] ?? '';
        $storeToken = $this->mainController->getCustAccessTok($storeId);
        $data['store_token'] = $storeToken;
        $data['store_id'] = $storeId;
        $data['store_hash'] = $storeHash;
        if (isset($storeToken['status']) && $storeToken['status'] == false) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Token Not Found',
            ], 200);
        }
       
        $storeUrl = 'https://api.bigcommerce.com/stores/' . $data['store_hash'] . '/v3/catalog/brands';
        $headers[] = 'X-Auth-Token: ' . $storeToken;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $response = $this->curlRequest->enSingleCurlRequest($storeUrl, [], $headers, 'GET', true);
        if(isset($response['status']) && $response['status'] == true){
            $response = json_decode($response['response'], true);
            $brandsArray = isset($response['data']) && !empty($response['data']) ? $response['data'] : [];
        }

        foreach($brandsArray as $brand){
            $storeBrands[] = ['id' => isset($brand['id']) ? $brand['id'] : '', 'name' => isset($brand['name']) ? $brand['name'] : ''];
        }
        
        return Helpers::sendJsonResponse(false, null, $storeBrands);
    }
}
