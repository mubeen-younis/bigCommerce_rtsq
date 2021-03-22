<?php

namespace App\Http\Controllers;

use App\CurlRequest;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class WebHooksController extends Controller
{
    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
        $this->mainController = new MainController();
    }

    public function registerWebHook($request)
    {

        // dd($request, $webHookType,URL::to('api/webhooks'));
        $storeId =  $request['store_id'] ?? '';
        $storeHash =  $request['store_name'] ?? '';
        $storeToken = $this->mainController->getCustAccessTok($storeId);
        if (isset($storeToken['status']) && $storeToken['status'] == false) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Token Not Found'
            ], 200);
        }
        $headers[] = 'X-Auth-Client: ' . $this->mainController->getAppClientId();
        $headers[] = 'X-Auth-Token: ' . $storeToken;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';

        $endpoint = 'https://api.bigcommerce.com/stores/' . $storeHash . '/v3/hooks';
        $request = [
            "scope" => "store/product/*",
            "destination" => URL::to('api/webhooks'),
            "is_active" => true
        ];
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, json_encode($request), $headers, 'POST', false);
        $response=json_decode($response['response'],true);
        // will update store column of webhook
        if(isset($response['data']['id'])){
            Store::where('id',$storeId)->update(['is_webhook_created'=>true]);
        }
        return true;
    }

    public function registerCarrier($request){
        $storeId =  $request['store_id'] ?? '';
        $storeHash =  $request['store_name'] ?? '';
        $storeToken = $this->mainController->getCustAccessTok($storeId);
        if (isset($storeToken['status']) && $storeToken['status'] == false) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Token Not Found'
            ], 200);
        }
        $headers[] = 'X-Auth-Client: ' . $this->mainController->getAppClientId();
        $headers[] = 'X-Auth-Token: ' . $storeToken;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        //https://api.bigcommerce.com/stores/uann2u/v2/shipping/carrier/connection
        $endpoint = 'https://api.bigcommerce.com/stores/' . $storeHash . '/v2/shipping/carrier/connection';
        $request = [
            "carrier_id" => "149", //149 provided by bigcommerce our carrier id
            "connection" => []
        ];
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, json_encode($request), $headers, 'POST', false);
        $response=json_decode($response['response'],true);

        return true;
    }
}
