<?php

namespace App\Http\Controllers;

use App\CurlRequest;
use App\CustomClasses\BigCommerceFunctions;
use App\CustomClasses\Functions;
use App\Endpoints\Endpoints;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/*@Author : saif
 * Class has been updated by Saif
 * */

class WebHooksController extends Controller
{
    /**
     * @var MainController
     */
    protected $mainController;
    /**
     * @var CurlRequest
     */
    protected $curlRequest;
    protected $orderWebHooks;
    protected $productWebHooks;
    protected $skuWebHooks;
    protected $webHookRequests;
    protected $webhookEndpoint;
    protected $headers;
    protected $storeToken;
    protected $baseAppUrl;

    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
        $this->mainController = new MainController();
        $this->orderWebHooks = ['store/order/created'];
        $this->productWebHooks = [/*'store/product/created',*/ 'store/product/updated', 'store/product/deleted'];
        $this->skuWebHooks = [/*'store/sku/created',*/ 'store/sku/updated', 'store/sku/deleted'];
        $this->webHookRequests = [];
        $this->webhookEndpoint = '';
        $this->headers = [];
        $this->baseAppUrl = App::environment('staging') ? env('APP_URL') : URL::to('/');
    }


    /**
     * @param $request
     * @return bool|\Illuminate\Http\JsonResponse
     */
    public function registerAllBcWebhooks($request)
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
        $this->storeToken = $storeToken;
        $this->webhookEndpoint = Endpoints::getBCComEndpoint() . $storeHash . '/v3/hooks';
        $this->setHeaders();
        $this->setAllWebhookRequests();
        $response = $this->curlRequest->enMultiCurl($this->webHookRequests, 'POST');
        Log::info('Webhook registration response on installation ' . json_encode($response));
        Store::where('id', $storeId)->update(['is_webhook_created' => true]);
        return true;
    }


    public function setHeaders()
    {
        $this->headers[] = 'X-Auth-Client: ' . $this->mainController->getAppClientId();
        $this->headers[] = 'X-Auth-Token: ' . $this->storeToken;
        $this->headers[] = 'Content-Type: application/json';
        $this->headers[] = 'Accept: application/json';
    }


    public function setAllWebhookRequests()
    {
        $this->setOrderWebHook();
        $this->setProductWebHook();
        $this->setSkuWebHook();
    }


    public function setOrderWebHook()
    {
        foreach ($this->orderWebHooks as $scope) {
            $this->webHookRequests[] = [
                'request' => json_encode($this->getWebHookRequest($scope, $this->baseAppUrl . '/api/order/webhooks')),
                'header' => $this->headers,
                'endpoint' => $this->webhookEndpoint
            ];
        }
    }


    public function setProductWebHook()
    {
        foreach ($this->productWebHooks as $scope) {
            $this->webHookRequests[] = [
                'request' => json_encode($this->getWebHookRequest($scope, $this->baseAppUrl . '/api/webhooks')),
                'header' => $this->headers,
                'endpoint' => $this->webhookEndpoint
            ];
        }
    }


    public function setSkuWebHook()
    {
        foreach ($this->skuWebHooks as $scope) {
            $this->webHookRequests[] = [
                'request' => json_encode($this->getWebHookRequest($scope, $this->baseAppUrl . '/api/sku/webhooks')),
                'header' => $this->headers,
                'endpoint' => $this->webhookEndpoint
            ];
        }
    }


    /**
     * @param $scope
     * @param $destination
     * @return array
     */
    public function getWebHookRequest($scope, $destination): array
    {
        return ["scope" => $scope,
            "destination" => $destination,
            "is_active" => true];
    }


    /**
     * @return void
     */
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


    /**
     * @param $storeHash
     * @return array|bool[]
     */
    public static function checkIsOrderWebhookActive($storeHash): array
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
            if (in_array($webHook['scope'], Functions::$orderWebhookString) &&
                $webHook['destination'] == Endpoints::orderWebhookEndpoint()) {
                return ['is_active' => $webHook['is_active'], 'id' => $webHook['id']];
            }
        }
        return ['is_active' => true];

    }


    /**
     * @param $storeHash
     * @param $id
     * @return void
     */
    public static function activateStoreWebhook($storeHash, $id)
    {
        $storeDetails = BigCommerceFunctions::getUpdateWebhookDetail($storeHash, $id);
        $storeDetails = (new CurlRequest())->enSingleCurlRequest($storeDetails['endpoint'],
            $storeDetails['request'], $storeDetails['headers'], $storeDetails['method'], false);
        Functions::log('Activated webhook of store ' . $storeHash . ' Response ' . json_encode($storeDetails));
    }


    /**
     * @param Request $request
     * @return void
     */
    public function registerStoreWebhooksManually(Request $request)
    {
        $request['store_name'] = $request['store_hash'];
        $this->registerAllBcWebhooks($request);
    }


    /*
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
            foreach ($this->orderWebHooks as $scope) {
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
                    "scope" => $scope,
                    "destination" => URL::to('api/order/webhooks'),
                    "is_active" => true
                ];
                $response = $this->curlRequest->enSingleCurlRequest($endpoint, json_encode($request), $headers, 'POST', false);
                $response = json_decode($response['response'], true);
                return true;
            }

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
        }*/

}
