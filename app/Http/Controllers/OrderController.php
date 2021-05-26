<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Orders;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
<<<<<<< HEAD
=======
use App\Models\Store;
use App\CurlRequest;

use GuzzleHttp\Exception\RequestException;
>>>>>>> 9c8399510f74d56d14391e4ffd0a1d93d6618216

class OrderController extends Controller
{
    public $accessToken;
    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return response()->json(
            [
                'data' => Order::all(),
                'error' => false,
            ]
        );
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Order  $order
     * @return \Illuminate\Http\Response
     */
    public function show(Order $order)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Order  $order
     * @return \Illuminate\Http\Response
     */
    public function edit(Order $order, Request $request)
    {
        if (empty($request->order_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Order Id',
            ], 404);
        }

        $order = Order::where('id', $request->order_id)
            ->first();

        if ($order === null) {
            return response()->json(
                ['error' => true,
                    'data' => [],
                    'message' => 'No Order Found Against This Id',
                ], 404);
        }

        return response()->json(
            ['error' => false,
                'data' => $order,
                'message' => 'Product Info',
            ], 200);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Order  $order
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Order $order)
    {
        if (!$request->order_id || empty($request->order_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Product Id',
            ], 404);
        }

        $order = Order::find($request->order_id);

        if ($order === null) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Product Found Against This Id',
            ], 404);
        }

        $order->settings = json_encode($request->only(['date', 'price']));
        $order->update();

        $this->updateSingleProductFromApi($request);

        return response()->json(['error' => false,
            'data' => Order::find($request->order_id),
            'message' => 'Order Updated Successfully',
        ], 200);

    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Order  $order
     * @return \Illuminate\Http\Response
     */
    public function destroy(Order $order)
    {
        //
    }

    public function orderFromWebhook(Request $request)
    {
        try {
            $postData = file_get_contents("php://input");
            Log::info('Orderdata: ' . $postData);
            $postData = json_decode($postData, true);
            $storeHash = explode('/', $postData['producer']);
            $storeHash = $storeHash[1];
            $orderId = $postData['data']['id'];
            // Update,delete,create from  webhook
            $scope = $postData['scope'];
            $store = Store::where('hash', $storeHash)->first();
            //allow only create/update orders actions
            $onlyScopes = ['store/order/created', 'store/order/updated'];
            if (empty($store) || !in_array($scope, $onlyScopes)) {
                return null;
            }
            $toRequest['store_id'] = $store->id;
            $toRequest['store_hash'] = $storeHash;
            $toRequest['order_id'] = $orderId;
            $this->accessToken = $store->access_token;
            $saveOrderId = $this->saveUpdateOrderByID($toRequest);
            $this->setOrderMeta($toRequest);
        } catch (\Exception $exception) {
            //  Have to LOg Here
        }
    }

<<<<<<< HEAD
    public function getOrderByID($toRequest)
    {
        Log::info('toRequest: ' . json_encode($toRequest));

=======
    public function saveUpdateOrderByID($toRequest){
>>>>>>> 9c8399510f74d56d14391e4ffd0a1d93d6618216
        $order = Orders::where('order_id', $toRequest['order_id'])
            ->where('store_id', $toRequest['store_id'])
            ->first();
        if (empty($order)) {
            $order = new Orders();
        }
        $order->store_id = $toRequest['store_id'];
        $order->order_id = $toRequest['order_id'];
<<<<<<< HEAD
        $order->settings = json_encode(['setting' => 'settings here']);
=======
        $order->settings = json_encode(['test'=>'testing settings']);
>>>>>>> 9c8399510f74d56d14391e4ffd0a1d93d6618216
        $order->save();
        $this->orderSettings($toRequest);
        //return $order->id;
    }

    function orderSettings($toRequest){
        $order = $this->getBCOrderByID($toRequest);
        $products = $this->getBCOrderProducts();
    }

    public function getBCOrderProducts(){

    }

    public function getBCOrderByID($toRequest){
        $headers[] = 'X-Auth-Token: ' . $this->accessToken;
        $headers[] = 'Content-Type: application/json';
        $endpoint = 'https://api.bigcommerce.com/stores/' . $toRequest['store_hash'] . '/v2/orders/'.$toRequest['order_id'];

        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);
        Log::info('order '. $response);
    }

    function setOrderMeta($toRequest){
        $headers[] = 'X-Auth-Token: ' . $this->accessToken;
        $headers[] = 'Content-Type: application/json';
        $endpoint = 'https://api.bigcommerce.com/stores/' . $toRequest['store_hash'] . '/v3/orders/'.$toRequest['order_id'].'/metafields';
        $request = [
            'permission_set' => 'app_only',
            'key' => 'settings',
            'value' => json_encode($toRequest),
            'resource_id' => $toRequest['order_id'],
            "namespace" => "str"
        ];
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, json_encode($request), $headers, 'POST', false);
    }
}
