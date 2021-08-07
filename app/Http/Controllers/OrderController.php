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
    public function index(Request $request)
    {
        $orders = $this->getBCOrders($request);
        $orders['cpage'] = $request['page'] ?? 1;
        if(empty($orders['allOrders'])){
            return response()->json(
                [
                    'data' => [],
                    'message' => "Order not found",
                    'error' => true,
                ]
            );
        }else {
            return response()->json(
                [
                    'data' => $orders['allOrders'],
                    'meta' => $orders['meta'],
                    'error' => false,
                ]
            );
        }
    }

    public function getOrderWidget(Request $request){
        $order = $this->getBCOrderByID($request);
        if(empty($order)){
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Order Found',
            ], 404);
        }
       // dd($order['cart_id'], $order['rate_id']); //echo "<pre>"; print_r($order); exit;
        $orderWidget = $this->createOrderWidget($request, $order);
        if(empty($orderWidget)){
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Order Widget Found',
            ], 404);
        }
        return response()->json(
            [
                'data' => $orderWidget,
                'error' => false,
            ]
        );
    }

    public function createOrderWidget($request, $order){
        $data = RequestData::where('rate_id', $order['rate_id'])
            ->where('cart_id', $order['cart_id'])
            ->where('store_id', $request['store_id'])
            ->first()->toArray();
        //dd($data);
        //print($order['rate_id']); exit;
        if(empty($data)){
            return [];
        }
        //echo "<pre>"; print_r($data); exit;
        $isSmallrate = substr($order['rate_id'], 0, 9) == 'parcel_12' || substr($order['rate_id'], 0, 5) == 'Multi'  ? true : false;
        $isLG = strpos($order['rate_id'], '+LG');

        $lineItem = json_decode($data['lineitems'])->lineItemData;
        $responseFromWS = json_decode($data['quotes']);
        $requestToWS = json_decode($data['request']);
        //echo "<pre>"; print_r($requestToWS); exit;
        $multiShipmentresponse = json_decode($data['multiShipmentresponse']);
        $autoResidentialsStatus = 'n';
        $residentialsPickup = 'n';
        $liftGateStatus = 'n';
        $binPackagingData = '';
        $orderWidget = [];
        foreach($responseFromWS as $carrrierName => $WsResp){
            //print_r($WsResp); exit;
            foreach($WsResp as $zip => $ws){

                if( !(isset($ws->severity) && $ws->severity == 'ERROR') ){

                    $liftResidentialStatus = $this->getLiftResidentialStatus($requestToWS, $isSmallrate);
                    if($isLG) {
                        $liftGateStatus = $liftResidentialStatus['liftG'] ?? 'n';
                    }
                    $autoResidentialsStatus = $liftResidentialStatus['resi'] ?? 'n';
                    $residentialsPickup = $liftResidentialStatus['resiPickup'] ?? 'n';
                    //$autoResidentialsStatus = $ws->autoResidentialsStatus ?? 'n';

                    //$binPackagingData = $ws['binPackagingData']['response']['']

                    $totalBoxes = 0;
                   // dd($order['rate_id'],$isSmallrate);
                    //print_r($ws); exit;
                    if(isset($ws->binPackagingData) && !empty($ws->binPackagingData) && $isSmallrate){
                        $sbsData = $ws->binPackagingData->response;
                        //print_r($sbsData);
                        if(1/*isset($sbsData->errors) && empty($sbsData->errors)*/) {
                            //$binPacked = $sbsData->bins_packed[0];

                            foreach ($sbsData->bins_packed as $key => $binPacked) {

                                $type = '';
                                if (isset($binPacked->bin_data->type) && $binPacked->bin_data->type == 'item') {
                                    $type = 'item';
                                }
                                $count = 0;
                                foreach ($binPacked->items as $item) {
                                    $orderWidget[$zip]['sbs'][$key]['type'] = $type;
                                    $orderWidget[$zip]['sbs'][$key]['image_complete'] = $binPacked->image_complete;
                                    $orderWidget[$zip]['sbs'][$key]['d'] = $binPacked->bin_data->d. 'x';
                                    $orderWidget[$zip]['sbs'][$key]['w'] = $binPacked->bin_data->w . 'x';
                                    $orderWidget[$zip]['sbs'][$key]['h'] = $binPacked->bin_data->h;

                                    $orderWidget[$zip]['sbs'][$key]['nickname'] = $this->getBoxName($binPacked->bin_data->id, $request['store_id'], $order['rate_id'], $order['cart_id']);
                                    $productid = $item->id;
                                    $orderWidget[$zip]['sbs'][$key]['items'][$count]['product_name'] = $lineItem->items->$productid->lineItemName;
                                    $orderWidget[$zip]['sbs'][$key]['items'][$count]['w'] = $item->w;
                                    $orderWidget[$zip]['sbs'][$key]['items'][$count]['h'] = $item->h;
                                    $orderWidget[$zip]['sbs'][$key]['items'][$count]['d'] = $item->d;

                                    $orderWidget[$zip]['sbs'][$key]['items'][$count]['image_separated'] = $item->image_separated;
                                    $orderWidget[$zip]['sbs'][$key]['items'][$count]['image_sbs'] = $item->image_sbs;
                                    $count++;

                                }
                                $orderWidget[$zip]['sbs'][$key]['number_of_items'] = $count;
                            }
                            $totalBoxes = $key+1;
                        }
                    }
                }
            }
        }
        $origins = $lineItem->origin;
        $items = $lineItem->items;

        $count = 0;
        //print_r($orderWidget); exit;
        $addedInsurance = $addHazmat = false;
        //echo "<pre>"; print_r($items); print_r($origins); exit;
        foreach($origins as $key => $origin){
            $item =  $items->$key;
            //dd($item);
            $city = $origin->senderCity ? $origin->senderCity.',': '';
            $state = $origin->senderState ?? '';
            $zip = $origin->locationId != '' ? $origin->locationId : $origin->senderZip;
            $senderZip = $origin->senderZip ?? '';
            $orderWidget[$zip]['locationtype'] = $item->dropship_enabled == 'N' ? 'Warehouse' : 'Dropship';
            $orderWidget[$zip]['address'] = $city . ' ' . $state . ' ' . $senderZip;
            $orderWidget[$zip]['totalBoxes'] = $totalBoxes;
            $sRate = $order['shipping_rate'];
            if($multiShipmentresponse != null && !empty($multiShipmentresponse)){
                $order['shipping_name'] = $multiShipmentresponse->simple->$zip->title;

                $sRate = $isLG ?  $multiShipmentresponse->liftgate->$zip->rate : $multiShipmentresponse->simple->$zip->rate;
            }
            //dd($order['shipping_name']);
            $shipping_name = explode('(',$order['shipping_name']);
            $sName = $shipping_name[0] ?? '';
            $sMethod = isset($shipping_name[1]) ? '('.$shipping_name[1] : '';

            $orderWidget[$zip]['shipping_method'] = $sName.$sMethod;
            $orderWidget[$zip]['shipping_rate'] = '$'.$sRate;
            $orderWidget[$zip]['items'][] = $item->piecesOfLineItem.' X '.$item->lineItemName;
            $orderWidget[$zip]['accessories'] = [];

            if(isset($item->isHazmatLineItem) && $item->isHazmatLineItem == 'Y') {
                array_push($orderWidget[$zip]['accessories'], 'Hazardous Material');
                $addHazmat = true;
            }else if($addHazmat){
                array_push($orderWidget[$zip]['accessories'], 'Hazardous Material');
            }
            if(isset($item->product_insurance_active) && $item->product_insurance_active == 'Y'){
                array_push($orderWidget[$zip]['accessories'], 'Insurance');
                $addedInsurance = true;
            }else if($addedInsurance){
                array_push($orderWidget[$zip]['accessories'], 'Insurance');
            }

            $autoResidentialsStatus != 'n' ? array_push($orderWidget[$zip]['accessories'], 'Residential Delivery') : '';
            $residentialsPickup != 'n' ? array_push($orderWidget[$zip]['accessories'], 'Residential Pickup') : '';
            $liftGateStatus != 'n' ? array_push($orderWidget[$zip]['accessories'], 'Lift Gate Delivery') : '';
            $count++;
        }
        $sbs = '';
        $resp = [
            'widget' => $this->objectToArray( $orderWidget ),
            'sbs' => $sbs
        ];
        return $resp;
    }


    public function getLiftResidentialStatus($requestToWS, $isSmallrate){
       // print_r($requestToWS); exit;
        $response = ['resi' => 'n', 'liftG' => 'n', 'resiPickup' => 'n'];
        if($isSmallrate){
            $checkResi = isset($requestToWS->requestArr->carriers->wweSmall->api->residentials_delivery) && ($requestToWS->requestArr->carriers->wweSmall->api->residentials_delivery == 'Y' || $requestToWS->requestArr->carriers->wweSmall->api->residentials_delivery == 'yes' );
            if($checkResi){
                $response['resi'] = 'Y';
            }
        }else {
            $checkResi = isset($requestToWS->requestArr->carriers->wweLTL->api->speed_freight_residential_delivery) && ($requestToWS->requestArr->carriers->wweLTL->api->speed_freight_residential_delivery == 'Y' || $requestToWS->requestArr->carriers->wweLTL->api->speed_freight_residential_delivery == 'yes');
            if($checkResi){
               $response['resi'] = 'Y';
            }

            $checkLift = isset($requestToWS->requestArr->carriers->wweLTL->api->speed_freight_lift_gate_delivery) && ($requestToWS->requestArr->carriers->wweLTL->api->speed_freight_lift_gate_delivery == 'Y' || $requestToWS->requestArr->carriers->wweLTL->api->speed_freight_lift_gate_delivery == 'yes');
            if($checkLift){
                $response['liftG'] = 'Y';
            }

            $checkResiPickup = isset($requestToWS->requestArr->carriers->wweLTL->api->speed_freight_residential_pickup) && ($requestToWS->requestArr->carriers->wweLTL->api->speed_freight_residential_pickup == 'Y' || $requestToWS->requestArr->carriers->wweLTL->api->speed_freight_residential_pickup == 'yes');
            if($checkResiPickup){
                $response['resiPickup'] = 'Y';
            }
        }
        //dd($response);
        return $response;
    }
    public function getBoxName($binId, $store_id, $rate_id, $cart_id){
        $data = RequestData::where('rate_id', $rate_id)
            ->where('cart_id', $cart_id)
            ->where('store_id', $store_id)
            ->first()->toArray();
        $binId = (int) $binId;
        $bins = json_decode($data['box_bins']);
        if(!empty($bins)){
            foreach ($bins as $bin){
                if($binId == $bin->id){
                    return $bin->nickname;
                }
            }
        }
    }

    public function objectToArray($orderWidget){
        $resp= [];
        foreach ($orderWidget as $widget){
            $resp[] = $widget;
        }
        return $resp;
    }

    public function getBCOrderByID($request){
        $store = Store::where('hash', $request['store_hash'])->first();
        if(empty($store)){
            return [];
        }
        $headers[] = 'X-Auth-Token: ' . $store->access_token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = "https://api.bigcommerce.com/stores/".$request['store_hash']."/v2/orders/".$request['order_id'];
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);
        $resp = [];
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $resp  = json_decode($response['response'], true);
            $endpoint = json_decode($response['response'])->shipping_addresses->url;
            $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', true);
            if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
                $endpoint = json_decode($response['response'])[0]->shipping_quotes->url;
                $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', true);
                if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
                    $response = json_decode($response['response']);
                    $resp['rate_id'] = $response->rate_id;
                    $resp['shipping_name'] = $response->shipping_provider_quote->name ?? '';
                    $resp['shipping_rate'] = $response->shipping_provider_quote->rate->value ?? '';
                }
            }
        }
        return $resp;
    }

    public function getBCOrders($request){
        $store = Store::where('hash', $request['store_hash'])->first();
        if(empty($store)){
            return null;
        }
        $page = $request['page'] ?? 1;
        $perPage = $request['perpage'] ?? 50;
        $status = $request['status'] ?? '';
        $sortProd = (isset($request['sortOrder']) && $request['sortOrder'] == "true") ? 'asc':'desc';
        //dd($status);
        $search = (int) $request['search'] ?? 0;
        $headers[] = 'X-Auth-Token: ' . $store->access_token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $total = 1;
        if($search){
            if($status == ''){
                $endpoint = "https://api.bigcommerce.com/stores/".$request['store_hash']."/v2/orders/".$search;
            }else{
                $endpoint = "https://api.bigcommerce.com/stores/".$request['store_hash']."/v2/orders/".$search."?status_id=".$status;
            }

        }else{
            if($status == ''){
                $countEndPoint = "https://api.bigcommerce.com/stores/".$request['store_hash']."/v2/orders/count";
            }else{
                $countEndPoint = "https://api.bigcommerce.com/stores/".$request['store_hash']."/v2/orders/count?status_id=".$status;
            }
            $response = $this->curlRequest->enSingleCurlRequest($countEndPoint, [], $headers, 'GET', false);
            $total = (int) ceil(json_decode($response['response'])->count);

            if($status !== ''){
                $endpoint = "https://api.bigcommerce.com/stores/".$request['store_hash']."/v2/orders?sort=id:desc&status_id=".$status."&limit=".$perPage."&page=".$page;
            }else{
                $endpoint = "https://api.bigcommerce.com/stores/".$request['store_hash']."/v2/orders?sort=id:desc&limit=".$perPage."&page=".$page;
            }

        }
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);

        $resp = [];
        $resp['allOrders'] = [];

        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $response = json_decode($response['response'], true);
            //dd($endpoint,$response);
            if( !(isset($response[0]['status']) && $search)){
                $orders = $search ? [$response] : $response;

                $orders = $sortProd ==='desc' ? $orders : array_reverse($orders);

                $count = 0;
                if($orders) {
                    foreach ($orders as $count => $order) {
                        //$count++;
                        $resp['allOrders'][$count]['id'] = $order['id'];
                        $resp['allOrders'][$count]['customer'] = $order['billing_address']['first_name'] . ' ' . $order['billing_address']['last_name'];
                        $resp['allOrders'][$count]['date_created'] = date("m/d/Y", strtotime($order['date_created']));
                        $resp['allOrders'][$count]['status'] = $order['status'];
                        $resp['allOrders'][$count]['total_inc_tax'] = '$' . number_format((float)$order['total_inc_tax'], 2, '.', '');
                        $resp['allOrders'][$count]['items_total'] = $order['items_total'];
                    }
                }
            }
        }

        $resp['meta']  =[
            'total' => $total,
            'current' => $page,
            'perpage' => $perPage
        ];

        return $resp;
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
        $order = $this->getBCOrderByID($toRequest);
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

    /*public function getBCOrderByID($toRequest)
    {
        $headers[] = 'X-Auth-Token: ' . $this->accessToken;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = 'https://api.bigcommerce.com/stores/' . $toRequest['store_hash'] . '/v2/orders/' . $toRequest['order_id'];
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', true);
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            return $response['response'];
        }
    }*/

    /***
     * @param $toRequest
     * Move row from request_temp to request table after order placing
     * delete all rows from request_temp relevant to cart_id
     */
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
                    $reqData = RequestTempData::where('rate_id', $rateId)->where('cart_id', $cartId)->get()->toArray();
                    foreach ($reqData as $data)
                    {
                        unset($data['id']);
                        RequestData::insert($data);
                    }
                    RequestTempData::where('cart_id', $cartId)->delete();
                }
            }
        }
    }
}
