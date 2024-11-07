<?php

namespace App\Http\Controllers;

use App\Helpers\Helpers;
use App\Models\ProductSetting;
use Illuminate\Http\Request;
use App\Http\Controllers\ProductSettingController;
use App\CustomClasses\BigCommerceFunctions;
use App\CurlRequest;
use App\Models\NestingItemsDetail;

class FDOProductController extends Controller
{
    //

    public function getVariantDetail(Request $request, $variantID)
    {
        if (empty($variantID)) {
            return Helpers::sendJsonResponseFdo(true, 'No variant ID');
        }
        $product = ProductSetting::where('variant_id', $variantID)
            ->first();
        if ($product === null) {
            return Helpers::sendJsonResponseFdo(true, 'No Variant found');
        }
        return Helpers::sendJsonResponseFdo(false, '', $product);
    }

    public function getProductDetails(Request $request)
    {
        if (empty($request->product_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Product Id',
            ], 404);
        }
        $DBproducts = ProductSetting::where('source_product_id', $request->product_id)
            ->whereNotNull('variant_id')
            ->where('store_id', $request->store_id)
            ->get();

        $ProductSettings = new ProductSettingController();
        $headers = BigCommerceFunctions::getHeaders($request['store_hash']);
        $storeUrl = BigCommerceFunctions::$initalUrl . $request['store_hash'] . '/v3/catalog/products/' . $request['product_id'];
        $response = $ProductSettings->curlRequest->enSingleCurlRequest($storeUrl, [], $headers, 'GET', true);
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $response = json_decode($response['response'], true);
            $product = $response['data'] ?? [];
            $products = [];

            if($product['base_variant_id'] == null){
                $variantEndPoint = BigCommerceFunctions::$initalUrl . $request['store_hash'] . '/v3/catalog/products/' . $request['product_id'] . '/variants?limit=250' ;
                $response = $ProductSettings->curlRequest->enSingleCurlRequest($variantEndPoint, [], $headers, 'GET', true);
                if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
                    $response = json_decode($response['response'], true);
                    $variants = $response['data'] ?? [];
                    if($DBproducts->count()){

                        if (count($DBproducts)) {
                            foreach ($DBproducts as $key => $DBvariant) {

                                $variant = collect($variants)->firstWhere('id', $DBvariant->variant_id);
                                $products = $ProductSettings->getProductIndex($variant, $DBproducts, $key);
                                $products[$key]['name'] = isset($product['name']) ? $product['name'] : '' ?? '';
                            }
                        }

                    } else {
                        if (count($variants)) {
                            foreach ($variants as $key => $variant) {
                                
                                $products = $ProductSettings->getProductIndex($variant, $products, $key);
                                $products[$key]['name'] = isset($product['name']) ? $product['name'] : '' ?? '';
                                $products[$key]['source_product_id'] = isset($variant['product_id']) ? $variant['product_id'] : '' ?? '';
                                $products[$key]['variant_id'] = isset($variant['id']) ? $variant['id'] : null ?? null;
                                $products[$key]['settings'] = $ProductSettings->setShippingMethod($variant, $request['store_id']);
                            }   
                        }
                    }
                }                    
            } else {

                if($DBproducts->count()){
                    
                    $products = $ProductSettings->getProductIndex($product, $DBproducts);

                } else {

                    $products = $ProductSettings->getProductIndex($product, $products);
                    $products[0]['source_product_id'] = isset($product['id']) ? $product['id'] : null ?? null;
                    $products[0]['variant_id'] = isset($product['base_variant_id']) ? $product['base_variant_id'] : null ?? null;
                    $products[0]['settings'] = $ProductSettings->setShippingMethod($product, $request['store_id']);
                }
            }
        } else {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Products Available',
            ], 200);
        }

        if(!empty($products)){
            foreach($products as $key => $product){
                if(!empty($product['id'])){
                    $nestingItemsDetails = optional(NestingItemsDetail::where('product_settings_id', $product['id'])->first())->toArray() ?? [];
                    $products[$key]['dimension_type'] = !empty($nestingItemsDetails['dimension_type']) ? $nestingItemsDetails['dimension_type'] : 0;
                    $products[$key]['nesting_percentage'] = !empty($nestingItemsDetails['nesting_percentage']) ? $nestingItemsDetails['nesting_percentage'] : null;
                    $products[$key]['stacked_type'] = !empty($nestingItemsDetails['stacked_type']) ? $nestingItemsDetails['stacked_type'] : 0;
                    $products[$key]['max_nested_items'] = !empty($nestingItemsDetails['max_nested_items']) ? $nestingItemsDetails['max_nested_items'] : null;
                    $products[$key]['is_nesting_enabled'] = !empty($nestingItemsDetails['is_nesting_enabled']) ? $nestingItemsDetails['is_nesting_enabled'] : 0;
                }
            }
        }
        
        if (empty($products)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Products Available',
            ], 200);
        }
        return response()->json(['error' => false,
            'data' => $products,
            'message' => '',
        ], 200);
    }
}
