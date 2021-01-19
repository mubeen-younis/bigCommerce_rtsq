<?php

namespace App\Http\Controllers;

use App\CurlRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class WebHooksController extends Controller
{
    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
        $this->mainController = new MainController();
    }

    public function registerWebHook($request, $webHookType)
    {
        // dd($request, $webHookType,URL::to('api/webhooks'));
        $storeId = isset($request['store_id']) ? $request['store_id'] : '';
        $storeHash = isset($request['store_name']) ? $request['store_name'] : '';
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
            "scope" => "store/product/" . $webHookType,
            "destination" => URL::to('api/webhooks'),
            "is_active" => true
        ];
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, json_encode($request), $headers, 'POST', false);
        return $response;
    }
}
