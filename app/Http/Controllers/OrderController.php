<?php

namespace App\Http\Controllers;

use App\Constants\Constant;
use App\CurlRequest;
use App\Models\BoxSize;
use App\Models\Orders;
use App\Models\RequestData;
use App\Models\RequestTempData;
use App\Models\ShippingGroup;
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
        if (empty($orders['allOrders'])) {
            return response()->json(
                [
                    'data' => [],
                    'message' => "Order not found",
                    'error' => true,
                ]
            );
        } else {
            return response()->json(
                [
                    'data' => $orders['allOrders'],
                    'meta' => $orders['meta'],
                    'error' => false,
                ]
            );
        }
    }

    public function getOrderWidget(Request $request)
    {
        try {
            $order = $this->getBCOrderByID($request);
            if (empty($order)) {
                return response()->json(['error' => true,
                    'data' => [],
                    'message' => 'No Order Found',
                ], 404);
            }
            $orderWidget = $this->createOrderWidget($request, $order);
            if (empty($orderWidget)) {
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
        } catch (\Exception $exception) {
            return response()->json(['error' => true,
                'data' => [$exception->getMessage()],
                'message' => 'No Order Widget Found',
            ], 404);
        }
    }

    public function formateItems($oldItems, $items)
    {
        $tempItems = $items;
        foreach ($tempItems as $key => $item) {
            $variant_id = $item->variant_id;
            $oldItems->$variant_id = $item;
            $oldItems->$key = $item;
        };
        return $oldItems;
    }

    public function formateOrigins($carriers)
    {
        $newOrigin = new \stdClass();
        foreach ($carriers as $carrier) {
            foreach ($carrier->originAddress as $key => $origin) {
                $newOrigin->$key = $origin;
            }
        }
        return $newOrigin;
    }

    public function createOrderWidget($request, $order)
    {
        $rateId = $order['rate_id'] ?? null;
        $cartId = $order['cart_id'] ?? null;
        $data = optional(RequestData::where('rate_id', $rateId)
                ->where('cart_id', $cartId)
                ->where('store_id', $request['store_id'])
                ->first())->toArray() ?? null;
        if (blank($data) && !blank($order['full_rate_id'])) {
            $data = optional(RequestData::where('rate_id', $order['full_rate_id'])
                    ->where('cart_id', $cartId)
                    ->where('store_id', $request['store_id'])
                    ->first())->toArray() ?? null;
            $rateId = $order['full_rate_id'] ?? null;
        }
        if (blank($data)) {
            return [];
        }
        $carrierHasInsurance = $this->hasInsureCarrier($rateId);
        $index = explode('idx+', $rateId);
        if (is_string($index[0]) && $index[0] == "shippingGroup") {
            return $this->shippingGroupOrderWidget($data, $order);
        }
        $index = explode('idx+', $rateId)[1];
        if (!empty($index)) {
            $index = strlen($index) <= 11 ? (int)substr($index, 0, 1) : (int)substr($index, 0, 2);
            // $index = (int)substr($index, 0, 1);
        }
        $isSmallLtlrate = substr($rateId, 0, 5) == 'multi' ? true : false;
        $isHAT = strpos(strtolower($rateId), '+hat');

        $rateId = strtolower($rateId);
        $isSmallrate = substr($rateId, 0, 9) == 'parcel_12' || substr($rateId, 0, 5) == 'multi' ? true : false;
        $isLG = strpos($rateId, '+lg');
        $isOwnArrangement = strpos($rateId, 'own_arrangement') === 0 || strpos($rateId, 'freernlltl') === 0 ? true : false;

        /*
        * Stored Response from WS */
        $lineItem = json_decode($data['lineitems'])->lineItemData;
        $responseFromWS = json_decode($data['quotes']);
        $shippingGroupResp = !blank($data['shipping_group_resp']) ? json_decode($data['shipping_group_resp']) : [];

        $requestToWS = json_decode($data['request']);
        $lineItem->items = $this->formateItems($lineItem->items, $requestToWS->requestArr->commdityDetails);
        //print_r($lineItem->items); exit;
        $lineItem->origin = $this->formateOrigins($requestToWS->requestArr->carriers);
        $multiShipmentresponse = $data['multiShipmentresponse'] === '{}' ? null : json_decode($data['multiShipmentresponse']);
        $autoResidentialsStatus = 'n';
        $residentialsPickup = 'n';
        $liftGateStatus = 'n';
        $binPackagingData = '';
        $orderWidget = [];
        $isOneRate = strpos($rateId, '+or');
        $isGround = strpos($rateId, '+gd');
        $isAir = strpos($rateId, '+as');

        /*
        * Shipment Packaging */
        $sbsItems = [];
        foreach ($responseFromWS as $carrrierName => $WsResp) {
            foreach ($WsResp as $zip => $ws) {
                if (!(isset($ws->severity) && $ws->severity == 'ERROR')) {

                    $liftResidentialStatus = $this->getLiftResidentialStatus($requestToWS, $isSmallrate, $isSmallLtlrate, $rateId);
                    if ($isLG) {
                        $liftGateStatus = $liftResidentialStatus['liftG'] ?? 'n';
                    }
                    $autoResidentialsStatus = $liftResidentialStatus['resi'] ?? 'n';
                    $residentialsPickup = $liftResidentialStatus['resiPickup'] ?? 'n';

                    $totalBoxes = 1;
                    if (isset($ws->binPackagingData) && !empty($ws->binPackagingData) && $isSmallrate) {
                        if ($isGround) {
                            $sbsData = $ws->binPackagingData->response->ground->bins_packed ?? $ws->binPackagingData->response->bins_packed ?? [];
                        } else if ($isAir) {
                            $sbsData = $ws->binPackagingData->response->air->bins_packed ?? $ws->binPackagingData->response->ground->bins_packed ?? $ws->binPackagingData->response->bins_packed ?? [];
                        } else if ($isOneRate) {
                            $sbsData = $ws->binPackagingData->response->oneRate->bins_packed ?? [];
                        } else {
                            $sbsData = $ws->binPackagingData->response->bins_packed ?? $ws->binPackagingData->response->ground->bins_packed ?? $ws->binPackagingData->response->air->bins_packed ?? $ws->binPackagingData->response->oneRate->bins_packed ?? [];
                        }
                        //print_r($ws->binPackagingData->response); exit;
                        $itemCount = 0;
                        foreach ($sbsData as $key => $binPacked) {
                            $type = '';
                            $quantity = 1;
                            if (isset($binPacked->bin_data->type) && $binPacked->bin_data->type == 'item') {
                                $type = 'item';
                                $product_id = $binPacked->bin_data->id;
                                $quantity = $binPacked->bin_data->quantity ?? 1;
                                $itemCount++;
                            }
                            $count = 0;
                            $orderWidgetData['type'] = $type;
                            $orderWidgetData['image_complete'] = $binPacked->image_complete;
                            $orderWidgetData['d'] = $binPacked->bin_data->d . ' x ';
                            $orderWidgetData['w'] = $binPacked->bin_data->w . ' x ';
                            $orderWidgetData['h'] = $binPacked->bin_data->h;
                            $orderWidgetData['quantity'] = $quantity;

                            $orderWidgetData['nickname'] = $this->getBoxName($binPacked->bin_data->id, $request['store_id'], $rateId, $cartId);
                            foreach ($binPacked->items as $item) {
                                $productid = $item->id;
                                $sbsItems[$zip][$productid] = 1;

                                $orderWidgetData['items'][$count]['product_name'] = $lineItem->items->$productid->lineItemName ?? '';
                                $orderWidgetData['items'][$count]['w'] = $item->w;
                                $orderWidgetData['items'][$count]['h'] = $item->h;
                                $orderWidgetData['items'][$count]['d'] = $item->d;

                                $orderWidgetData['items'][$count]['image_separated'] = $item->image_separated;
                                $orderWidgetData['items'][$count]['image_sbs'] = $item->image_sbs;

                                $orderWidget[$zip]['sbs'][$key] = $orderWidgetData;
                                ++$count;

                            }
                            unset($orderWidgetData);
                            if ($count) {
                                $orderWidget[$zip]['sbs'][$key]['number_of_items'] = $count;
                            }
                        }
                        $totalBoxes = isset($key) ? $key + 1 - $itemCount : 0;


                    }
                }
            }
        }

        /*
        * Shipment Origins */
        $origins = $lineItem->origin;
        $items = $lineItem->items;
        $count = 0;
        $addedInsurance = $addHazmat = false;
        $isMulti = false;
        $insertedIds = $insertedNames = [];
        //print_r($items); exit;
        $code = '';

        foreach ($origins as $key => $origin) {
            $item = $items->$key;
            $city = $origin->senderCity ? $origin->senderCity . ',' : '';
            $state = $origin->senderState ?? '';
            $zip = $origin->locationId != '' ? $origin->locationId : $origin->senderZip;
            $senderZip = $origin->senderZip ?? '';
            $orderWidget[$zip]['locationtype'] = $item->dropship_enabled == 'N' ? 'Warehouse' : 'Dropship';
            $orderWidget[$zip]['address'] = $city . ' ' . $state . ' ' . $senderZip;
            $orderWidget[$zip]['totalBoxes'] = $totalBoxes ?? 0;
            $sRate = $order['shipping_rate'];
            //print_r($multiShipmentresponse); exit;
            if ($multiShipmentresponse != null && !empty($multiShipmentresponse) && !$isOwnArrangement) {
                if ($isHAT) {
                    $sRate = $multiShipmentresponse->$index->hat->$zip->rate ?? $multiShipmentresponse->$index->liftgate->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? 0.00;
                    $order['shipping_name'] = $multiShipmentresponse->$index->hat->$zip->title ?? $multiShipmentresponse->$index->liftgate->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? '';
                } else if ($isLG) {
                    $sRate = $multiShipmentresponse->$index->liftgate->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? 0.00;
                    $order['shipping_name'] = $multiShipmentresponse->$index->liftgate->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? '';
                    $code = $multiShipmentresponse->$index->liftgate->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? '';
                } else {
                    $sRate = $multiShipmentresponse->$index->simple->$zip->rate ?? $multiShipmentresponse->$index->liftgate->$zip->rate ?? 0.00;
                    $order['shipping_name'] = $multiShipmentresponse->$index->simple->$zip->title ?? $multiShipmentresponse->$index->liftgate->$zip->title ?? '';
                    $code = $multiShipmentresponse->$index->simple->$zip->code ?? $multiShipmentresponse->$index->liftgate->$zip->code ?? '';
                }
                $carrierHasInsurance = $code ? $this->hasInsureCarrier($code) : false;

                /*Added condition if in case of multi shipment
                The rate of shipping group will be added to warehouse rate*/
                if ($shippingGroupResp != null && $orderWidget[$zip]['locationtype'] == "Warehouse") {
                    $shippingGroupRate = $shippingGroupResp[0]->rate ?? 0;
                    $sRate = $sRate + $shippingGroupRate;
                }
                $isMulti = true;
            }

            $shipping_name = explode('(', $order['shipping_name']);
            $sName = $shipping_name[0] ?? '';
            $sName = str_replace(Constant::RESI_LABEL, '', $sName);
            $sName = str_replace(Constant::LIFT_LABEL, '', $sName);
            $sName = str_replace(Constant::RESI_LIFT_LABEL, '', $sName);
            $sMethod = isset($shipping_name[1]) ? '(' . $shipping_name[1] : '';

            $orderWidget[$zip]['shipping_method'] = $sName . $sMethod;
            $orderWidget[$zip]['shipping_rate'] = '$' . number_format((float)$sRate, 2,);

            if ($item->shipMultiplePackage) {
                if ((!in_array($item->lineItemName, $insertedNames))) {
                    $insertedNames[] = $item->lineItemName;
                    $orderWidget[$zip]['items'][] = $item->originalPiecesOfLineItem . ' X ' . $item->lineItemName;
                }

                /*Added Else if BLock for Catering BUg of MUltiple Products IN ONe BOX*/
            } elseif (isset($sbsItems[$zip]) && !empty($sbsItems[$zip])) {
                foreach ($sbsItems[$zip] as $sbsVariantKey => $sbsItem) {
                    $itemDetail = $this->getSbsItemDetail($sbsVariantKey, $items);
                    if (!blank($itemDetail) && (!in_array($itemDetail->lineItemName, $insertedNames)) && (!in_array($itemDetail->id, $insertedIds))) {
                        $insertedNames[] = $itemDetail->lineItemName;
                        $insertedIds[] = $itemDetail->id;
                        $orderWidget[$zip]['items'][] = $itemDetail->originalPiecesOfLineItem . ' X ' . $itemDetail->lineItemName;
                    }
                }
            } else {
                if ((!in_array($item->id, $insertedIds))) {
                    $insertedIds[] = $item->id;
                    $orderWidget[$zip]['items'][] = $item->originalPiecesOfLineItem . ' X ' . $item->lineItemName;
                }
            }


            /*
            * Item Accessorials */
            $addedHazmat = false;
            if (isset($orderWidget[$zip]['accessories'])) {
                $addedHazmat = in_array('Hazardous Material', $orderWidget[$zip]['accessories']);
            }
            $oldAccessorial = $orderWidget[$zip]['accessories'] ?? [];
            $orderWidget[$zip]['accessories'] = [];
            if (!$isMulti) {
                if ($carrierHasInsurance) {
                    if (isset($item->product_insurance_active) && $item->product_insurance_active == 1) {
                        array_push($orderWidget[$zip]['accessories'], 'Insurance');
                        $addedInsurance = true;
                    } else if ($addedInsurance) {
                        array_push($orderWidget[$zip]['accessories'], 'Insurance');
                    }
                }
                if (isset($item->isHazmatLineItem) && $item->isHazmatLineItem == 'Y') {
                    array_push($orderWidget[$zip]['accessories'], 'Hazardous Material');
                    $addHazmat = true;
                } else if ($addHazmat) {
                    array_push($orderWidget[$zip]['accessories'], 'Hazardous Material');
                }
            } else {
                if ((isset($item->product_insurance_active) && $item->product_insurance_active == 1 && $carrierHasInsurance) || in_array('Insurance', $oldAccessorial)) {
                    array_push($orderWidget[$zip]['accessories'], 'Insurance');
                }
                if ((isset($item->isHazmatLineItem) && $item->isHazmatLineItem == 'Y') || $addedHazmat) {
                    array_push($orderWidget[$zip]['accessories'], 'Hazardous Material');
                    $addHazmat = true;
                }
            }

            // TODO:need to change implementation of this function
            $isSmall = $this->isSmallQuote($sName) || $isSmallrate;
            if ($isMulti) {
                strpos(strtolower($code), '+r') ? array_push($orderWidget[$zip]['accessories'], 'Residential Delivery') : '';
            } else {
                $autoResidentialsStatus != 'n' ? array_push($orderWidget[$zip]['accessories'], 'Residential Delivery') : '';
            }

            $isHAT ? array_push($orderWidget[$zip]['accessories'], 'Hold At Terminal') : '';
            if (!$isSmall) {
                $residentialsPickup != 'n' ? array_push($orderWidget[$zip]['accessories'], 'Residential Pickup') : '';
                $liftGateStatus != 'n' ? array_push($orderWidget[$zip]['accessories'], 'Lift Gate Delivery') : '';
            }
            $count++;
        }
        /*
         * Added For Catering items that ship as SHippping Group*/
        $itemsWithShipGroup = collect($items)->where('shipping_group', '!=', null)->all();
        if (!blank($itemsWithShipGroup)) {
            $itemsForm = [];
            foreach ($itemsWithShipGroup as $item) {
                $itemsForm[] = $item->originalPiecesOfLineItem . ' X ' . $item->lineItemName;
            }
            foreach ($orderWidget as $key => $data) {
                $items = data_get($data, 'items');
                if (count($orderWidget) > 1) {
                    if ($data['locationtype'] == "Warehouse") {
                        $items = array_merge($items, $itemsForm);
                    }
                } else {
                    $items = array_merge($items, $itemsForm);
                }
                $orderWidget[$key]['items'] = $items;
            }
        }
        $sbs = '';
        //print_r($orderWidget); exit;
        $resp = [
            'widget' => $this->objectToArray($orderWidget),
            'sbs' => $sbs
        ];
        return $resp;
    }

    public function getSbsItemDetail($sbsItemKey, $items)
    {
        return $items->$sbsItemKey ?? [];
    }


    public function shippingGroupOrderWidget($data, $order)
    {
        $orderWidget = ShippingGroup::shippingGroupOrderWidget($data, $order);
        $resp = [
            'widget' => $this->objectToArray($orderWidget)
        ];
        return $resp;
    }

    public function getLiftResidentialStatus($requestToWS, $isSmallrate, $isSmallLtlrate, $rateId)
    {
        //dd($isSmallLtlrate);
        $response = ['resi' => 'n', 'liftG' => 'n', 'resiPickup' => 'n'];
        /*if($isSmallrate && !$isSmallLtlrate){
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
        }*/
        $response['resi'] = strpos($rateId, '+r') ? 'Y' : 'n';
        $response['liftG'] = strpos($rateId, '+lg') ? 'Y' : 'n';
        $response['resiPickup'] = strpos($rateId, '+pu') ? 'Y' : 'n';
        return $response;
    }

    public function getBoxName($binId, $store_id, $rate_id, $cart_id)
    {
        $nickname = BoxSize::getBoxNicknameAndFee($binId);
        return $nickname->nickname ?? null;

        /*     $data = RequestData::where('rate_id', $rate_id)
                 ->where('cart_id', $cart_id)
                 ->where('store_id', $store_id)
                 ->first()->toArray();
             $binId = (int)$binId;
             $bins = json_decode($data['box_bins']);
             if (!empty($bins)) {
                 foreach ($bins as $bin) {
                     if ($binId == $bin->id) {
                         return $bin->nickname;
                     }
                 }
             }*/
    }

    public function objectToArray($orderWidget)
    {
        $resp = [];
        foreach ($orderWidget as $widget) {
            $resp[] = $widget;
        }
        return $resp;
    }

    public function getBCOrderByID($request)
    {
        $store = Store::where('hash', $request['store_hash'])->first();
        if (empty($store)) {
            return [];
        }
        $headers[] = 'X-Auth-Token: ' . $store->access_token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = "https://api.bigcommerce.com/stores/" . $request['store_hash'] . "/v2/orders/" . $request['order_id'];
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);
        $resp = [];
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $resp = json_decode($response['response'], true);
            $endpoint = json_decode($response['response'])->shipping_addresses->url;
            $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', true);
            if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
                $endpoint = json_decode($response['response'])[0]->shipping_quotes->url;
                $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', true);
                if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
                    $response = json_decode($response['response']);
                    $resp['rate_id'] = $response->rate_id;
                    $resp['full_rate_id'] = $response->shipping_provider_quote->rateId ?? '';
                    $resp['shipping_name'] = $response->shipping_provider_quote->name ?? '';
                    $resp['shipping_rate'] = $response->shipping_provider_quote->rate->value ?? '';
                }
            }
        }
        return $resp;
    }

    public function getBCOrders($request)
    {
        $store = Store::where('hash', $request['store_hash'])->first();
        if (empty($store)) {
            return null;
        }
        $page = $request['page'] ?? 1;
        $perPage = $request['perpage'] ?? 50;
        $status = $request['status'] ?? '';
        $sortProd = (isset($request['sortOrder']) && $request['sortOrder'] === "true") ? 'desc' : 'asc';
        //dd($status);
        $search = (int)$request['search'] ?? 0;
        $headers[] = 'X-Auth-Token: ' . $store->access_token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $total = 1;
        if ($search) {
            if ($status == '') {
                $endpoint = "https://api.bigcommerce.com/stores/" . $request['store_hash'] . "/v2/orders/" . $search;
            } else {
                $endpoint = "https://api.bigcommerce.com/stores/" . $request['store_hash'] . "/v2/orders/" . $search . "?status_id=" . $status;
            }

        } else {
            if ($status == '') {
                $countEndPoint = "https://api.bigcommerce.com/stores/" . $request['store_hash'] . "/v2/orders/count";
            } else {
                $countEndPoint = "https://api.bigcommerce.com/stores/" . $request['store_hash'] . "/v2/orders/count?status_id=" . $status;
            }
            $response = $this->curlRequest->enSingleCurlRequest($countEndPoint, [], $headers, 'GET', false);
            $total = (int)ceil(json_decode($response['response'])->count);

            if ($status !== '') {
                $endpoint = "https://api.bigcommerce.com/stores/" . $request['store_hash'] . "/v2/orders?sort=id:" . $sortProd . "&status_id=" . $status . "&limit=" . $perPage . "&page=" . $page;
            } else {
                /*$endpoint = "https://api.bigcommerce.com/stores/".$request['store_hash']."/v2/orders?sort=id:desc&limit=".$perPage."&page=".$page;*/
                $endpoint = "https://api.bigcommerce.com/stores/" . $request['store_hash'] . "/v2/orders?sort=id:" . $sortProd . "&limit=" . $perPage . "&page=" . $page;
            }

        }
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);

        $resp = [];
        $resp['allOrders'] = [];

        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $response = json_decode($response['response'], true);
            //dd($endpoint,$response);
            if (!(isset($response[0]['status']) && $search)) {
                $orders = $search ? [$response] : $response;

                // $orders = $sortProd ==='desc' ? $orders : array_reverse($orders);

                $count = 0;
                if ($orders) {
                    foreach ($orders as $count => $order) {
                        //$count++;
                        $resp['allOrders'][$count]['id'] = $order['id'];
                        $resp['allOrders'][$count]['customer'] = $order['billing_address']['first_name'] . ' ' . $order['billing_address']['last_name'];
                        $resp['allOrders'][$count]['date_created'] = date("m/d/Y", strtotime($order['date_created']));
                        $resp['allOrders'][$count]['status'] = $order['status'];
                        $resp['allOrders'][$count]['total_inc_tax'] = '$' . number_format((float)$order['total_inc_tax'], 2);
                        $resp['allOrders'][$count]['items_total'] = $order['items_total'];
                    }
                }
            }
        }

        $resp['meta'] = [
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
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Models\Order $order
     * @return \Illuminate\Http\Response
     */
    public function show(Order $order)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param \App\Models\Order $order
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
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Order $order
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
     * @param \App\Models\Order $order
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
            print_r($products);
            exit;
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

    /***
     * @param $toRequest
     * Move row from request_temp to request table after order placing
     * delete all rows from request_temp relevant to cart_id
     */
    public function moveQuotesTempToReq($toRequest)
    {
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
                    $response = json_decode($response['response']);
                    $rateId = optional($response)->rate_id ?? null;
                    /*
                     * Added this if in case of rate ID characters exceed 36
                     * Big commerce truncate other characters
                     * That was the issue reported in Qa for Fedex SMall Testing
                     *
                     * */
                    $fullRateId = optional($response)->shipping_provider_quote->rateId ?? null;
                    $reqData = optional(RequestTempData::where('rate_id', $rateId)->where('cart_id', $cartId)->get())->toArray();
                    if (blank($reqData)) {
                        $reqData = optional(RequestTempData::where('rate_id', $fullRateId)->where('cart_id', $cartId)->first())->toArray();
                    }
                    Log::info('Orderdata $reqData: ' . json_encode($reqData) . ' RateID: ' . $rateId . ' CartId: ' . $cartId);
                    if (!blank($reqData)) {
                        unset($reqData['id']);
                        RequestData::insert($reqData);
                    }

                    // RequestTempData::where('cart_id', $cartId)->delete();
                }
            }
        }
    }

    private function isSmallQuote($quote)
    {
        $quote = explode('(', $quote)[0];
        $quote = trim($quote);
        $small = [
            'UPS Ground',
            'UPS 3 Day Select',
            'UPS 2nd Day Air',
            'UPS 2nd Day Air Saver',
            'UPS Next Day Air Saver',
            'UPS Next Day Air',
            'UPS Next Day Air Early',
            'Fedex Ground',
            'UPS 2nd Day Air A.M.',
            'UPS Next Day Air Early A.M.',
        ];
        return in_array($quote, $small);
    }

    private function hasInsureCarrier($code)
    {
        $insureCarriers = ['wweltl', 'parcel_12wwe', 'parcel_12ups', 'parcel_12fd', 'parcel_12uniship'];
        foreach ($insureCarriers as $insureCarrier) {
            if (strpos($code, $insureCarrier) !== false) {
                return true;
            }
        }
        return false;
    }

}
