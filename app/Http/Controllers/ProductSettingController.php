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
        //dd($request->all());
        if(!ImportProductsModel::where('store_id', $request['store_id'])->where('status', '=',1)->exists()) {
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
                if($page<3)
                ImportProductsFromBCStore::dispatch($data)->delay(Carbon::now()->addSecond(($delay++) * 20));
            }
            ImportProductsFromBCStoreStatusUpdate::dispatch($insertedId)->delay(Carbon::now()->addSecond(($delay++) * 20));
            \Artisan::call('queue:work');
        }
        return response()->json(['error' => false,
            'message' => 'Synchronize request is in progress.',
        ], 200);
    }
    public function importProductsJob($data){
        $storeUrl = 'https://api.bigcommerce.com/stores/' . $data['store_hash'] . '/v3/catalog/products?limit='.$data['perpage'].'&page='.$data['page'];
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
                $this->saveProducts->saveProduct($product, $data['store_id']);
            }
        }
    }

    /*
     * return number of pages for all products
     */

    public function importProductsGetPages($data){
        $storeUrl = 'https://api.bigcommerce.com/stores/' . $data['store_hash'] . '/v3/catalog/products?limit='.$data['perpage'].'&page=0';
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
            $this->saveProducts->saveProduct($response['data'], $storeId);
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
        $storeToken = $this->mainController->getCustAccessTok($storeId);
        $storeUrl = 'https://api.bigcommerce.com/stores/' . $storeHash . '/v3/catalog/products/' . $source_product_id;
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

    public function getBCProductByID($request){
        $store = Store::where('hash', $request['store_hash'])->first();
        if(empty($store)){
            return [];
        }
        $headers[] = 'X-Auth-Token: ' . $store->access_token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = "https://api.bigcommerce.com/stores/".$request['store_hash']."/v3/catalog/products/".$request['product_id'];
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);
        $prd = [];
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $product = json_decode($response['response'], true)['data'];
            $prd['weight'] = number_format($product['weight'], 2);
            $prd['width'] = number_format($product['width'], 2);
            $prd['length'] = number_format($product['depth'], 2);
            $prd['height'] = number_format($product['height'], 2);
            $prdSettings = $this->getProductSettings($request , $store->access_token);
            $prd['settings'] = json_encode($prdSettings);
            $prd['id'] = $product['id'];
            //echo "<pre>"; print_r($prd); exit;
        }
        return $prd;
    }

    public function getProductSettings($request, $token){
        $headers[] = 'X-Auth-Token: ' . $token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = "https://api.bigcommerce.com/stores/".$request['store_hash']."/v3/catalog/products/".$request['product_id']."/custom-fields?limit=250";
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);
        $settings = [];
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $customFields = json_decode($response['response'], true);

            foreach($customFields['data'] as $field){
                $boolIndex = ['dropship_enabled', 'hazardous_enabled', 'freight_enabled', 'insurance'];
                $name = strtolower($field['name']);
                $value = strtolower($field['value']);
                if(in_array( $name, $boolIndex)){
                    $settings[$name] = ($value == 'true') ? true : false;
                }else{
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
        $products = ProductSetting::where('id', $request->product_id)
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
        $page = $request['page'] ?? 1;
        $perPage = $request['perpage'] ?? 50;
        $count = ProductSetting::where('store_id', $request->store_id)
            ->groupBy('source_product_id')->get()->count();
        $products = ProductSetting::where('store_id', $request->store_id)
            ->groupBy('source_product_id')->skip(($page-1)*$perPage)->take($perPage)->get();
        if ($products->isEmpty()) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Products Available',
            ], 200);
        }
        return response()->json(['error' => false,
            'data' => $products,
            'meta' => ['total'=>$count, 'current' => $page, 'perpage'=>$perPage],
            'message' => '',
        ], 200);
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
        if (!$request->product_id || empty($request->product_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Product Id',
            ], 404);
        }

        $product = ProductSetting::find($request->product_id);

        if ($product === null) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Product Found Against This Id',
            ], 404);
        }

        $product->weight = $request->weight;
        $product->length = $request->length;
        $product->width = $request->width;
        $product->height = $request->height;
        $product->settings = json_encode($request->only(['dropship_enabled', 'dropship_location', 'freight_class',
            'hazardous_enabled', 'freight_enabled', 'insurance']));
        $product->update();
        $this->updateSingleProductFromApi($request);
        return response()->json(['error' => false,
            'data' => ProductSetting::find($request->product_id),
            'message' => 'Product Updated Successfully',
        ], 200);
    }


    public function getAllProducts(Request $request){
        $store = Store::where('hash', $request['store_hash'])->first();
        if(empty($store)){
            return [];
        }
        $headers[] = 'X-Auth-Token: ' . $store->access_token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = "https://api.bigcommerce.com/stores/".$request['store_hash']."/v3/catalog/products";
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $allProducts = json_decode($response['response'], true);
            //$allowed = ['id', 'sku', 'name', 'price'];
            $filteredPrds = [];
            $productsMeta = $allProducts['meta'];
            foreach ($allProducts['data'] as $key=>$product){
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

    public function getProductImageByID($id, $request, $token){
        $imageEndPoint = 'https://api.bigcommerce.com/stores/' .$request['store_hash']. '/v3/catalog/products/'.$id.'/images';
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
