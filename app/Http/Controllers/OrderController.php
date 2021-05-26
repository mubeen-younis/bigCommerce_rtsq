<?php

namespace App\Http\Controllers;

use App\Models\Orders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Store;
use App\CurlRequest;

use GuzzleHttp\Exception\RequestException;

class OrderController extends Controller
{
    public $accessToken;
    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
    }
    public function orderFromWebhook(Request $request){
        try {
            $postData = file_get_contents("php://input");
            Log::info('Orderdata: '. $postData);
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

    public function saveUpdateOrderByID($toRequest){
        $order = Orders::where('order_id', $toRequest['order_id'])
            ->where('store_id',$toRequest['store_id'])
            ->first();
        if (empty($order)){
            $order = new Orders();
        }
        $order->store_id = $toRequest['store_id'];
        $order->order_id = $toRequest['order_id'];
        $order->settings = json_encode(['test'=>'testing settings']);
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
