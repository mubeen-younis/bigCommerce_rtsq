<?php

namespace App\Http\Controllers;

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
            $postData = json_decode($postData, true);
            $storeHash = explode('/', $postData['producer']);
            $storeHash = $storeHash[1];
            $orderId = $postData['data']['id'];
            // Update,delete,create from  webhook
            $scope = $postData['scope'];
            $store = Store::where('hash', $storeHash)->first();
            if (empty($storeID)) {
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
    }
}
