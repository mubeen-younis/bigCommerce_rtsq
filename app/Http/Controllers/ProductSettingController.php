<?php

namespace App\Http\Controllers;

use App\CurlRequest;
use App\Jobs\ImportProductsFromBCStore;
use App\Jobs\ImportProductsFromBCStoreStatusUpdate;
use App\Jobs\SKUWebhookImport;
use App\Models\ProductSetting;
use App\Models\ImportProducts as ImportProductsModel;
use Illuminate\Http\Request;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProductWebhookImport;
use App\Models\NestingItemsDetail;
use App\CustomClasses\Functions;
use App\Http\Controllers\GetRatesController;

class ProductSettingController extends Controller
{
    public $curlRequest;
    public $mainController;
    public $saveProducts;
    public $categoryArray;

    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
        $this->mainController = new MainController();
        $this->saveProducts = new ProductSetting();
        $this->categoryArray = [];
    }

    public function importProducts(Request $request)
    {
        set_time_limit(0);
        Log::info('started sync process');
        // return back due to store plan expired
        $GetRatesController = new GetRatesController();
        if (!$GetRatesController->storePlanStatus($request['store_id'])) {
            return response()->json(['error' => true,
                    'data' => [],
                    'message' => 'Your current plan has expired. Please renew your plan.',
                ], 200);
        }
        $isSyncinProgress = ImportProductsModel::where('store_id', $request['store_id'])->where('status', '=', 1)->where('created_at', '>', Carbon::now()->subDay()->toDateTimeString())->exists();
        if (!$isSyncinProgress) {
            $importPrdModel = new ImportProductsModel();
            $importPrdModel->store_id = $request['store_id'];
            $importPrdModel->status = 1;
            $importPrdModel->save();
            $insertedId = $importPrdModel->id;
            $data['store_hash'] = $request['store_hash'];
            $data['store_token'] = $this->mainController->getCustAccessTok($request['store_id']);
            $data['store_id'] = $request['store_id'];
            $data['perpage'] = 250;
            $totalpages = $this->importProductsGetPages($data);
            // Adds seconds of delay
            $delay = 1;

            for ($page = 1; $page <= $totalpages; $page++) {
                $data['page'] = $page;
                // Need to add this and comment below line if you want to execute without queue job or in dev server $this->importProductsJob($data);
                ImportProductsFromBCStore::dispatch($data)->delay(Carbon::now()->addSeconds($delay++));
            }
            Log::info('Syncing inprogress');
            
            ImportProductsFromBCStoreStatusUpdate::dispatch($insertedId, $request['email'])->delay(Carbon::now()->addSeconds(5));
        }

    }

    public function importProductsJob($data)
    {
        $storeUrl = 'https://api.bigcommerce.com/stores/' . $data['store_hash'] . '/v3/catalog/products?limit=' . $data['perpage'] . '&page=' . $data['page'];
        unset($headers);
        $headers[] = 'X-Auth-Token: ' . $data['store_token'];
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $response = $this->curlRequest->enSingleCurlRequest($storeUrl, [], $headers, 'GET', true);
        if (isset($response['status']) && $response['status'] == false) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => $response['response'],
            ]);
        }
        $response = json_decode($response['response'], true);

        if(Functions::isEnabledLogs($data['store_hash'])){
            Log::info('Get products from BC using sync process' . json_encode($response));
        }

        if (isset($response['data']) && count($response['data'])) {
            foreach ($response['data'] as $product) {

                /*
                 * $product['base_variant_id'] = null mean this has variants and iterate those
                 * otherwise base product is as a variant product
                 * */
                if ($product['base_variant_id'] == null) {
                    $this->saveProducts->saveProductFromSync($product, $data['store_id']);
                    $this->getVariants($product, $data, '', false);
                } else {
                    $this->saveProducts->saveProductFromSync($product, $data['store_id']);
                }

                //$this->saveProducts->saveProduct($product, $data['store_id']);
            }
        }
    }

    public function getVariants($product, $data, $scope = null, $useTransaction = true)
    {
        $metaEndPoint = 'https://api.bigcommerce.com/stores/' . $data['store_hash'] . '/v3/catalog/products/' . $product['id'] . '/variants?limit=250';
        unset($headers);
        $headers[] = 'X-Auth-Token: ' . $data['store_token'];
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $metaResponse = $this->curlRequest->enSingleCurlRequest($metaEndPoint, [], $headers, 'GET', true);
        $metaResponse = json_decode($metaResponse['response'], true);
        $total_pages = $metaResponse['meta']['pagination']['total_pages'] ?? null;
        if (blank($total_pages)) {
            return null;
        }
        for ($count = 1; $count <= $total_pages; $count++) {
            $variantEndPoint = 'https://api.bigcommerce.com/stores/' . $data['store_hash'] . '/v3/catalog/products/' . $product['id'] . '/variants?limit=250&page=' . $count;
            $response = $this->curlRequest->enSingleCurlRequest($variantEndPoint, [], $headers, 'GET', true);
            $response = json_decode($response['response'], true);
            
            if(Functions::isEnabledLogs($data['store_hash'])){
                Log::info('Get veriants from BC using sync process' . json_encode($response));
            }

            if (isset($response['data']) && count($response['data'])) {
                foreach ($response['data'] as $variant) {
                    $product['price'] = $variant['price'] ?? $product['price'];
                    $product['weight'] = $variant['weight'] ?? $product['weight'];
                    $product['depth'] = $variant['depth'] ?? $product['depth'];
                    $product['width'] = $variant['width'] ?? $product['width'];
                    $product['height'] = $variant['height'] ?? $product['height'];
                    $product['sku'] = $variant['sku'] ?? $product['sku'];
                    $product['base_variant_id'] = $variant['id'];

                    !$useTransaction ? $this->saveProducts->saveProductFromSync($product, $data['store_id']) :
                        $this->saveProducts->saveProduct($product, $data['store_id'], $scope);
                }
            }
        }
    }

    /*
     * return number of pages for all products
     */

    public function importProductsGetPages($data)
    {
        $storeUrl = 'https://api.bigcommerce.com/stores/' . $data['store_hash'] . '/v3/catalog/products?limit=' . $data['perpage'];
        $headers[] = 'X-Auth-Token: ' . $data['store_token'];
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $response = $this->curlRequest->enSingleCurlRequest($storeUrl, [], $headers, 'GET', true);
        $response = json_decode($response['response'], true);
        return $response['meta']['pagination']['total_pages'];
    }

    public function getSingleProductFromApi($request, $scope = null)
    {
        $storeId = $request['store_id'] ?? '';
        $storeName = $request['store_name'] ?? '';
        $productId = $request['product_id'] ?? '';
        $storeToken = $this->mainController->getCustAccessTok($storeId);
        $data['store_token'] = $storeToken;
        $data['store_id'] = $storeId;
        $data['store_hash'] = $storeName;
        if (isset($storeToken['status']) && $storeToken['status'] == false) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Token Not Found',
            ], 200);
        }
        $storeUrl = 'https://api.bigcommerce.com/stores/' . $storeName . '/v3/catalog/products/' . $productId;
        $headers[] = 'X-Auth-Client: ' . $this->mainController->getAppClientId();
        $headers[] = 'X-Auth-Token: ' . $storeToken;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $response = $this->curlRequest->enSingleCurlRequest($storeUrl, [], $headers, 'GET', true);
        $response = json_decode($response['response'], true);
        
        if (isset($response['data']) && count($response['data'])) {
            $product = $response['data'];
            if ($product['base_variant_id'] == null) {
                $this->saveProducts->saveProduct($product, $storeId, $scope);
                $this->getVariants($product, $data, $scope);
            } else {
                $this->saveProducts->saveProduct($product, $storeId, $scope);
                $this->saveProducts->deleteNullVariantProduct($product, $storeId);
            }
            return response()->json(['error' => false,
                'data' => [],
                'message' => 'Products Saved Succesfully',
            ], 200);
        }
    }

    public function updateSingleProductFromApi($request)
    {
        $storeId = $request['store_id'] ?? '';
        $storeHash = $request['store_hash'] ?? '';
        $source_product_id = $request['source_product_id'] ?? '';
        $variant_id = (int)$request['variant_id'] ?? 0;
        $storeToken = $this->mainController->getCustAccessTok($storeId);
        if ($variant_id) {
            $storeUrl = 'https://api.bigcommerce.com/stores/' . $storeHash . '/v3/catalog/products/' . $source_product_id . '/variants/' . $variant_id;
        } else {
            $storeUrl = 'https://api.bigcommerce.com/stores/' . $storeHash . '/v3/catalog/products/' . $source_product_id;
        }

        //$headers[] = 'X-Auth-Client: ' . $this->mainController->getAppClientId();
        $headers[] = 'X-Auth-Token: ' . $storeToken;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $data = [
            'weight' => $request['weight'] ?? 0,
            'width' => $request['width'] ?? 0,
            'height' => $request['height'] ?? 0,
            'depth' => $request['length'] ?? 0,
        ];

        $this->curlRequest->enSingleCurlRequest($storeUrl, json_encode($data), $headers, 'PUT', true);
    }


    /*public function getSingleProductDetail(Request $request)
    {
        if (empty($request->product_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Product Id',
            ], 404);
        }
        $products = ProductSetting::where('id', $request->product_id)->get();
        //$products = $this->getBCProductByID($request);
        return response()->json(['error' => false,
            'data' => $products,
            'message' => '',
        ], 200);
    }*/

    public function getBCProductByID($request)
    {
        $store = Store::where('hash', $request['store_hash'])->first();
        if (empty($store)) {
            return [];
        }
        $headers[] = 'X-Auth-Token: ' . $store->access_token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = "https://api.bigcommerce.com/stores/" . $request['store_hash'] . "/v3/catalog/products/" . $request['product_id'];
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);
        $prd = [];
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $product = json_decode($response['response'], true)['data'];
            $prd['weight'] = number_format($product['weight'], 2);
            $prd['width'] = number_format($product['width'], 2);
            $prd['length'] = number_format($product['depth'], 2);
            $prd['height'] = number_format($product['height'], 2);
            $prdSettings = $this->getProductSettings($request, $store->access_token);
            $prd['settings'] = json_encode($prdSettings);
            $prd['id'] = $product['id'];
            //echo "<pre>"; print_r($prd); exit;
        }
        return $prd;
    }

    public function getProductSettings($request, $token)
    {
        $headers[] = 'X-Auth-Token: ' . $token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = "https://api.bigcommerce.com/stores/" . $request['store_hash'] . "/v3/catalog/products/" . $request['product_id'] . "/custom-fields?limit=250";
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);
        $settings = [];
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $customFields = json_decode($response['response'], true);

            foreach ($customFields['data'] as $field) {
                $boolIndex = ['dropship_enabled', 'hazardous_enabled', 'freight_enabled', 'insurance'];
                $name = strtolower($field['name']);
                $value = strtolower($field['value']);
                if (in_array($name, $boolIndex)) {
                    $settings[$name] = ($value == 'true') ? true : false;
                } else {
                    $settings[$name] = $value;
                }

            }
        }
        return $settings;
    }


    public function getSingleProductDetail(Request $request)
    {
        $this->deleteDuplicateProducts($request);

        if (empty($request->product_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Product Id',
            ], 404);
        }
        $products = ProductSetting::where('source_product_id', $request->product_id)
            ->whereNotNull('variant_id')
            ->where('store_id', $request->store_id)
            ->get();

        if(!empty($products)){
            foreach($products as $key => $product){
                $nestingItemsDetails = optional(NestingItemsDetail::where('product_settings_id', $product['id'])->first())->toArray() ?? [];
                $products[$key]->dimension_type = $nestingItemsDetails['dimension_type'] ?? 0;
                $products[$key]->nesting_percentage = $nestingItemsDetails['nesting_percentage'] ?? null;
                $products[$key]->stacked_type = $nestingItemsDetails['stacked_type'] ?? 0;
                $products[$key]->max_nested_items = $nestingItemsDetails['max_nested_items'] ?? null;
                $products[$key]->is_nesting_enabled = $nestingItemsDetails['is_nesting_enabled'] ?? 0;
            }
        }
        
        if ($products->isEmpty()) {
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

    public function deleteDuplicateProducts($request)
    {
        if(!(isset($request->store_id) && isset($request->product_id) && !empty($request->store_id) && !empty($request->product_id))){
            return false;
        }

        $duplicateProd = ProductSetting::select('variant_id', 'source_product_id', DB::raw('COUNT(*) as count'))
            ->where(['store_id' => $request->store_id, 'source_product_id' => $request->product_id])
            ->groupBy('variant_id')
            ->having('count', '>', 1)
            ->get();

            if(!count($duplicateProd)){
                return false;
            }

            foreach ($duplicateProd as $product) {
                $count = $product->count ?? 0;
                while($count > 1){
                    ProductSetting::where(['store_id' => $request->store_id, 'variant_id' => $product->variant_id, 'source_product_id' => $product->source_product_id])
                    ->first()
                    ->delete();
                    $count--;
                }
                        
            }

        return true;
    }

    public function getStoreProductsFromDb(Request $request)
    {
        try {
            $page = $request['page'] ?? 1;
            $perPage = $request['perpage'] ?? 50;
            $search = $request['search'] ?? null;
            $sortProd = $request['sortProd'] == "true" ? 'DESC' : 'ASC';

            /*$count = ProductSetting::where('store_id', $request->store_id)
                ->where('name','LIKE','%'.$search.'%')->orderBy('name', $sortProd)->get()->groupBy('source_product_id')->count();*/

            if ($search === null || $search == '') {
                $count = ProductSetting::where('store_id', $request->store_id)
                    ->orderBy('name', $sortProd)->get();
            } else {
                $count = ProductSetting::where('store_id', $request->store_id)
                    ->where(function ($query) use ($search) {
                        $query->where('name', 'LIKE', '%' . $search . '%')
                            ->orWhere('sku', 'LIKE', '%' . $search . '%')
                            ->orWhere('variant_id', $search)
                            ->orWhere('source_product_id', $search);
                    })
                    ->orderBy('name', $sortProd)
                    ->get();
            }

            if ($count->count()) {
                $count = $count->groupBy('source_product_id')->count();
            } else {
                $count = 0;
            }

            if ($search === null || $search == '') {
                $products = ProductSetting::where('store_id', $request->store_id)
                    ->groupBy('source_product_id')->orderBy('name', $sortProd)->skip(($page - 1) * $perPage)->take($perPage)->get();
            } else {
                $products = ProductSetting::where(function ($query) use ($search) {
                    $query->where('name', 'LIKE', '%' . $search . '%')
                        ->orWhere('sku', 'LIKE', '%' . $search . '%')
                        ->orWhere('variant_id', $search)
                        ->orWhere('source_product_id', $search);
                })->where('store_id', $request->store_id)
                    ->orderBy('name', $sortProd)
                    ->groupBy('source_product_id')
                    ->skip(($page - 1) * $perPage)->take($perPage)->get();
            }
            if ($products->isEmpty()) {
                return response()->json(['error' => true,
                    'data' => [],
                    'message' => 'No Products Available',
                ], 200);
            }

            $products = $this->isLtlParcelBothEnabled($products, $request);

            $resp = response()->json(['error' => false,
                'data' => $products,
                'meta' => ['total' => $count, 'current' => $page, 'perpage' => $perPage],
                'message' => '',
            ], 200);
            return $resp;
        } catch (\Exception $exception) {
            Log::info('catch: ' . json_encode($exception->getMessage()));
        }

    }

    public function isLtlParcelBothEnabled($products, $request)
    {
        $brandArray = [];
        
        $uniqueBrandIds = collect($products)->unique('brand_id')->values()->all();
            
            foreach($uniqueBrandIds as $product){
                
                $brandName = $this->productBrand($request, $product) ?? '';
                $brandArray['brand_' . $product['brand_id']] = $brandName;
                
            }

        foreach($products as $key => $product){
            $freightEnabled = $parcelEnabled = false;
            if($product['variant_id'] == null){
                $variants = ProductSetting::where('source_product_id', $product['source_product_id'])
                ->where('store_id', $request->store_id)->get();
                foreach($variants as $variant){
                    if($variant->variant_id != null){
                        if(json_decode($variant['settings'])->freight_enabled){
                            $freightEnabled = json_decode($variant['settings'])->freight_enabled;
                        } elseif (json_decode($variant['settings'])->parcel_enabled){
                            $parcelEnabled = json_decode($variant['settings'])->parcel_enabled;
                        }
                    }
                }

                if ($freightEnabled && $parcelEnabled){
                    $settings = json_decode($product['settings']);
                    $settings->freightParcelEnabled = true;
                    $product['settings'] = json_encode($settings);
                    $products[$key] = $product;
                } elseif ($parcelEnabled){
                    $settings = json_decode($product['settings']);
                    $settings->parcel_enabled = true;
                    $settings->freight_enabled = false;
                    $product['settings'] = json_encode($settings);
                    $products[$key] = $product;
                } elseif ($freightEnabled){
                    $settings = json_decode($product['settings']);
                    $settings->freight_enabled = true;
                    $settings->parcel_enabled = false;
                    $product['settings'] = json_encode($settings);
                    $products[$key] = $product;
                }
            }

            $products[$key]['brand_name'] = $brandArray['brand_' . $product['brand_id']] ?? '';
            $products[$key]['category_name'] = $this->productCategory($request, $product) ?? '';
        }

        return $products;
    }

    public function productBrand($request, $product)
    {
        $store = Store::where('hash', $request['store_hash'])->first();
        if (empty($store)) {
            return [];
        }
        $headers[] = 'X-Auth-Token: ' . $store->access_token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = "https://api.bigcommerce.com/stores/" . $store->hash . "/v3/catalog/brands/" . $product['brand_id'];
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);
        
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $brand = json_decode($response['response'], true);
            return $brand['data']['name'] ?? '';
        }
    }

    public function productCategory($request, $product)
    {
        $categoriesNames = [];
        $categories = json_decode($product['categories_id']) ?? [];
        $store = Store::where('hash', $request['store_hash'])->first();

        if (empty($store)) {
            return [];
        }

        foreach ($categories as $category) {

            if (isset($this->categoryArray['category_' . $category])) {
                
                $categoriesNames[] = $this->categoryArray['category_' . $category];
                continue;
            }

            $headers[] = 'X-Auth-Token: ' . $store->access_token;
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Accept: application/json';
            $endpoint = "https://api.bigcommerce.com/stores/" . $store->hash . "/v3/catalog/categories/" . $category;
            $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);

            $name = null;
            if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
                $response = json_decode($response['response'], true);
                $name = $response['data']['name'] ?? null;
                $categoriesNames[] = $name;
            }
            $this->categoryArray['category_' . $category] = $name;

        }

        return $categoriesNames;
    }

    public function editProduct(Request $request)
    {
        if (empty($request->product_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Product Id',
            ], 404);
        }
        $product = ProductSetting::where('id', $request->product_id)
            ->first();
        if ($product === null) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Product Found Against This Id',
            ], 404);
        }
        return response()->json(['error' => false,
            'data' => $product,
            'message' => 'Product Info',
        ], 200);
    }

    public function updateProductDetail(Request $request)
    {
        $productCount = isset($request->products) ? count($request->products) : null;
        foreach ($request->products as $prd) {
            $prd['store_id'] = $request->store_id;
            $prd['store_hash'] = $request->store_hash;
            $result = $this->updateSingleProductFromApi($prd);
            $product = ProductSetting::where('source_product_id', $prd['source_product_id'])
                ->where('variant_id', $prd['variant_id'])
                ->where('store_id', $request->store_id)->first();
            $product->weight = $prd['weight'];
            $product->length = $prd['length'];
            $product->width = $prd['width'];
            $product->height = $prd['height'];
            $product->name = $prd['name'];
            $product->ship_multiple_package = isset($prd['ship_multiple_package']) && $prd['ship_multiple_package'] ? 1 : 0;
            $product->pallet_vertical_rotation = isset($prd['pallet_vertical_rotation']) && $prd['pallet_vertical_rotation'] ? 1 : 0;
            $product->own_pallet = isset($prd['own_pallet']) && $prd['own_pallet'] ? 1 : 0;
            $product->product_markup = isset($prd['product_markup']) && !empty($prd['product_markup']) ? $prd['product_markup'] : '';
            $product->nmfc = isset($prd['nmfc']) && !empty($prd['nmfc']) ? $prd['nmfc'] : '';
            if (isset($prd['dropship_enabled']) && $prd['dropship_enabled']) {
                $product->dropship_enabled = true;
                $product->dropship_location = $prd['dropship_location'] ?? null;
            } else {
                $product->dropship_enabled = false;
                $product->dropship_location = null;
            }

            if (isset($prd['shipping_group_enabled']) && $prd['shipping_group_enabled']) {
                $product->shipping_group_enabled = true;
                $product->shipping_group = $prd['shipping_group'] ?? null;
            } else {
                $product->shipping_group_enabled = false;
                $product->shipping_group = null;
            }

            if (isset($prd['shipping_class_enabled']) && $prd['shipping_class_enabled']) {
                $product->shipping_class_enabled = true;
                $product->shipping_class = $prd['shipping_class'] ?? null;
            } else {
                $product->shipping_class_enabled = false;
                $product->shipping_class = null;
            }

            $product->settings = json_encode($this->getSetting($prd));
            $product->update();
            // updating Nesting Items details
            $nestingItemsDetails = $this->updateNestingItemsDetail($prd, $request['store_id']);
            if(!empty($nestingItemsDetails)){
                $product->nested_diamensions = $prd['nested_diamensions'] ?? 0;
                $product->nesting_percentage = $prd['nesting_percentage'] ?? null;
                $product->stacking_property = $prd['stacking_property'] ?? 0;
                $product->max_nested_items = $prd['max_nested_items'] ?? null;
                $product->is_nesting_enabled = $prd['is_nesting_enabled'] ?? 0;
            }

            $prd['store_id'] = $request['store_id'];
            $prd['store_hash'] = $request['store_hash'];
        }

        if($productCount > 1){
            $products = $this->isLtlParcelBothEnabled($request->products, $request);
            foreach($products as $prod){
                if($prod['variant_id'] == null){
                    $product = $prod;
                }
            }
        } else { 
            $products = $this->isLtlParcelBothEnabled($request->products, $request);
            $product = $products[0] ?? [];
        }

        return response()->json(['error' => false,
            'data' => $product,
            'message' => 'Product Updated Successfully',
        ], 200);
    }

    public function updateNestingItemsDetail($product, $storeId)
    {
        $nestingItemsDetails = NestingItemsDetail::firstOrNew(['product_settings_id' => $product['id'], 'store_id' => $storeId]);
        $nestingItemsDetails->dimension_type = $product['dimension_type'] ?? 0;
        $nestingItemsDetails->nesting_percentage = $product['nesting_percentage'] ?? null;
        $nestingItemsDetails->stacked_type = $product['stacked_type'] ?? 0;
        $nestingItemsDetails->max_nested_items = $product['max_nested_items'] ?? null;
        $nestingItemsDetails->is_nesting_enabled = $product['is_nesting_enabled'] ? 1 : 0;
        $nestingItemsDetails->save();
        
        return $product;
    }

    public function getSetting($product)
    {
        $getOnly = ['freight_class', 'freightParcelEnabled',
            'hazardous_enabled', 'freight_enabled', 'parcel_enabled', 'quote_as_instore', 'quote_as_local', 'insurance', 'allow_vertical', 'ship_own_package', 'nmfc', 'hs_code'];
        $settings = new \stdClass();
        foreach ($product as $key => $prd) {
            if (in_array($key, $getOnly)) {
                $settings->$key = $prd;
            }
        }
        return $settings;
    }


    public function getAllProducts(Request $request)
    {
        $store = Store::where('hash', $request['store_hash'])->first();
        if (empty($store)) {
            return [];
        }
        $headers[] = 'X-Auth-Token: ' . $store->access_token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = "https://api.bigcommerce.com/stores/" . $request['store_hash'] . "/v3/catalog/products";
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $allProducts = json_decode($response['response'], true);
            //$allowed = ['id', 'sku', 'name', 'price'];
            $filteredPrds = [];
            $productsMeta = $allProducts['meta'];
            foreach ($allProducts['data'] as $key => $product) {
                $filteredPrds[$key]['id'] = $product['id'];
                $filteredPrds[$key]['name'] = $product['name'];
                $filteredPrds[$key]['sku'] = $product['sku'];
                $filteredPrds[$key]['price'] = $product['price'];
                //$filteredPrds[$key]['image_src'] = $this->getProductImageByID($product['id'], $request, $store->access_token );
            };
        }
        return response()->json(
            [
                'error' => false,
                'data' => $filteredPrds,
                'meta' => $productsMeta,
                'message' => '',
            ]
        );
    }

    
    public function deleteDuplicateVariants(Request $request)
    {
        ini_set('memory_limit', '-1');
        ini_set('max_execution_time', '0');
        set_time_limit(0);
        Log::info('Delete duplicates variants from DB request');
        if(!(isset($request->store_id) && isset($request->deleteit) && $request->deleteit == 'true')){
            return response()->json(['error' => false,
                'data' => [],
                'message' => 'Request not acceptable.',
            ], 200);
        }


        $limit = 5;
        $hasMoreDuplicates = true;
        do {

            $duplicateVar = ProductSetting::select('variant_id', 'source_product_id', DB::raw('COUNT(*) as count'))
                ->where('store_id', $request->store_id)
                ->whereNotNull('variant_id')
                ->groupBy('variant_id', 'store_id')
                ->having('count', '>', 1)
                ->limit($limit)
                ->get();

            if($duplicateVar->isEmpty()){
                $hasMoreDuplicates=false;
               
            }else{
                foreach ($duplicateVar as $duplicate) {
                    $count = $duplicate->count ?? 0;

                    while($count > 1){
                        $product = ProductSetting::where(['store_id' => $request->store_id, 'variant_id' => $duplicate->variant_id])
                        ->first();
                        if($product != null){
                            $product->delete();
                        }
                        $count--;
                    }

                    // $store = Store::getStoreDetailsFromStoreId($request->store_id);
                    // $storeUrl = 'https://api.bigcommerce.com/stores/' . $store['hash'] . '/v3/catalog/products/' . $duplicate->source_product_id . '/variants/' . $duplicate->variant_id;
                    // $storeToken = $this->mainController->getCustAccessTok($store['id']);
                    // $headers[] = 'X-Auth-Token: ' . $storeToken;
                    // $headers[] = 'Content-Type: application/json';
                    // $headers[] = 'Accept: application/json';

                    // $response  = $this->curlRequest->enSingleCurlRequest($storeUrl, [], $headers, 'GET', true);

                    // if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
                    //     $bcProduct = json_decode($response['response'], true)['data'];

                    //     $product = ProductSetting::where(['store_id' => $store['id'], 'source_product_id' => $duplicate->source_product_id, 'variant_id' => $duplicate->variant_id])
                    //     ->update([
                    //         // Update attributes
                    //         'weight' => $bcProduct['weight'] ?? 0,
                    //         'length' => $bcProduct['depth'] ?? 0, 
                    //         'width' => $bcProduct['width'] ?? 0,
                    //         'height' => $bcProduct['height'] ?? 0,
                    //     ]);
                    // }

                }
            }



        } while($hasMoreDuplicates);


        Log::info('Delete duplicates variants from DB Completed');
        return response()->json(['error' => false,
            'data' => [],
            'message' => 'Duplicated Variants deleted Successfully.',
        ], 200);
    }

    public function deleteNullVariants(Request $request)
    {
        Log::info('Delete Null variants from DB request');
        if(!(isset($request->store_id) && isset($request->deleteit) && $request->deleteit == 'true')){
            return response()->json(['error' => false,
                'data' => [],
                'message' => 'Request not acceptable.',
            ], 200);    
        }

        $nullVariants = ProductSetting::select('variant_id', 'source_product_id', DB::raw('COUNT(*) as count'))
            ->where('store_id', $request->store_id)
            ->whereNull('variant_id')
            ->groupBy('source_product_id', 'store_id')
            ->having('count', '>', 1)
            ->get();

            if(!count($nullVariants)){
                $message = 'No Null Variants Found.';
                return response()->json(['error' => false,
                    'data' => [],
                    'message' => $message,
                ], 200);    
            }

            foreach ($nullVariants as $duplicate) {
                $count = $duplicate->count ?? 0;

                while($count > 1){
                    $result = ProductSetting::where(['store_id' => $request->store_id, 'source_product_id' => $duplicate->source_product_id])
                    ->whereNull('variant_id')
                    ->first()
                    ->delete();
                    $count--;
                }       
            }
        Log::info('Delete Null variants from DB Completed');
        return response()->json(['error' => false,
            'data' => [],
            'message' => 'Null Variants deleted Successfully.',
        ], 200);

    }

    public function getProductImageByID($id, $request, $token)
    {
        $imageEndPoint = 'https://api.bigcommerce.com/stores/' . $request['store_hash'] . '/v3/catalog/products/' . $id . '/images';
        $headers[] = 'X-Auth-Token: ' . $token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $image_src = '';
        $image = $this->curlRequest->enSingleCurlRequest($imageEndPoint, [], $headers, 'GET', true);
        if (isset($image['status']) && $image['status'] == true) {
            $image = json_decode($image['response'], true);
            if (isset($image['data']) && count($image['data'])) {
                $image_src = $image['data'][0]['url_tiny'] ?? '';
            }
        }
        return $image_src;
    }


    /*
     * webhook
     * Update product when option/variants/sku creates/updated
     * */

    public function skuFromWebhook(Request $request)
    {
        try {

            $postData = file_get_contents("php://input");
            $postData = json_decode($postData, true);
            return $this->skuWebhookProcess($postData);

        } catch (\Exception $exception) {
            //  Have to LOg Here
            Log::info('Sku Webhook Exception ' . json_encode([$exception->getMessage(), $exception->getLine()]));
        }
    }


    public function skuWebhookProcess($postData)
    {
        try {
            $storeHash = explode('/', $postData['producer']);
            $storeHash = $storeHash[1];
            $productId = $postData['data']['sku']['product_id'];
            $variant_id = $postData['data']['sku']['variant_id'];
            // Update,delete,create from  webhook
            $scope = $postData['scope'];
            $store = Store::where('hash', $storeHash)->first();
            //allow only create/update orders actions
            if ($scope == "store/sku/deleted") {
                ProductSetting::where('source_product_id', $productId)->where('variant_id', $variant_id)->where('store_id', $store->id)->delete();
                return response()->json(true, 200);
            }
            $onlyScopes = ['store/sku/created', 'store/sku/updated'];
            if (empty($store) || !in_array($scope, $onlyScopes)) {
                return response()->json(true, 200);
            }

            // webhook call return back due to store plan expired
            $GetRatesController = new GetRatesController();
            if (!$GetRatesController->storePlanStatus($store->id)) {
                return response()->json(true, 200);
            }

            /*
             * get variant details from bigcommerce
             * update details and save product into db
             * */
            $storeToken = $this->mainController->getCustAccessTok($store->id);
            $storeUrl = 'https://api.bigcommerce.com/stores/' . $storeHash . '/v3/catalog/products/' . $productId . '/variants' . '/' . $variant_id;
            $headers[] = 'X-Auth-Client: ' . $this->mainController->getAppClientId();
            $headers[] = 'X-Auth-Token: ' . $storeToken;
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Accept: application/json';
            $response = $this->curlRequest->enSingleCurlRequest($storeUrl, [], $headers, 'GET', true);
            $response = json_decode($response['response'], true);

            if (isset($response['data'])) {
                $variant = $response['data'];
                $product['price'] = $variant['price'];
                $product['weight'] = $variant['weight'];
                $product['depth'] = $variant['depth'];
                $product['width'] = $variant['width'];
                $product['height'] = $variant['height'];
                $product['sku'] = $variant['sku'];
                $product['base_variant_id'] = $variant['id'];
                $product['id'] = $variant['product_id'];
                $this->saveProducts->setVariantNullProduct($product, $store->id);
                $this->saveProducts->saveProduct($product, $store->id);
            }
            return response()->json(true, 200);
        } catch (\Exception $exception) {
            Log::info('Sku Webhook Exception ' . json_encode([$exception->getMessage(), $exception->getLine()]));
            return response()->json(true, 200);
        }

    }

