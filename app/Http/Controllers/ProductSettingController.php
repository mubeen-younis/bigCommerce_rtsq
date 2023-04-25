<?php

namespace App\Http\Controllers;

use App\CurlRequest;
use App\Jobs\ImportProductsFromBCStore;
use App\Jobs\ImportProductsFromBCStoreStatusUpdate;
use App\Models\ProductSetting;
use App\Models\ImportProducts as ImportProductsModel;
use Illuminate\Http\Request;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class ProductSettingController extends Controller
{
    public $curlRequest;
    public $mainController;
    public $saveProducts;

    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
        $this->mainController = new MainController();
        $this->saveProducts = new ProductSetting();
    }

    public function importProducts(Request $request)
    {
        set_time_limit(0);
        $isSyncinProgress = ImportProductsModel::where('store_id', $request['store_id'])->where('status', '=', 1)->where('created_at', '>', Carbon::now()->subDay(1)->toDateTimeString())->exists();
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
            $delay = 0;

            for ($page = 0; $page <= $totalpages; $page++) {
                $data['page'] = $page;
                ImportProductsFromBCStore::dispatch($data)->delay(Carbon::now()->addSecond(($delay++) * 20));
            }
            ImportProductsFromBCStoreStatusUpdate::dispatch($insertedId, $request['email'])->delay(Carbon::now()->addSecond(($delay++) * 20));
            \Artisan::call('queue:work');

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
        if (isset($response['data']) && count($response['data'])) {
            foreach ($response['data'] as $product) {

                /*
                 * $product['base_variant_id'] = null mean this has variants and iterate those
                 * otherwise base product is as a variant product
                 * */
                if ($product['base_variant_id'] == null) {
                    $this->saveProducts->saveProduct($product, $data['store_id']);
                    $this->getVariants($product, $data);
                } else {
                    $this->saveProducts->saveProduct($product, $data['store_id']);
                }

                //$this->saveProducts->saveProduct($product, $data['store_id']);
            }
        }
    }

    public function getVariants($product, $data)
    {
        $metaEndPoint = 'https://api.bigcommerce.com/stores/' . $data['store_hash'] . '/v3/catalog/products/' . $product['id'] . '/variants?limit=250';
        unset($headers);
        $headers[] = 'X-Auth-Token: ' . $data['store_token'];
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $metaResponse = $this->curlRequest->enSingleCurlRequest($metaEndPoint, [], $headers, 'GET', true);
        $metaResponse = json_decode($metaResponse['response'], true);
        Log::info('get all product variants-productID:' . $product['id'] . json_encode($metaResponse));
        $total_pages = $metaResponse['meta']['pagination']['total_pages'] ?? null;
        if(blank($total_pages)){
            return null;
        }
        for ($count = 1; $count <= $total_pages; $count++) {
            $variantEndPoint = 'https://api.bigcommerce.com/stores/' . $data['store_hash'] . '/v3/catalog/products/' . $product['id'] . '/variants?limit=250&page=' . $count;
            $response = $this->curlRequest->enSingleCurlRequest($variantEndPoint, [], $headers, 'GET', true);
            $response = json_decode($response['response'], true);
            if (isset($response['data']) && count($response['data'])) {
                foreach ($response['data'] as $variant) {
                    $product['price'] = $variant['price'];
                    $product['weight'] = $variant['weight'];
                    $product['depth'] = $variant['depth'];
                    $product['width'] = $variant['width'];
                    $product['height'] = $variant['height'];
                    $product['sku'] = $variant['sku'];
                    $product['base_variant_id'] = $variant['id'];
                    $this->saveProducts->saveProduct($product, $data['store_id']);
                }
            }
        }
    }

    /*
     * return number of pages for all products
     */

    public function importProductsGetPages($data)
    {
        $storeUrl = 'https://api.bigcommerce.com/stores/' . $data['store_hash'] . '/v3/catalog/products?limit=' . $data['perpage'] . '&page=0';
        $headers[] = 'X-Auth-Token: ' . $data['store_token'];
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $response = $this->curlRequest->enSingleCurlRequest($storeUrl, [], $headers, 'GET', true);
        $response = json_decode($response['response'], true);
        //dd($response['meta']['pagination']['total_pages']);
        return $response['meta']['pagination']['total_pages'];
    }

    public function getSingleProductFromApi($request)
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
        Log::info('product settings from webhok 12' . json_encode($response));
        if (isset($response['data']) && count($response['data'])) {
            $product = $response['data'];
            if ($product['base_variant_id'] == null) {
                $this->saveProducts->saveProduct($product, $storeId);
                $this->getVariants($product, $data);
            } else {
                $this->saveProducts->saveProduct($product, $storeId);
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
            'weight' => $request['weight'] ?? '',
            'width' => $request['width'] ?? '',
            'height' => $request['height'] ?? '',
            'depth' => $request['length'] ?? '',
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
        if (empty($request->product_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Product Id',
            ], 404);
        }
        $products = ProductSetting::where('source_product_id', $request->product_id)
            ->where('store_id', $request->store_id)
            ->get();
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
                $count = ProductSetting::where('name', 'LIKE', '%' . $search . '%')
                    ->where('store_id', $request->store_id)->orderBy('name', $sortProd)
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
                    $query->where('name', 'LIKE', '%' . $search . '%');
                    $query->orWhere('variant_id', $search);
                    $query->orWhere('source_product_id', $search);
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

        foreach ($request->products as $prd) {
            $product = ProductSetting::where('source_product_id', $prd['source_product_id'])
                ->where('variant_id', $prd['variant_id'])
                ->where('store_id', $request->store_id)->first();
            $product->weight = $prd['weight'];
            $product->length = $prd['length'];
            $product->width = $prd['width'];
            $product->height = $prd['height'];
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
            /*json_encode($prd->only(['dropship_enabled', 'dropship_location', 'freight_class',
                'hazardous_enabled', 'freight_enabled', 'parcel_enabled', 'insurance']));*/
            $product->update();
            $prd['store_id'] = $request['store_id'];
            $prd['store_hash'] = $request['store_hash'];
            $this->updateSingleProductFromApi($prd);
        }
        return response()->json(['error' => false,
            'data' => [],
            'message' => 'Product Updated Successfully',
        ], 200);
    }

    public function getSetting($product)
    {
        $getOnly = ['freight_class',
            'hazardous_enabled', 'freight_enabled', 'parcel_enabled', 'quote_as_instore', 'quote_as_local', 'insurance', 'allow_vertical', 'ship_own_package', 'nmfc'];
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
            Log::info('Webhook sku data: ' . $postData);
            $postData = json_decode($postData, true);
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
                return response()->json(true);
            }
            $onlyScopes = ['store/sku/created', 'store/sku/updated'];
            if (empty($store) || !in_array($scope, $onlyScopes)) {
                return response()->json(true);
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
            Log::info('Get variant details-' . $store->id . json_encode($response));

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
                $this->saveProducts->saveProduct($product, $store->id);
            }
            
            return response()->json(true);
        } catch (\Exception $exception) {
            //  Have to LOg Here
            Log::info('Sku Webhook Exception '.json_encode([$exception->getMessage(), $exception->getLine()]));
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
