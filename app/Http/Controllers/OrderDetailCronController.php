<?php

namespace App\Http\Controllers;

use App\CustomClasses\CurlRequest;
use App\Models\Store;
use App\Models\StoreOrderCronCount;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrderDetailCronController extends Controller
{
    public $curlRequest;

    public $perPage = 250;

    public $minDateCreated;

    public $storeDetails;
    public $orderController;

    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
        $this->orderController = new OrderController();
        $this->setMinDateCreated();
        $this->storeDetails = [];

    }

    public function setMinDateCreated()
    {
        $this->minDateCreated = Carbon::now()->subDays(15)->toISOString();
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
            $this->importOrdersSinceDate();
        } else {
            $this->importOrdersSinceID();
        }
    }

    /**
     * Import Orders since date
     * @return void|null
     */
    public function importOrdersSinceDate()
    {
        $orderEndpoint = 'https://api.bigcommerce.com/stores/' . $this->storeDetails['hash'] . '/v2/orders?min_date_created=' . $this->minDateCreated . '&limit=' . $this->perPage;
        $orders = $this->getOrdersFromBC($orderEndpoint);
        if (blank($orders)) {
            return null;
        }
        $this->processOrders($orders);

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
     * Execute after the specific order ID
     * Which will optimize the process of imports
     * @return void|null
     */
    public function importOrdersSinceID()
    {
        $orderEndpoint = 'https://api.bigcommerce.com/stores/' . $this->storeDetails['hash'] . '/v2/orders?min_id=' . $this->storeDetails['store_order_cron_count']['min_order'];
        $orders = $this->getOrdersFromBC($orderEndpoint);
        if (blank($orders)) {
            return null;
        }
        $this->processOrders($orders);
    }


    /**
     * Gets Orders response from bigcommerce
     * @param $orderEndpoint
     * @return mixed|null
     */
    public function getOrdersFromBC($orderEndpoint)
    {
        $orders = $this->curlRequest->enSingleCurlRequest($orderEndpoint, [], $this->getRequestHeaders(), 'GET', true);
        if (isset($orders['status']) && $orders['status'] == false) {
            return null;
        }
        return json_decode($orders['response'], true) ?? null;

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
     * Process Orders Array
     * @param $orders
     * @return void
     */
    public function processOrders($orders)
    {
        foreach ($orders as $order) {
            $orderID = $order['id'] ?? null;
            if (blank($orderID)) {
                continue;
            }
            $orderWebhookSample = $this->getWebhookSampleData($orderID);
            $this->orderController->orderWebhookProcess([], $orderWebhookSample);
            // Updates order count in stores table, so we process since id orders after first import
            StoreOrderCronCount::addOrUpdate($this->storeDetails['id'], $orderID);
        }
    }

    /**
     * Returns webhook sample data
     * @param $id
     * @return array
     */
    public function getWebhookSampleData($id)
    {
        $sampleData['producer'] = "stores/" . $this->storeDetails['hash'];
        $sampleData['hash'] = "aeeeaa952cee00eab08c6b1968a87e49452a50cb";
        $sampleData['created_at'] = "1676904417";
        $sampleData['store_id'] = "";
        $sampleData['scope'] = "store/order/created";
        $sampleData['data']['type'] = "order";
        $sampleData['data']['id'] = $id;
        return $sampleData;
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
