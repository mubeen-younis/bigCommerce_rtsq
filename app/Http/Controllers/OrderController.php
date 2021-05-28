<?php

namespace App\Http\Controllers;

use App\CurlRequest;
use App\Models\Orders;
use App\Models\RequestData;
use App\Models\RequestTempData;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public $accessToken;
    public $storeHash;

    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     * get Orders from Bigcommerce
     */
    public function index()
    {
        return response()->json(
            [
                'data' => Orders::all(),
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
    public function edit(Orders $order, Request $request)
    {
        if (empty($request->order_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Order Id',
            ], 404);
        }

        $order = Orders::where('id', $request->order_id)
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
    public function update(Request $request, Orders $order)
    {
        if (!$request->order_id || empty($request->order_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Product Id',
            ], 404);
        }

        $order = Orders::find($request->order_id);

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
            'data' => Orders::find($request->order_id),
            'message' => 'Order Updated Successfully',
        ], 200);

    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Order  $order
     * @return \Illuminate\Http\Response
     */
    public function destroy(Orders $order)
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
            $this->storeHash = $storeHash;
            $this->moveQuotesTempToReq($toRequest);
            //$saveOrderId = $this->saveUpdateOrderByID($toRequest);
            //$this->setOrderMeta($toRequest);
        } catch (\Exception $exception) {
            //  Have to LOg Here
        }
    }

    public function setOrderMeta($toRequest, $orderMetaFields, $updateWidgetId)
    {
        $headers[] = 'X-Auth-Token: ' . $this->accessToken;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';

        $endpoint = 'https://api.bigcommerce.com/stores/' . $toRequest['store_hash'] . '/v3/orders/' . $toRequest['order_id'] . '/metafields';
        $method = "POST";
        if ($updateWidgetId) { //if order widget already created
            $endpoint = 'https://api.bigcommerce.com/stores/' . $toRequest['store_hash'] . '/v3/orders/' . $toRequest['order_id'] . '/metafields/' . $updateWidgetId;
            $method = "PUT";
        }

        $request = [
            'permission_set' => 'app_only',
            'key' => 'settings',
            'value' => json_encode($orderMetaFields),
            'resource_id' => $toRequest['order_id'],
            "namespace" => "str",
        ];
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, json_encode($request), $headers, $method, false);
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            return json_decode($response['response'])->data->id;
        }
    }

    public function saveUpdateOrderByID($toRequest)
    {

        $order = Orders::where('order_id', $toRequest['order_id'])
            ->where('store_id', $toRequest['store_id'])
            ->first();
        if (empty($order)) {
            $order = new Orders();
            $updateWidget = 0;
        } else {
            $updateWidget = $order->widgetid;
        }
        $order->store_id = $toRequest['store_id'];
        $order->order_id = $toRequest['order_id'];
        $orderSettings = $this->orderSettings($toRequest);
        $order->widgetid = $this->setOrderMeta($toRequest, $orderSettings, $updateWidget) ?? 0;
        $order->settings = json_encode($orderSettings);
        $order->save();
    }

    public function orderSettings($toRequest)
    {
        $order = json_decode($this->getBCOrderByID($toRequest), true);
        return $this->getBCOrderProducts($order['products']['url']);
    }

    public function getBCOrderProducts($productsUrl)
    {
        $headers[] = 'X-Auth-Token: ' . $this->accessToken;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = $productsUrl;
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);

        $prds = [];
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $products = json_decode($response['response']);
            echo "<pre>";
            print_r($products);exit;
            foreach ($products as $key => $product) {
                $prds[$key]['product_id'] = $product->product_id;
                $prds[$key]['weight'] = $product->weight ?? 0;
                $prds[$key]['width'] = $product->width ?? 0;
                $prds[$key]['height'] = $product->height ?? 0;
                $prds[$key]['depth'] = $product->depth ?? 0;
                $prds[$key]['color'] = $product->product_options[0]->display_value_customer ?? '';
                $prdCustomFields = $this->prdCustomFeilds($product->product_id);
                $prds[$key]['dropship_location'] = $prdCustomFields['dropship_location'] ?? false;
                $prds[$key]['freight_class'] = $prdCustomFields['freight_class'] ?? false;
                $prds[$key]['dropship_enabled'] = isset($prdCustomFields['dropship_enabled']) && $prdCustomFields['dropship_enabled'] == "true" ? true : false;
                $prds[$key]['hazardous_enabled'] = isset($prdCustomFields['hazardous_enabled']) && $prdCustomFields['hazardous_enabled'] == "true" ? true : false;
                $prds[$key]['freight_enabled'] = isset($prdCustomFields['freight_enabled']) && $prdCustomFields['freight_enabled'] == "true" ? true : false;
                $prds[$key]['insurance'] = isset($prdCustomFields['insurance']) && $prdCustomFields['insurance'] == "true" ? true : false;
            }
            //dd($prds);
        }
        return $prds;
    }

    public function prdCustomFeilds($productdId)
    {
        $headers[] = 'X-Auth-Token: ' . $this->accessToken;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = 'https://api.bigcommerce.com/stores/' . $this->storeHash . '/v3/catalog/products/' . $productdId . '/custom-fields';
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);
        $prdCustomFieldData = [];
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $prdCustomFields = json_decode($response['response'])->data;
            if (!empty($prdCustomFields)) {
                foreach ($prdCustomFields as $prdCustomField) {
                    $prdCustomFieldData[$prdCustomField->name] = $prdCustomField->value;
                }
            }
        }
        return $prdCustomFieldData;
    }

    public function getBCOrderByID($toRequest)
    {
        $headers[] = 'X-Auth-Token: ' . $this->accessToken;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = 'https://api.bigcommerce.com/stores/' . $toRequest['store_hash'] . '/v2/orders/' . $toRequest['order_id'];
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', true);
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            return $response['response'];
        }
    }

    public function moveQuotesTempToReq($toRequest){
        $headers[] = 'X-Auth-Token: ' . $this->accessToken;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = 'https://api.bigcommerce.com/stores/' . $toRequest['store_hash'] . '/v2/orders/' . $toRequest['order_id'];
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', true);
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $cartId = json_decode($response['response'])->cart_id;
            $endpoint = json_decode($response['response'])->shipping_addresses->url;
            $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', true);
            if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
                $endpoint = json_decode($response['response'])[0]->shipping_quotes->url;
                $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', true);
                if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
                    $rateId = json_decode($response['response'])->rate_id;
                    //$rateId = "RLCA1622184139"; $cartId = "2484e9e2-ed65-4115-befb-f79b05b0b988";
                    $requestData = RequestTempData::where('rate_id', $rateId)->where('cart_id', $cartId)->get()->toArray();
                    foreach ($requestData as $data)
                    {
                        RequestData::insert($data);
                    }
                    RequestTempData::where('cart_id', $cartId)->delete();
                }
            }
        }
    }
}
