<?php

namespace App\Http\Controllers;

use App\CurlRequest;
use App\Models\ProductSetting;
use Illuminate\Http\Request;

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
        $storeId = isset($request->store_id) ? $request->store_id : 1;
        $storeName = isset($request->store_name) ? $request->store_name : 'uann2u';
        $storeHash = isset($request->store_hash) ? $request->store_hash : 'uann2u';
        $storeToken = $this->mainController->getCustAccessTok($storeId);
        if (isset($storeToken['status']) && $storeToken['status'] == false) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Token Not Found',
            ], 200);
        }
        $storeUrl = 'https://api.bigcommerce.com/stores/' . $storeHash . '/v3/catalog/products';
        $headers[] = 'X-Auth-Client: ' . $this->mainController->getAppClientId();
        $headers[] = 'X-Auth-Token: ' . $storeToken;
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
                $this->saveProducts->saveProduct($product, $storeId);
            }
            return response()->json(['error' => false,
                'data' => $response,
                'message' => 'Products Syncronized Succesfully',
            ], 200);
        }

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
        $products = ProductSetting::where('store_id', $request->store_id)
            ->groupBy('source_product_id')->get();
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

        return response()->json(['error' => false,
            'data' => ProductSetting::find($request->product_id),
            'message' => 'Product Updated Successfully',
        ], 200);
    }

//
    /*    public function getAllProducts(Request $request)
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
