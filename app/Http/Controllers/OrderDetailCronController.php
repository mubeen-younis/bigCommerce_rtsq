<?php

namespace App\Http\Controllers;

use App\CustomClasses\CurlRequest;
use App\Models\Store;
use App\Models\StoreOrderCronCount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrderDetailCronController extends Controller
{
    public $curlRequest;

    public $perPage = 250;

    public $storeDetails;
    public $orderController;

    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
        $this->orderController = new OrderController();
        $this->storeDetails = [];

    }

    /**
     * Executes cron for webhooks
     * @return void
     */
    public function createOrderDetailData()
    {
        set_time_limit(-1);
        $stores = Store::getActiveStores();
        foreach ($stores as $store) {
            try {
                $this->setStoreDetails($store);
                $this->processStoreCron();
            } catch (\Exception $exception) {
                Log::info('Webhook cron exception ' . json_encode([
                        'storedet' => $store, 'line' => $exception->getLine(),
                        'message' => $exception->getMessage(), 'file' => $exception->getFile()
                    ]));
            }
        }

    }

    /**
     * Sets store details
     * @param $store
     * @return void
     */
    public function setStoreDetails($store)
    {
        $this->storeDetails = $store;
    }

    /**
     * Prcoesses orders
     * @return void
     */
    public function processStoreCron()
    {
        if (empty($this->storeDetails['store_order_cron_count'])) {
            $this->importOrdersAsNew();
        } else {
            $this->importOrdersSinceID();
        }
    }

    /**
     * Executes first time for store to check his orders
     * @return false|void
     */
    public function importOrdersAsNew()
    {
        $countOfOrders = $this->getCountOfOrders();
        if (blank($countOfOrders)) {
            return false;
        }
        $this->processPaginatedOrders($countOfOrders);

    }




    /**
     * Returns orders count
     * @return mixed|null
     */
    public function getCountOfOrders()
    {
        $orderEndpoint = 'https://api.bigcommerce.com/stores/' . $this->storeDetails['hash'] . '/v2/orders/count';
        $getCountOfOrders = $this->curlRequest->enSingleCurlRequest($orderEndpoint, [], $this->getRequestHeaders(), 'GET', true);
        if (isset($response['status']) && $response['status'] == false) {
            return null;
        }
        $count = json_decode($getCountOfOrders['response'], true);
        return $count['count'] ?? null;
    }


    /**
     * Processes paginated Orders
     * @param $countOfOrders
     * @return void|null
     */
    public function processPaginatedOrders($countOfOrders)
    {
        $numberOfPages = ceil($countOfOrders / $this->perPage);
        if ($numberOfPages <= 0) {
            return null;
        }
        for ($pageNumber = 1; $pageNumber <= $numberOfPages; $pageNumber++) {
            $this->processPage($pageNumber);
        }
    }

    /**
     * Process pages of orders
     * @param $pageNumber
     * @return void|null
     */
    public function processPage($pageNumber)
    {
        $orderEndpoint = 'https://api.bigcommerce.com/stores/' . $this->storeDetails['hash'] . '/v2/orders?limit=' . $this->perPage . '&page=' . $pageNumber;
        $orders = $this->curlRequest->enSingleCurlRequest($orderEndpoint, [], $this->getRequestHeaders(), 'GET', true);
        if (isset($orders['status']) && $orders['status'] == false) {
            return null;
        }
        $orders = json_decode($orders['response'], true);
        if (blank($orders)) {
            return null;
        }
        $this->processOrders($orders);

    }





    /**
     * Returns request headers
     * @return array
     */
    public function getRequestHeaders()
    {
        $headers[] = 'X-Auth-Token: ' . $this->storeDetails['access_token'];
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        return $headers;
    }
}