//
    /* public function getAllProducts(Request $request)
     {
         $this->getProductsFromBC();
     }

     public function getProductsFromBC()
     {
     $client = new Client();
     $mainController = new MainController();
     //https://api.bigcommerce.com/stores/{$$.env.store_hash}/v3/catalog/products

     $ch = curl_init();
     curl_setopt($ch, CURLOPT_URL, "https://api.bigcommerce.com/stores/uann2u/v3/catalog/products");
     //curl_setopt($ch, CURLOPT_GET, 1);
     //curl_setopt($ch, CURLOPT_POSTFIELDS);
     curl_setopt($ch, CURLOPT_HTTPHEADER, [
     'X-Auth-Token' => 'fo9l40uqj8najktk0t01klrl7dioiuc',
     'X-Auth-Client' => $mainController->getAppClientId()
     ]);
     curl_setopt($ch, CURLOPT_HEADER, true);
     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
     $output = curl_exec($ch);
     $curlInfo = curl_getinfo($ch);
     curl_close($ch);

     echo '<pre>';
     print_r($output);
     print_r(json_decode($output));
     echo '</pre>';
     exit();

     $result = $client->request('GET', 'https://api.bigcommerce.com/stores/uann2u/v3/catalog/products', [
     'headers' => [
     'X-Auth-Token' => 'fo9l40uqj8najktk0t01klrl7dioiuc'
     ],
     'on_stats' => function (TransferStats $stats) {
     // You must check if a response was received before using the
     // response object.
     if ($stats->hasResponse()) {
     echo $stats->getResponse()->getStatusCode();
     dd($stats->getResponse());
     } else {
     // Error data is handler specific. You will need to know what
     // type of error data your handler uses before using this
     // value.
     var_dump($stats->getHandlerErrorData());
     }
     }
     ]);
     /*$store = DB::table('access_tokens')->get()->toArray();
     $storeHash = explode('/', $store[0]->store_hash);
     Bigcommerce::configure(array(
     'client_id' => env('BC_APP_CLIENT_ID'),
     'auth_token' => $store[0]->access_token,
     'store_hash' => $storeHash[1]
     ));
     $products = Bigcommerce::getProducts();
     //Debug point added by @Azeem
     echo '$products';
     echo '<pre>';
     print_r($products);
     echo '</pre>';
     exit();*/
// }*/
}
