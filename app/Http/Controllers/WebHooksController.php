<?php

namespace App\Http\Controllers;

use App\CurlRequest;
use App\CustomClasses\BigCommerceFunctions;
use App\CustomClasses\Functions;
use App\Endpoints\Endpoints;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $storeId = $request['store_id'] ?? '';
        $storeHash = $request['store_name'] ?? '';
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
        $response = json_decode($response['response'], true);
        // will update store column of webhook
        if (isset($response['data']['id'])) {
            Store::where('id', $storeId)->update(['is_webhook_created' => true]);
        }
        return true;
    }

    public function registerOrderWebHook($request)
    {
        $storeId = $request['store_id'] ?? '';
        $storeHash = $request['store_name'] ?? '';
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
        $endpoint = 'https://api.bigcommerce.com/stores/' . $storeHash . '/v3/hooks';
        $request = [
            "scope" => "store/order/*",
            "destination" => URL::to('api/order/webhooks'),
            "is_active" => true
        ];
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, json_encode($request), $headers, 'POST', false);
        $response = json_decode($response['response'], true);

        return true;
    }

    public function registerSkuWebHook($request)
    {
        $storeId = $request['store_id'] ?? '';
        $storeHash = $request['store_name'] ?? '';
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
        $endpoint = 'https://api.bigcommerce.com/stores/' . $storeHash . '/v3/hooks';
        $request = [
            "scope" => "store/sku/*",
            "destination" => URL::to('api/sku/webhooks'),
            "is_active" => true
        ];
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, json_encode($request), $headers, 'POST', false);
        $response = json_decode($response['response'], true);

        return true;
    }


    public static function updateOrderWebhookStatus()
    {
        try {
            $stores = Store::getActiveStores();
            foreach ($stores as $store) {
                $storeHash = $store['hash'];
                $response = self::checkIsOrderWebhookActive($storeHash);
                if (!$response['is_active']) {
                    self::activateStoreWebhook($storeHash, $response['id']);
                }
            }
        } catch (\Exception $exception) {
            Functions::log('Cron Exception on updating webhook statuses', $exception, 'error');
        }

    }

    public static function checkIsOrderWebhookActive($storeHash)
    {
        $storeDetails = BigCommerceFunctions::getWebhooksOfStore($storeHash);
        $storeDetails = (new CurlRequest())->enSingleCurlRequest($storeDetails['endpoint'],
            $storeDetails['request'], $storeDetails['headers'], $storeDetails['method'], true);
        $webHooks = json_decode($storeDetails['response'], true);
        if (blank($webHooks)) {
            Functions::log('No webhooks found for store :' . $storeHash . 'Store Details' . json_encode($storeDetails));
            return ['is_active' => true];
        }
        foreach ($webHooks as $webHook) {
            if ($webHook['scope'] == Functions::$orderWebhookString &&
                $webHook['destination'] == Endpoints::orderWebhookEndpoint()) {
                return ['is_active' => $webHook['is_active'], 'id' => $webHook['id']];
            }
        }
        return ['is_active' => true];

    }


    public static function activateStoreWebhook($storeHash, $id)
    {
        $storeDetails = BigCommerceFunctions::getUpdateWebhookDetail($storeHash, $id);
        $storeDetails = (new CurlRequest())->enSingleCurlRequest($storeDetails['endpoint'],
            $storeDetails['request'], $storeDetails['headers'], $storeDetails['method'], false);
        Functions::log('Activated webhook of store ' . $storeHash . ' Response ' . json_encode($storeDetails));
    }
}
