<?php

namespace App\Http\Controllers;

use App\Helpers\Helpers;
use App\Models\ProductSetting;
use Illuminate\Http\Request;
use App\Http\Controllers\ProductSettingController;
use App\CustomClasses\BigCommerceFunctions;
use App\CurlRequest;
use App\Models\NestingItemsDetail;
use App\CustomClasses\Functions;
use Illuminate\Support\Facades\Log;

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
            ], 200);
        }

        $ProductSettings = new ProductSettingController();
        $headers = BigCommerceFunctions::getHeaders($request['store_hash']);
        $storeUrl = BigCommerceFunctions::$initalUrl . $request['store_hash'] . '/v3/catalog/products/' . $request['product_id'];
        $response = $ProductSettings->curlRequest->enSingleCurlRequest($storeUrl, [], $headers, 'GET', true);
        if(Functions::isEnabledLogs($request['store_hash'])){
            Log::info('Get products from BC using FDO call' . json_encode($response));
        }
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

                    if(count($variants)){
                        foreach ($variants as $key => $variant) {

                            $DBproduct = ProductSetting::where('source_product_id', $variant['product_id'])
                            ->where('variant_id', $variant['id'])
                            ->where('store_id', $request->store_id)
                            ->first();

                            $variant = $ProductSettings->setVariantDimensions($variant, $product);
                            $variant['name'] = isset($product['name']) ? $product['name'] : '' ?? '';
                            $variant['source_product_id'] = isset($variant['product_id']) ? $variant['product_id'] : '' ?? '';
                            $variant['variant_id'] = isset($variant['id']) ? $variant['id'] : null ?? null;
                            $variant['store_id'] = isset($request->store_id) ? $request->store_id : null ?? null;
                            $variant['settings'] = $ProductSettings->setShippingMethod($variant, $request['store_id']);

                            if(!empty($DBproduct)){
                                $variant = $ProductSettings->getProductIndex($variant, $DBproduct);
                            } else{
                                unset($variant['id']);
                            }

                            $products[$key] = $variant;
                        }
                    }
                }                    
            } else {

                $DBproduct = ProductSetting::where('source_product_id', $request->product_id)
                    ->where('variant_id', $product['base_variant_id'])
                    ->where('store_id', $request->store_id)
                    ->first();

                $product = $ProductSettings->setVariantDimensions($product, []);
                $product['source_product_id'] = isset($product['id']) ? $product['id'] : null ?? null;
                $product['variant_id'] = isset($product['base_variant_id']) ? $product['base_variant_id'] : null ?? null;
                $product['settings'] = $ProductSettings->setShippingMethod($product, $request['store_id']);

                if(!empty($DBproduct)){
                    $product = $ProductSettings->getProductIndex($product, $DBproduct);
                } else{
                    unset($product['id']);
                }
                
                $products[0] = $product;
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
