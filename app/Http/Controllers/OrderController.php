<?php

namespace App\Http\Controllers;

use App\Constants\Constant;
use App\CurlRequest;
use App\CustomClasses\Functions;
use App\CustomClasses\PalletPackaging;
use App\Models\BoxSize;
use App\Models\DBSC\DbscShippingProfile;
use App\Models\Locations;
use App\Models\Orders;
use App\Models\RequestData;
use App\Models\RequestTempData;
use App\Models\ShippingGroup;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
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

    public function getOrderWidget(Request $request, $reportingFlag = false)
    {
        try {
            $order = $this->getBCOrderByID($request);
            if (empty($order)) {
                return response()->json(['error' => true,
                    'data' => [],
                    'message' => 'No Order Found',
                ], 404);
            }
            $orderWidget = $this->createOrderWidget($request, $order, $reportingFlag);
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
                'data' => [],
                'debug' => [$exception->getMessage(), $exception->getFile(), $exception->getLine()],
                'message' => 'No Order Widget Found',
            ], 404);
        }
    }

    public function formateItems($oldItems, $items)
    {
        foreach ($items as $key => $item) {
            $variant_id = $item->variant_id;
            $oldItems->$variant_id = $item;
            $oldItems->$key = $item;
        }
        return $oldItems;
    }


    /**
     * @param $oldItems
     * @param $items
     * @return object
     */
    public function newFormatItems($oldItems, $items)
    {
        $formItems = [];
        foreach ($items as $key => $item) {
            $variant_id = $item->variant_id;
            if (isset($formItems[$variant_id]) && isset($oldItems->$key)) {
                $formItems[$variant_id]->itemQuantity = $formItems[$variant_id]->itemQuantity + $item->piecesOfLineItem;
            } elseif (isset($formItems[$variant_id]) && !isset($oldItems->$key)) {
                $formItems[$variant_id]->itemQuantity = $item->originalPiecesOfLineItem;
            } else {
                $item->itemQuantity = $item->piecesOfLineItem;
                $formItems[$variant_id] = $item;
            }

        }
        return (object)$formItems;
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

    public function getRequestDataFromDB($tableName, $request, $rateId, $cartId, $order)
    {
        $modelName = $tableName === 'RequestData' ? new RequestData() : new RequestTempData();
        $source = $order['order_source'] ?? "www";

        $data = optional($modelName::where('rate_id', $rateId)
            ->where('cart_id', $cartId)
            ->where('store_id', $request['store_id'])
            ->first())->toArray() ?? null;

        if (blank($data) && !blank($order['full_rate_id'])) {
            $data = optional($modelName::where('rate_id', $order['full_rate_id'])
                ->where('cart_id', $cartId)
                ->where('store_id', $request['store_id'])
                ->first())->toArray() ?? null;
        }

        // Get data for draft order from DB
        if (blank($data) && $source === "manual") {
            $data = optional($modelName::where('rate_id', $rateId)
                ->where('store_id', $request['store_id'])
                ->where('is_draft_order', 1)
                ->first())->toArray() ?? null;
            if (blank($data) && !blank($order['full_rate_id'])) {
                $data = optional($modelName::where('rate_id', $order['full_rate_id'])
                    ->where('store_id', $request['store_id'])
                    ->where('is_draft_order', 1)
                    ->first())->toArray() ?? null;
            }
        }
        return $data;
    }

    public function createOrderWidget($request, $order, $reportingFlag)
    {
        $rateId = $order['rate_id'] ?? null;
        $cartId = $order['cart_id'] ?? null;

        $data = $this->getRequestDataFromDB('RequestData', $request, $rateId, $cartId, $order);

        if (blank($data)) {
            $data = $this->getRequestDataFromDB('RequestTempData', $request, $rateId, $cartId, $order);

            if (!blank($data)) {
                unset($data['id']);
                RequestData::insert($data);

            } else {
                return [];
            }
        }

        $rateId = str_contains($rateId, 'idx+') ? $rateId : $order['full_rate_id'];
        $carrierHasInsurance = $this->hasInsureCarrier($rateId);
        $index = explode('idx+', $rateId);
        if (is_string($index[0]) && $index[0] == "shippingGroup") {
            return $this->shippingGroupOrderWidget($data, $order);
        }
        // DBSC order widget
        if (is_string($index[0]) && strpos($index[0], 'dbsc') !== false) {
            return $this->dbscOrderWidget($data, $order);
        }

        $index = explode('idx+', $rateId)[1];

        if (!empty($index)) {
            $index = strlen($index) <= 11 ? (int)substr($index, 0, 1) : (int)substr($index, 0, 2);
            // $index = (int)substr($index, 0, 1);
        }
        $isSmallLtlrate = substr($rateId, 0, 5) == 'multi' ? true : false;
        $isHAT = strpos(strtolower($rateId), '+hat');
        $insideDelivery = strpos($rateId, '+ID') ? 'Y' : 'n';
        $notifyBeforeDelivery = strpos($rateId, '+NBD') ? 'Y' : 'n';
        $LimitedAccessDel = strpos($rateId, '+LAD') ? 'Y' : 'n';
        $isTruckLoad = strpos($rateId, '+TL') ? 'Y' : 'n';
        $isFreightTruckLoad = strpos($rateId, '+FLGTL') ? 'Y' : 'n';
        $isTwoManDel = strpos($rateId, Functions::$twoManDelAccess) ? 'Y' : 'n';
        $isAppointmentDel = strpos($rateId, Functions::$appointmentDelAccess) ? 'Y' : 'n';
        $rateId = strtolower($rateId);
        $isInspOrLocal = substr($rateId, 0, 4) == 'insp' || substr($rateId, 0, 6) == 'locdel';
        $isSmallrate = substr($rateId, 0, 9) == 'parcel_12' || substr($rateId, 0, 5) == 'multi' ? true : false;
        $isLG = strpos($rateId, '+lg') != false;
        $isOwnArrangement = strpos($rateId, 'own_arrangement') === 0 || strpos($rateId, 'freernlltl') === 0 ? true : false;
        $isLtlRate = $isSmallLtlrate || (substr($rateId, 0, 9) != 'parcel_12') || (strpos($rateId, 'ltl') != false);
        /*
        * Stored Response from WS */
        $lineItem = json_decode($data['lineitems'])->lineItemData;
        $originalItemsReq = json_decode(json_encode($lineItem->items));
        $responseFromWS = json_decode($data['quotes']);
        $shippingGroupResp = !blank($data['shipping_group_resp']) ? json_decode($data['shipping_group_resp']) : [];

        $requestToWS = json_decode($data['request']);
        // TODO: Need to chenage implementation e.g new FormatItems
        $lineItem->items = $this->formateItems($lineItem->items, $requestToWS->requestArr->commdityDetails);

        $lineItem->origin = $this->formateOrigins($requestToWS->requestArr->carriers);
        $isMultiShipment = false;
        $multiShipmentresponse = $data['multiShipmentresponse'] === '{}' ? null : json_decode($data['multiShipmentresponse']);
        if (!blank($multiShipmentresponse)) {
            $isMultiShipment = true;
        }
        $autoResidentialsStatus = 'n';
        $residentialsPickup = 'n';
        $liftGateStatus = 'n';
        $binPackagingData = '';
        $orderWidget = [];
        $isOneRate = strpos($rateId, '+or');
        $isGround = strpos($rateId, '+gd');
        $isAir = strpos($rateId, '+as');
        $isSimpleRate = strpos($rateId, 'sr_') || strpos($rateId, '+sr');

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
                    $liftGatePickup = $liftResidentialStatus['lgPickup'] ?? 'n';

                    $totalBoxes = 1;
                    if (isset($ws->binPackagingData) && !empty($ws->binPackagingData) && ($isSmallrate)) {
                        if ($isGround) {
                            $sbsData = $ws->binPackagingData->response->ground->bins_packed ?? $ws->binPackagingData->response->bins_packed ?? [];
                        } else if ($isAir) {
                            $sbsData = $ws->binPackagingData->response->air->bins_packed ?? $ws->binPackagingData->response->ground->bins_packed ?? $ws->binPackagingData->response->bins_packed ?? [];
                        } else if ($isOneRate) {
                            $sbsData = $ws->binPackagingData->response->oneRate->bins_packed ?? [];
                        } else if ($isSimpleRate) {
                            $sbsData = $ws->binPackagingData->response->simpleRate->bins_packed ??
                                $ws->binPackagingData->response->ground->bins_packed ?? $ws->binPackagingData->response->bins_packed ?? [];
                        } else {
                            $sbsData = $ws->binPackagingData->response->bins_packed ?? $ws->binPackagingData->response->ground->bins_packed ?? $ws->binPackagingData->response->air->bins_packed ?? $ws->binPackagingData->response->oneRate->bins_packed ?? [];
                        }

                        /* Usps carrier packaging according to boxes types */
                        $customBoxes = $ws->binPackagingData->response->customboxes->bins_packed ?? [];
                        if (!blank($customBoxes)) {
                            $orderWidgetData[] = $this->formatUspsPackaging($customBoxes, $zip, $lineItem);
                        }
                        $upmbBoxes = $ws->binPackagingData->response->upmb->bins_packed ?? [];
                        if (!blank($upmbBoxes)) {
                            $orderWidgetData[] = $this->formatUspsPackaging($upmbBoxes, $zip, $lineItem);
                        }
                        $umebBoxes = $ws->binPackagingData->response->umeb->bins_packed ?? [];
                        if (!blank($umebBoxes)) {
                            $orderWidgetData[] = $this->formatUspsPackaging($umebBoxes, $zip, $lineItem);
                        }
                        $uflatBoxes = $ws->binPackagingData->response->uflat->bins_packed ?? [];
                        if (!blank($uflatBoxes)) {
                            $orderWidgetData[] = $this->formatUspsPackaging($uflatBoxes, $zip, $lineItem);
                        }

                        $itemCount = 0;
                        foreach ($sbsData as $key => $binPacked) {
                            $type = optional($binPacked->bin_data)->type ?? '';
                            $quantity = 1;
                            if ($type == 'item' || $type == 'weight_based') {
                                $type = $binPacked->bin_data->type;
                                $product_id = $binPacked->bin_data->id;
                                $quantity = $binPacked->bin_data->quantity ?? 1;
                                $itemCount++;
                            }
                            $count = 0;
                            $orderWidgetData['type'] = $type;
                            $orderWidgetData['image_complete'] = $binPacked->image_complete;
                            $orderWidgetData['quantity'] = $quantity;
                            /*For Weight Based Products*/
                            if ($type == 'weight_based') {
                                $orderWidgetData['d'] = '';
                                $orderWidgetData['w'] = '';
                                $orderWidgetData['h'] = '';
                                $orderWidgetData['weight'] = $binPacked->bin_data->weight ?? '';
                            } else {
                                $orderWidgetData['d'] = $binPacked->bin_data->d . ' x ';
                                $orderWidgetData['w'] = $binPacked->bin_data->w . ' x ';
                                $orderWidgetData['h'] = $binPacked->bin_data->h;
                            }

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

                    // Pallet packaging order widget
                    if (isset($ws->palletPackagingData) && !empty($ws->palletPackagingData) && $isLtlRate) {
                        $palletPkgResp = (new PalletPackaging())->formatOrderWidget($responseFromWS, $lineItem);

                        if (!empty($palletPkgResp)) {
                            if (empty($orderWidget)) {
                                $orderWidget = $palletPkgResp;
                            } else {
                                $orderWidget[$zip]['pallet'] = $palletPkgResp[$zip]['pallet'];
                            }
                        }
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
        $code = '';

        foreach ($origins as $key => $origin) {
            $item = optional($items)->$key;
            if (blank($item)) {
                continue;
            }
            $isOriginMarkup = isset($origin->origin_markup) && !empty($origin->origin_markup);
            $isProductMarkup = isset($item->product_markup) && !empty($item->product_markup);

            $zip = $origin->locationId != '' ? $origin->locationId : $origin->senderZip;
            $city = $origin->senderCity ? $origin->senderCity . ',' : '';
            $state = $origin->senderState ?? '';
            $senderZip = $origin->senderZip ?? '';
            if (!$isMultiShipment && $isInspOrLocal) {
                $origDetails = $this->getOriginForInsAndLocal($zip);
                if (!blank($origDetails)) {
                    $city = $origDetails['city'] . ',';
                    $state = $origDetails['state'];
                    $senderZip = $origDetails['zip_code'];
                }
            }
            $orderWidget[$zip]['locationtype'] = $item->dropship_enabled == 'N' ? 'Warehouse' : 'Dropship';
            $orderWidget[$zip]['address'] = $city . ' ' . $state . ' ' . $senderZip;
            $orderWidget[$zip]['totalBoxes'] = $totalBoxes ?? 0;
            $sRate = $order['shipping_rate'];

            if ($multiShipmentresponse != null && !empty($multiShipmentresponse) && !$isOwnArrangement) {
                if ($isHAT) {
                    $sRate = $multiShipmentresponse->$index->hat->$zip->rate ?? $multiShipmentresponse->$index->liftgate->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? 0.00;
                    $order['shipping_name'] = $multiShipmentresponse->$index->hat->$zip->title ?? $multiShipmentresponse->$index->liftgate->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? '';
                    $code = $multiShipmentresponse->$index->hat->$zip->code ?? $multiShipmentresponse->$index->liftgate->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? '';
                } else if ($isLG && $insideDelivery == 'Y') {
                    $sRate = $multiShipmentresponse->$index->insideLiftGateDelivery->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? 0.00;
                    $order['shipping_name'] = $multiShipmentresponse->$index->insideLiftGateDelivery->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? '';
                    $code = $multiShipmentresponse->$index->insideLiftGateDelivery->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? '';
                } else if ($isLG && $notifyBeforeDelivery == 'Y') {
                    $sRate = $multiShipmentresponse->$index->lgnotifydelivery->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? 0.00;
                    $order['shipping_name'] = $multiShipmentresponse->$index->lgnotifydelivery->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? '';
                    $code = $multiShipmentresponse->$index->lgnotifydelivery->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? '';
                } else if ($LimitedAccessDel == 'Y' && $isLG) {
                    $sRate = $multiShipmentresponse->$index->limitedaccessLG->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? 0.00;
                    $order['shipping_name'] = $multiShipmentresponse->$index->limitedaccessLG->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? '';
                    $code = $multiShipmentresponse->$index->limitedaccessLG->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? '';
                } else if ($isLG) {
                    $sRate = $multiShipmentresponse->$index->liftgate->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? 0.00;
                    $order['shipping_name'] = $multiShipmentresponse->$index->liftgate->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? '';
                    $code = $multiShipmentresponse->$index->liftgate->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? '';
                } else if ($isFreightTruckLoad == 'Y') {
                    $sRate = $multiShipmentresponse->$index->Truckload->$zip->rate ?? $multiShipmentresponse->$index->liftgate->$zip->rate ?? 0.00;
                    $order['shipping_name'] = $multiShipmentresponse->$index->Truckload->$zip->title ?? $multiShipmentresponse->$index->liftgate->$zip->title ?? '';
                    $code = $multiShipmentresponse->$index->Truckload->$zip->code ?? $multiShipmentresponse->$index->liftgate->$zip->code ?? '';
                } else if ($isTruckLoad == 'Y') {
                    $sRate = $multiShipmentresponse->$index->Truckload->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? $multiShipmentresponse->$index->liftgate->$zip->rate ?? 0.00;
                    $order['shipping_name'] = $multiShipmentresponse->$index->Truckload->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? $multiShipmentresponse->$index->liftgate->$zip->title ?? '';
                    $code = $multiShipmentresponse->$index->Truckload->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? $multiShipmentresponse->$index->liftgate->$zip->code ?? '';
                } else if ($insideDelivery == 'Y') {
                    $sRate = $multiShipmentresponse->$index->insideDelivery->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? 0.00;
                    $order['shipping_name'] = $multiShipmentresponse->$index->insideDelivery->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? '';
                    $code = $multiShipmentresponse->$index->insideDelivery->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? '';
                } else if ($notifyBeforeDelivery == 'Y') {
                    $sRate = $multiShipmentresponse->$index->notifydelivery->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? 0.00;
                    $order['shipping_name'] = $multiShipmentresponse->$index->notifydelivery->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? '';
                    $code = $multiShipmentresponse->$index->notifydelivery->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? '';
                } else if ($LimitedAccessDel == 'Y') {
                    $sRate = $multiShipmentresponse->$index->limitedaccess->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? 0.00;
                    $order['shipping_name'] = $multiShipmentresponse->$index->limitedaccess->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? '';
                    $code = $multiShipmentresponse->$index->limitedaccess->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? '';
                } else if ($isTwoManDel == 'Y' && $isAppointmentDel == 'Y') {
                    $sRate = $multiShipmentresponse->$index->twoManAptDelivery->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? 0.00;
                    $order['shipping_name'] = $multiShipmentresponse->$index->twoManAptDelivery->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? '';
                    $code = $multiShipmentresponse->$index->twoManAptDelivery->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? '';
                } else if ($isTwoManDel == 'Y') {
                    $sRate = $multiShipmentresponse->$index->twoMan->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? 0.00;
                    $order['shipping_name'] = $multiShipmentresponse->$index->twoMan->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? '';
                    $code = $multiShipmentresponse->$index->twoMan->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? '';
                } else if ($isAppointmentDel == 'Y') {
                    $sRate = $multiShipmentresponse->$index->appointment->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? 0.00;
                    $order['shipping_name'] = $multiShipmentresponse->$index->appointment->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? '';
                    $code = $multiShipmentresponse->$index->appointment->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? '';
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

            /** 
             * Add Quote ID
             * */ 
            if (!$isSmallLtlrate && empty($multiShipmentresponse)){
                $orderWidget[$zip]['quoteId'] = Functions::getQuoteId($rateId, $responseFromWS, $zip);
            } elseif (!$isSmallLtlrate && $isMultiShipment) {
                $orderWidget[$zip]['quoteId'] = Functions::getQuoteId($code, $responseFromWS, $zip);
            }

            if (isset($order['shipping_name']) && strpos($order['shipping_name'], '(Expected')){
                $sName = explode('(Expected', $order['shipping_name'])[0] ?? '';
                $sName = explode('w/', $sName)[0] ?? '';
                $estimate = explode('(Expected', $order['shipping_name'])[1] ?? '';
                $sMethod = '(Expected' . $estimate;
            } elseif (isset($order['shipping_name']) && strpos($order['shipping_name'], '(Intransit')){
                $sName = explode('(Intransit', $order['shipping_name'])[0] ?? '';
                $sName = explode('w/', $sName)[0] ?? '';
                $estimate = explode('(Intransit', $order['shipping_name'])[1] ?? '';
                $sMethod = '(Intransit' . $estimate;
            } else {
                $sName = $order['shipping_name'] ?? '';
                $sName = explode('w/', $sName)[0] ?? '';
                $sMethod = '';
            }            

            $orderWidget[$zip]['shipping_method'] = $sName . $sMethod;
            $orderWidget[$zip]['shipping_rate'] = '$' . number_format((float)$sRate, 2,);
            // TODO : Need to change originalPiecesOfLineItem -> itemQuantity
            if (isset($item->shipMultiplePackage) && $item->shipMultiplePackage) {
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
                if (isset($item->id) && (!in_array($item->id, $insertedIds))) {
                    $insertedIds[] = $item->id;
                    $orderWidget[$zip]['items'][] = $item->originalPiecesOfLineItem . ' X ' . $item->lineItemName;
                }
            }


            /*If instore and not multi shipment we are showing only instore and local delivery original items*/
            if (!$isMultiShipment && $isInspOrLocal) {
                $orderWidget[$zip]['items'] = [];
                foreach ($originalItemsReq as $originalItem) {
                    $orderWidget[$zip]['items'][] = $originalItem->originalPiecesOfLineItem . ' X ' . $originalItem->lineItemName;;
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
            /*Added For hazmat and INsurance in case of one box and multi products*/
            if (!empty($sbsItems[$zip])) {
                foreach ($sbsItems[$zip] as $sbsVariant => $sbsItem) {
                    $hazardous = isset($items->$sbsVariant->isHazmatLineItem) && $items->$sbsVariant->isHazmatLineItem == 'Y' ? true : false;
                    $insurance = isset($items->$sbsVariant->product_insurance_active) && $items->$sbsVariant->product_insurance_active == 1 ? true : false;
                    if ($hazardous) {
                        $addHazmat = true;
                        array_push($orderWidget[$zip]['accessories'], 'Hazardous Material');
                    }
                    if ($insurance) {
                        array_push($orderWidget[$zip]['accessories'], 'Insurance');
                    }
                }
            }

            $isSmall = Functions::isSmallCarrier($code);

            if ($isMulti) {
                strpos(strtolower($code), '+r') ? array_push($orderWidget[$zip]['accessories'], 'Residential Delivery') : '';
            } else {
                $autoResidentialsStatus != 'n' ? array_push($orderWidget[$zip]['accessories'], 'Residential Delivery') : '';
            }

            $isProductMarkup ? array_push($orderWidget[$zip]['accessories'], 'Product Markup') : '';
            $isOriginMarkup ? array_push($orderWidget[$zip]['accessories'], 'Origin Markup') : '';

            if (!$isSmall) {
                $residentialsPickup != 'n' ? array_push($orderWidget[$zip]['accessories'], 'Residential Pickup') : '';
                $liftGateStatus != 'n' ? array_push($orderWidget[$zip]['accessories'], 'Lift Gate Delivery') : '';
                $liftGatePickup != 'n' ? array_push($orderWidget[$zip]['accessories'], 'Lift Gate Pickup') : '';
                $insideDelivery != 'n' ? array_push($orderWidget[$zip]['accessories'], 'Inside Delivery') : '';
                $LimitedAccessDel != 'n' ? array_push($orderWidget[$zip]['accessories'], 'Limited Access Delivery') : '';
                $isTruckLoad != 'n' ? array_push($orderWidget[$zip]['accessories'], 'Truck Load Delivery') : '';
                $isFreightTruckLoad != 'n' ? array_push($orderWidget[$zip]['accessories'], 'Truck Load Delivery') : '';
                $isTwoManDel != 'n' ? array_push($orderWidget[$zip]['accessories'], 'Two Man Delivery') : '';
                $isAppointmentDel != 'n' ? array_push($orderWidget[$zip]['accessories'], 'Appointment Delivery') : '';
                $notifyBeforeDelivery != 'n' ? array_push($orderWidget[$zip]['accessories'], 'Notify Before Delivery') : '';
            }
            $orderWidget[$zip]['accessories'] = array_values(array_unique($orderWidget[$zip]['accessories']));
            $count++;

        }

        if ($reportingFlag) {
            $reportData = $this->bcReportingData($request, $order, $data, $isMulti, $orderWidget, $zip);
            $reportDataResp = $this->curlRequest->reportingDataCurlRequest($reportData);
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
                $items = data_get($data, 'items') ?? [];
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

        $fdoShipmenst = json_decode($data['fdo_shipments_data'], true) ?? [];

        $sbs = '';
        $resp = [
            'widget' => $this->objectToArray($orderWidget),
            'sbs' => $sbs,
            'fdoShipments' =>$fdoShipmenst, 
        ];
        return $resp;
    }


    public function getOriginForInsAndLocal($locationId)
    {
        return Locations::getlocationDetail($locationId);
    }

    public function getSbsItemDetail($sbsItemKey, $items)
    {
        return $items->$sbsItemKey ?? [];
    }


    public
    function shippingGroupOrderWidget($data, $order)
    {
        $orderWidget = ShippingGroup::shippingGroupOrderWidget($data, $order);
        $resp = [
            'widget' => $this->objectToArray($orderWidget)
        ];
        return $resp;
    }

    public function dbscOrderWidget($data, $order)
    {
        $orderWidget = DbscShippingProfile::makeOrderWidget($data, $order);
        $resp = ['widget' => $this->objectToArray($orderWidget)];

        return $resp;
    }

    public
    function getLiftResidentialStatus($requestToWS, $isSmallrate, $isSmallLtlrate, $rateId)
    {
        $response = ['resi' => 'n', 'liftG' => 'n', 'resiPickup' => 'n', 'lgPickup' => 'n'];

        $response['resi'] = strpos($rateId, '+r') ? 'Y' : 'n';
        $response['liftG'] = strpos($rateId, '+lg') ? 'Y' : 'n';
        $response['resiPickup'] = strpos($rateId, '+pu') ? 'Y' : 'n';
        $response['lgPickup'] = strpos($rateId, '+lfgpu') ? 'Y' : 'n';
        return $response;
    }

    public
    function getBoxName($binId, $store_id, $rate_id, $cart_id)
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

    public
    function objectToArray($orderWidget)
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

    public function formatUspsPackaging($binPackagingData, $zip, $lineItem): array
    {
        $sbsData = $binPackagingData ?? [];
        $itemCount = 0;
        $orderWidget = [];

        foreach ($sbsData as $key => $binPacked) {
            $type = optional($binPacked->bin_data)->type ?? '';
            $quantity = 1;
            if ($type == 'item' || $type == 'weight_based') {
                $type = $binPacked->bin_data->type;
                $product_id = $binPacked->bin_data->id;
                $quantity = $binPacked->bin_data->quantity ?? 1;
                $itemCount++;
            }
            $count = 0;
            $orderWidgetData['type'] = $type;
            $orderWidgetData['image_complete'] = $binPacked->image_complete;
            $orderWidgetData['quantity'] = $quantity;
            /*For Weight Based Products*/
            if ($type == 'weight_based') {
                $orderWidgetData['d'] = '';
                $orderWidgetData['w'] = '';
                $orderWidgetData['h'] = '';
                $orderWidgetData['weight'] = $binPacked->bin_data->weight ?? '';
            } else {
                $orderWidgetData['d'] = $binPacked->bin_data->d . ' x ';
                $orderWidgetData['w'] = $binPacked->bin_data->w . ' x ';
                $orderWidgetData['h'] = $binPacked->bin_data->h;
            }

            $orderWidgetData['nickname'] = $this->getBoxName($binPacked->bin_data->id, '', '', '');
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

        return $orderWidget;
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public
    function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public
    function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Models\Order $order
     * @return \Illuminate\Http\Response
     */
    public
    function show(Order $order)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param \App\Models\Order $order
     * @return \Illuminate\Http\Response
     */
    public
    function edit(Orders $order, Request $request)
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
    public
    function update(Request $request, Orders $order)
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
    public
    function destroy(Orders $order)
    {
        //
    }

    public function orderFromWebhook(Request $request)
    {
        try {
            $postData = file_get_contents("php://input");
            $postData = json_decode($postData, true);
            return $this->orderWebhookProcess($request, $postData);
        } catch (\Exception $exception) {
            Log::info('Exception On Moving Quotes ' . json_encode($exception->getTraceAsString()));
            return response()->json(true, 200);
        }
    }

    /**
     * Executes Order process for moving data from request_temp table to request
     * @param $request
     * @param $postData
     * @return JsonResponse|null
     */
    public function orderWebhookProcess($request, $postData)
    {
        $storeHash = explode('/', $postData['producer']);
        $storeHash = $storeHash[1];
        Log::info('Order Webhook Data From BigCommerce ' . json_encode($postData));
        $orderId = $postData['data']['id'] ?? $postData['data']['order_id'];
        // Update,delete,create from  webhook
        $scope = $postData['scope'];
        $store = Store::where('hash', $storeHash)->first();
        //allow only create/update orders actions
        $onlyScopes = ['store/order/created', 'store/order/updated'];
        if (empty($store) || !in_array($scope, $onlyScopes)) {
            return null;
        }
        $toRequest['store_id'] = $store->id;
        $toRequest['store_name'] = $store->name;
        $toRequest['store_hash'] = $storeHash;
        $toRequest['order_id'] = $orderId;
        $this->accessToken = $store->access_token;
        $this->storeHash = $storeHash;
        $this->moveQuotesTempToReq($toRequest, $request);
        return response()->json(true, 200);
    }

    public
    function setOrderMeta($toRequest, $orderMetaFields, $updateWidgetId)
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

    public
    function saveUpdateOrderByID($toRequest)
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

    public
    function orderSettings($toRequest)
    {
        $order = $this->getBCOrderByID($toRequest);
        return $this->getBCOrderProducts($order['products']['url']);
    }

    public
    function getBCOrderProducts($productsUrl)
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
        }
        return $prds;
    }

    public
    function prdCustomFeilds($productdId)
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
    public function moveQuotesTempToReq($toRequest, $request)
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
                    $reqData = optional(RequestTempData::where('rate_id', $rateId)->where('cart_id', $cartId)->first())->toArray();

                    if (blank($reqData)) {
                        $reqData = optional(RequestTempData::where('rate_id', $fullRateId)->where('cart_id', $cartId)->first())->toArray();
                    }

                    if (blank($reqData)) {
                        $reqData = optional(RequestTempData::where('rate_id', $fullRateId)->where('store_id', $toRequest['store_id'])->latest()->first())->toArray();
                    }
                    Log::info('Order Data DB: ' . json_encode($reqData) . ' RateID: ' . $rateId . ' CartId: ' . $cartId);
                    if (!blank($reqData)) {
                        unset($reqData['id']);
                        RequestData::insert($reqData);
                        // TODO :  Need to check why we are doing this
//                        $request['store_name'] = $toRequest['store_name'];
//                        $request['store_id'] = $toRequest['store_id'];
//                        $request['store_hash'] = $toRequest['store_hash'];
//                        $request['order_id'] = $toRequest['order_id'];
//                        $this->getOrderWidget($request, true);
                    }
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
            'Saturday - UPS Next Day Air',
            'Saturday - UPS Next Day Air Early A.M.',
            'Saturday - UPS 2nd Day Air',
        ];
        return in_array($quote, $small);
    }

    private function hasInsureCarrier($code)
    {
        $insureCarriers = ['wweltl', 'parcel_12wwe', 'parcel_12ups', 'parcel_12fd', 'parcel_12uniship', 'parcel_12shipEng'];
        foreach ($insureCarriers as $insureCarrier) {
            if (strpos($code, $insureCarrier) !== false) {
                return true;
            }
        }
        return false;
    }

    private function bcReportingData($request, $OrderData, $dbData, $isMulti, $orderWidget, $zip)
    {
        $multiShipmentresponse = json_decode($dbData['multiShipmentresponse']) ?? [];
        $rateId = $OrderData['rate_id'] ?? '';
        $ratecode = [];

        if ($isMulti) {
            foreach ($multiShipmentresponse as $mul => $shipment) {
                foreach ($shipment as $key => $quote) {
                    if (strpos($rateId, 'LG') !== false && $key === 'liftgate') {
                        foreach ($quote as $loc => $code) {

                            $ratecode[$loc] = $code->code;
                        }
                    } else if (strpos($rateId, 'HAT') !== false && $key === 'hat') {
                        foreach ($quote as $loc => $code) {
                            $ratecode[$loc] = $code->code;
                        }
                    } else if (strpos($rateId, 'NBD') !== false && $key === 'notifydelivery') {
                        foreach ($quote as $loc => $code) {
                            $ratecode[$loc] = $code->code;
                        }
                    } else if (strpos($rateId, 'LGNBD') !== false && $key === 'lgnotifydelivery') {
                        foreach ($quote as $loc => $code) {
                            $ratecode[$loc] = $code->code;
                        }
                    } else if (strpos($rateId, 'ID') !== false && $key === 'insideDelivery') {
                        foreach ($quote as $loc => $code) {
                            $ratecode[$loc] = $code->code;
                        }
                    } else if (strpos($rateId, 'LGID') !== false && $key === 'insideLiftGateDelivery') {
                        foreach ($quote as $loc => $code) {
                            $ratecode[$loc] = $code->code;
                        }
                    } else if (strpos($rateId, 'LAD') !== false && $key === 'limitedaccess') {
                        foreach ($quote as $loc => $code) {
                            $ratecode[$loc] = $code->code;
                        }
                    } else if (strpos($rateId, 'LGLAD') !== false && $key === 'limitedaccessLG') {
                        foreach ($quote as $loc => $code) {
                            $ratecode[$loc] = $code->code;
                        }
                    } else if (strpos($rateId, 'TL') !== false && $key === 'Truckload') {
                        foreach ($quote as $loc => $code) {
                            $ratecode[$loc] = $code->code;
                        }
                    } else {
                        foreach ($quote as $loc => $code) {
                            $ratecode[$loc] = $code->code;
                        }
                    }
                    break;
                }
                break;
            }
        }

        if (empty($ratecode)) {
            $ratecode[$zip] = $rateId ?? '';
        }

        $carrierCodes = ['wweltl', 'rnlltl', 'xpoltl', 'fedexltl', 'gtzltl', 'cltl', 'upsltl', 'fqltl', 'tqlltl', 'yrcltl', 'odflltl', 'dayrossltl', 'fqchrltl', 'estesltl', 'echoltl', 'saialtl', 'abfltl', 'daylightltl', 'SouthEastern', 'parcel_12wwe', 'parcel_12ups', 'parcel_12fd', 'parcel_12uniship', 'parcel_12Purolator', 'parcel_12usps'];
        $orderMeta = [];
        $serviceId = 0;
        foreach ($carrierCodes as $key => $code) {
            foreach ($ratecode as $loc_code => $rateId) {
                if (strpos($rateId, $code) !== false) {
                    $carrierName[$loc_code] = Functions::getCarrierName($code) ?? '';
                    $carrierType[$loc_code] = Functions::isSmallCarrier($code) ? 'small' : 'ltl';
                }
            }
        }

        $lineItems = json_decode($dbData['lineitems'])->lineItemData ?? [];
        $origins = $lineItems->origin;
        $items = $lineItems->items;

        foreach ($orderWidget as $key => $owrate) {
            foreach ($owrate['accessories'] as $data) {
                $accessorials[$data] = true;
            }

            foreach ($origins as $or => $origin) {
                if ($origin->locationId == $key) {
                    $orderMeta['plugin_type'] = $carrierType[$key] ?? '';
                    $orderMeta['plugin_name'] = $carrierName[$key] ?? '';
                    $orderMeta['accessorials'] = $accessorials ?? [];
                    $orderMeta['items'][] = $items->$or ?? [];
                    $orderMeta['address'] = $origin ?? [];
                    $orderMeta['receiver_address'] = $lineItems->destination ?? [];
                    $rate['locationtype'] = $owrate['locationtype'] ?? '';
                    $rate['label'] = $owrate['shipping_method'] ?? '';
                    $rate['cost'] = $owrate['shipping_rate'] ?? null;
                    $orderMeta['rate'] = $rate ?? [];

                }
            }

            $orders[] = [
                'orderId' => $OrderData['id'] ?? null,
                'carrierName' => $carrierName[$key] ?? '',
                'serviceId' => $serviceId ?? 0,
                'shipmentType' => $isMulti ? 'multiple' : 'single',
                'serviceName' => '', // service description
                'serviceCharge' => $owrate['shipping_rate'] ?? null,
                'orderCreatedDate' => $OrderData['date_created'] ?? '',

                'orderMeta' => base64_encode(json_encode($orderMeta)), // json data encoded with base 64
            ];
            unset($orderMeta, $accessorials);
            $serviceId++;

        }

        $data = [
            'serverName' => $request['store_name'] ?? '',
            'licenseKey' => 'V1T9Z7QY-X357RURI-01MMZZ3W-O0TOJAQG',
            'platform' => 'bigcommerce',
            'currencyUnit' => $OrderData['currency_code'] ?? '',

            'orders' => $orders ?? [],
        ];

        return $data;
    }

}
