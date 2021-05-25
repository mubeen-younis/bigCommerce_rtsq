<?php

namespace App\Http\Controllers;

use App\Models\Orders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Store;

use GuzzleHttp\Exception\RequestException;

class OrderController extends Controller
{
    public function __construct()
    {

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
            $toRequest['store_name'] = $storeHash;
            $toRequest['order_id'] = $orderId;

            $this->getOrderByID($toRequest);
        } catch (\Exception $exception) {
            //  Have to LOg Here
        }
    }

    public function getOrderByID($toRequest){
        Log::info('toRequest: '. json_encode($toRequest));

        $order = Orders::where('order_id', $toRequest['order_id'])
            ->where('store_id',$toRequest['store_id'])
            ->first();
        if (empty($order)){
            $order = new Orders();
        }
        $order->store_id = $toRequest['store_id'];
        $order->order_id = $toRequest['order_id'];
        $order->settings = json_encode(['setting'=>'settings here']);
        $order->save();
    }
}
