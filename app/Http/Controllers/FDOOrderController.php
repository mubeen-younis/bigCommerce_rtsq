<?php

namespace App\Http\Controllers;

use App\Constants\Constant;
use App\CurlRequest;
use App\CustomClasses\Functions;
use App\Endpoints\Endpoints;
use App\Helpers\Helpers;
use App\Models\RequestData;
use App\Models\RequestTempData;
use App\Models\Store;
use Illuminate\Http\Request;
use App\Models\ShippingRule;

class FDOOrderController extends Controller
{
    private $curlRequest;

    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
    }

    public function getOrderDetails(Request $request, $orderId)
    {
        try {
            $storeHash = $request->header('store-hash') ?? null;
            if (blank($storeHash) || blank($orderId)) {
                return Helpers::sendJsonResponseFdo(true, 'Store hash and order id required');

            }
            $order = $this->getBCOrderByID($storeHash, $orderId);
            if (blank($order['order_detail'])) {
                return Helpers::sendJsonResponseFdo(true, 'No Order Detail Found From BigCommerce');
            }
            $orderDetail = $this->getDetail($order);
            if (empty($orderDetail)) {
                return Helpers::sendJsonResponseFdo(true, 'No order detail found from DB');
            }
            return Helpers::sendJsonResponseFdo(false, '', $orderDetail);
        } catch (\Exception $exception) {
            return Helpers::sendJsonResponseFdo(true, 'Something went wrong', ['exception' => $exception->getMessage(),
                'line' => $exception->getLine()]);
        }
    }


    public function getBCOrderByID($storeHash, $orderId): array
    {
        $store = Store::where('hash', $storeHash)->first();
        if (blank($store)) {
            return [];
        }
        $endpoint = Endpoints::getBCComEndpoint() . $storeHash . "/v2/orders/" . $orderId;
        $headers = $this->getHeaders($store->access_token);
        $bcResponse = $this->getOrderResponseFromBC($endpoint, $headers);
        return ['order_detail' => $bcResponse, 'store_id' => $store->id];

    }

    public function getHeaders($accessToken)
    {
        $headers[] = 'X-Auth-Token: ' . $accessToken;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        return $headers;
    }

    public function getOrderResponseFromBC($endpoint, $headers)
    {
        $resp = [];
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $resp = json_decode($response['response'], true);
            $endpoint = json_decode($response['response'])->shipping_addresses->url;
            $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', true);
            if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
                $endpoint = json_decode($response['response'])[0]->shipping_quotes->url;
                $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', true);
                if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
                    $response = json_decode($response['response']);
                    if (blank($response)) {
                        return [];
                    }
                    $resp['rate_id'] = $response->rate_id;
                    $resp['full_rate_id'] = $response->shipping_provider_quote->rateId ?? '';
                    $resp['shipping_name'] = $response->shipping_provider_quote->name ?? '';
                    $resp['shipping_rate'] = $response->shipping_provider_quote->rate->value ?? '';
                }
            }
        }
        return $resp;
    }

    public function getRequestDataFromDB($tableName, $storeId, $rateId, $cartId, $order)
    {
        $modelName = $tableName === 'RequestData' ? new RequestData() : new RequestTempData();
        $source = $order['order_source'] ?? "www";

        $data = optional($modelName::where('rate_id', $rateId)
            ->where('cart_id', $cartId)
            ->where('store_id', $storeId)
            ->first())->toArray() ?? null;
        if (blank($data) && isset($order['full_rate_id']) && !blank($order['full_rate_id'])) {
            $data = optional($modelName::where('rate_id', $order['full_rate_id'])
                ->where('cart_id', $cartId)
                ->where('store_id', $storeId)
                ->first())->toArray() ?? null;
            $rateId = $order['full_rate_id'] ?? null;
        }

         // Get data for draft order from DB
         if (blank($data) && $source === "manual") {
            $data = optional($modelName::where('rate_id', $rateId)
                ->where('store_id', $storeId)
                ->where('is_draft_order', 1)
                ->first())->toArray() ?? null;
            if (blank($data) && !blank($order['full_rate_id'])) {
                $data = optional($modelName::where('rate_id', $order['full_rate_id'])
                    ->where('store_id', $storeId)
                    ->where('is_draft_order', 1)
                    ->first())->toArray() ?? null;
            }
        }
        return $data;
    }

    public function getDetail($detail)
    {
        $storeId = $detail['store_id'];
        $order = $detail['order_detail'];
        $rateId = $order['rate_id'] ?? null;
        $cartId = $order['cart_id'] ?? null;

        $data = $this->getRequestDataFromDB('RequestData', $storeId, $rateId, $cartId, $order);

        if (blank($data)) {
            $data = $this->getRequestDataFromDB('RequestTempData', $storeId, $rateId, $cartId, $order);

            if (!blank($data)) {
                unset($data['id']);
                RequestData::insert($data);

            } else {
                return [];
            }
        }

        $rateId = str_contains($rateId, 'idx+') ? $rateId : $order['full_rate_id'];
        $carrierHasInsurance = Functions::hasInsureCarrier($rateId);
        $carrierName = Functions::getCarrierNameOrCode($rateId);

        $isSmall = Functions::isSmallCarrier($rateId);
        $wsCarrierCode = Functions::getCarrierNameOrCode($rateId, 1);

        $index = explode('idx+', $rateId)[1];
        if (!empty($index)) {
            $index = (int)substr($index, 0, 1);
        }
        $isSmallLtlrate = substr($rateId, 0, 5) == 'multi' ? true : false;
        $isHAT = strpos(strtolower($rateId), '+hat');
        $rateId = strtolower($rateId);
        $isSmallrate = substr($rateId, 0, 9) == 'parcel_12' || substr($rateId, 0, 5) == 'multi' ? true : false;
        $isLG = strpos($rateId, '+lg');
        $isOwnArrangement = strpos($rateId, 'own_arrangement') === 0 || strpos($rateId, 'freernlltl') === 0 ? true : false;
        $lineItem = json_decode($data['lineitems'])->lineItemData;

        $responseFromWS = json_decode($data['quotes']);

        $shippingGroupResp = !blank($data['shipping_group_resp']) ? json_decode($data['shipping_group_resp']) : [];
        $requestToWS = json_decode($data['request']);

        $handlingUnitWeight = $requestToWS->requestArr->carriers->$wsCarrierCode->api->handlingUnitWeight ?? 0;
        $maxWeightPerHandlingUnit = $requestToWS->requestArr->carriers->$wsCarrierCode->api->maxWeightPerHandlingUnit ?? 0;

        // $lineItem->items = $this->formatItems($lineItem->items, $requestToWS->requestArr->commdityDetails);
        // $lineItem->origin = $this->formatOrigins($requestToWS->requestArr->carriers);
        $isMultiShipment = false;
        $multiShipmentresponse = $data['multiShipmentresponse'] === '{}' ? null : json_decode($data['multiShipmentresponse']);
        if (!blank($multiShipmentresponse)) {
            $isMultiShipment = true;
        }
        $flatRateResp = !blank($data['flat_rate_resp']) ? json_decode($data['flat_rate_resp']) : null;
        $liftGateStatus = 'n';
        $LimitedAccessDel = strpos($rateId, '+lad') ? 'Y' : 'n';
        $notifyBeforeDel = strpos($rateId, '+nbd') ? 'Y' : 'n';
        $insideDelivery = strpos($rateId, '+id') ? 'Y' : 'n';
        $isTruckLoad = strpos($rateId, '+tl') ? 'Y' : 'n';
        $isFreightTruckLoad = strpos($rateId, '+flgtl') ? 'Y' : 'n';
        $isTwoManDel = strpos($rateId, Functions::$twoManDelAccess) ? 'Y' : 'n';
        $isAppointmentDel = strpos($rateId, Functions::$appointmentDelAccess) ? 'Y' : 'n';
        $orderWidget = [];
        $isOneRate = strpos($rateId, '+or');
        $isGround = strpos($rateId, '+gd');
        $isAir = strpos($rateId, '+as');
        $rateType = $isGround ? 'ground' : ($isAir ? 'air' : ($isOneRate ? 'one_rate' : ""));
        $liftResidentialStatus = Functions::getLiftResidentialStatus($rateId);
        if ($isLG) {
            $liftGateStatus = $liftResidentialStatus['liftG'] ?? 'n';
        }
        $autoResidentialsStatus = $liftResidentialStatus['resi'] ?? 'n';
        $residentialsPickup = $liftResidentialStatus['resiPickup'] ?? 'n';
        $liftGatePickup = $liftResidentialStatus['lgPickup'] ?? 'n';
        // Removed Sbs COde From Here
        $packagingDetail = $this->getPackagingDetail($responseFromWS, $isSmallrate, $rateType);
        $origins = $lineItem->origin;
        $items = $lineItem->items;
        $count = 0;
        $addedInsurance = $addHazmat = false;

        $isMulti = false;
        $code = '';
        $orderDetails = [];
        foreach ($origins as $key => $origin) {
            $isFlatRate = false;
            $isFlatRate = strpos($rateId, 'flatraterule') === 0 ? true : false;
            $item = $items->$key;
            $city = $origin->senderCity ?? '';
            $state = $origin->senderState ?? '';
            $zip = $origin->locationId != '' ? $origin->locationId : $origin->senderZip;
            $senderZip = $origin->senderZip ?? '';
            $orderWidget[$zip]['ship_type'] = $item->dropship_enabled == 'N' ? 'Warehouse' : 'Dropship';
            $orderWidget[$zip]['address']['city'] = $city;
            $orderWidget[$zip]['address']['state'] = $state;
            $orderWidget[$zip]['address']['country'] = $origin->senderCountryCode;
            $orderWidget[$zip]['address']['postal_code'] = $senderZip;


            $orderWidget[$zip]['totalBoxes'] = $totalBoxes ?? 0;
            $sRate = $order['shipping_rate'];
            //print_r($multiShipmentresponse); exit;
            if ($multiShipmentresponse != null && !empty($multiShipmentresponse) && !$isOwnArrangement) {
                $enableFeaturesArray = Functions::getEnableFeaturesArr($isLG, $insideDelivery == 'Y', $notifyBeforeDel == 'Y', $LimitedAccessDel == 'Y');
                $enableFeaturesArray = array_reverse($enableFeaturesArray);
                foreach($enableFeaturesArray as $key => $feature){
                    if ($isHAT) {
                        $sRate = $multiShipmentresponse->$index->hat->$zip->rate ?? $multiShipmentresponse->$index->liftgate->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? 0.00;
                        $order['shipping_name'] = $multiShipmentresponse->$index->hat->$zip->title ?? $multiShipmentresponse->$index->liftgate->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? '';
                        $code = $multiShipmentresponse->$index->hat->$zip->code ?? $multiShipmentresponse->$index->liftgate->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? '';
                        break;
                    } else if ($feature['isEnable']) {
                        $sRate = $multiShipmentresponse->$index->$key->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? 0.00;
                        $order['shipping_name'] = $multiShipmentresponse->$index->$key->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? '';
                        $code = $multiShipmentresponse->$index->$key->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? '';
                        break;
                    } else if ($isFreightTruckLoad == 'Y') {
                        $sRate = $multiShipmentresponse->$index->Truckload->$zip->rate ?? $multiShipmentresponse->$index->liftgate->$zip->rate ?? 0.00;
                        $order['shipping_name'] = $multiShipmentresponse->$index->Truckload->$zip->title ?? $multiShipmentresponse->$index->liftgate->$zip->title ?? '';
                        $code = $multiShipmentresponse->$index->Truckload->$zip->code ?? $multiShipmentresponse->$index->liftgate->$zip->code ?? '';
                        break;
                    } else if ($isTruckLoad == 'Y') {
                        $sRate = $multiShipmentresponse->$index->Truckload->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? $multiShipmentresponse->$index->liftgate->$zip->rate ?? 0.00;
                        $order['shipping_name'] = $multiShipmentresponse->$index->Truckload->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? $multiShipmentresponse->$index->liftgate->$zip->title ?? '';
                        $code = $multiShipmentresponse->$index->Truckload->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? $multiShipmentresponse->$index->liftgate->$zip->code ?? '';
                        break;
                    } else if ($isTwoManDel == 'Y' && $isAppointmentDel == 'Y') {
                        $sRate = $multiShipmentresponse->$index->twoManAptDelivery->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? 0.00;
                        $order['shipping_name'] = $multiShipmentresponse->$index->twoManAptDelivery->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? '';
                        $code = $multiShipmentresponse->$index->twoManAptDelivery->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? '';
                        break;
                    } else if ($isTwoManDel == 'Y') {
                        $sRate = $multiShipmentresponse->$index->twoMan->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? 0.00;
                        $order['shipping_name'] = $multiShipmentresponse->$index->twoMan->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? '';
                        $code = $multiShipmentresponse->$index->twoMan->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? '';
                        break;
                    } else if ($isAppointmentDel == 'Y') {
                        $sRate = $multiShipmentresponse->$index->appointment->$zip->rate ?? $multiShipmentresponse->$index->simple->$zip->rate ?? 0.00;
                        $order['shipping_name'] = $multiShipmentresponse->$index->appointment->$zip->title ?? $multiShipmentresponse->$index->simple->$zip->title ?? '';
                        $code = $multiShipmentresponse->$index->appointment->$zip->code ?? $multiShipmentresponse->$index->simple->$zip->code ?? '';
                        break;
                    }
                }
                $carrierHasInsurance = $code ? Functions::hasInsureCarrier($code) : false;
                $carrierName = $code ? Functions::getCarrierNameOrCode($code) : "Multi Carrier";
                $wsCarrierCode = Functions::getCarrierNameOrCode($rateId, 1);
                $isSmall = Functions::isSmallCarrier($code);
                /*Added condition if in case of multi shipment
             The rate of shipping group will be added to warehouse rate*/
                if ($shippingGroupResp != null && $orderWidget[$zip]['locationtype'] == "Warehouse") {
                    $shippingGroupRate = $shippingGroupResp[0]->rate ?? 0;
                    $sRate = $sRate + $shippingGroupRate;
                }

                $isMulti = true;
            }
            
            if ($flatRateResp != null && $multiShipmentresponse == null && $isFlatRate) {
                $isFlatRate = true;
                $sRate = $flatRateResp->$zip->rate ?? 0;
            }

            $handlingUnitDetails = optional($responseFromWS)->$wsCarrierCode->$zip->debug ?? [];
            if (blank($handlingUnitDetails)) {
                $handlingUnitDetails = optional($responseFromWS)->$wsCarrierCode->$zip->DEBUG ?? [];
            }

            /**
             * Add Quote ID
             * */
            if (!$isSmallLtlrate && empty($multiShipmentresponse)){
                $orderWidget[$zip]['quoteId'] = Functions::getQuoteId($rateId, $responseFromWS, $zip);
            } elseif (!$isSmallLtlrate && $isMultiShipment) {
                $orderWidget[$zip]['quoteId'] = Functions::getQuoteId($code, $responseFromWS, $zip);
            }

            if (isset($order['shipping_name']) && strpos($order['shipping_name'], '(Delivery')){
                $sName = explode('(Delivery', $order['shipping_name'])[0] ?? '';
                $sName = explode('w/', $sName)[0] ?? '';
                $estimate = explode('(Delivery', $order['shipping_name'])[1] ?? '';
                $sMethod = '(Delivery' . $estimate;
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

            $quotes = optional($responseFromWS)->$wsCarrierCode->$zip ?? [];
            $sName = Functions::get3plServiceName($sName, $rateId, $origin, $quotes);

            $orderWidget[$zip]['service_name'] = $sName . $sMethod;
            $orderWidget[$zip]['ship_price'] = number_format((float)$sRate, 2);
            $orderWidget[$zip]['app_name'] = $carrierName;
            $orderWidget[$zip]['carrier_name'] = $carrierName;

            $addedHazmat = false;
            if (isset($orderWidget[$zip]['accessorials'])) {
                $addedHazmat = in_array('Hazardous Material', $orderWidget[$zip]['accessorials']);
            }
            $oldAccessorial = $orderWidget[$zip]['accessorials'] ?? [];
            $orderWidget[$zip]['accessorials'] = [];
            if (!$isMulti) {
                if ($carrierHasInsurance) {
                    if (isset($item->product_insurance_active) && $item->product_insurance_active == 1) {
                        array_push($orderWidget[$zip]['accessorials'], 'Insurance');
                        $addedInsurance = true;
                    } else if ($addedInsurance) {
                        array_push($orderWidget[$zip]['accessorials'], 'Insurance');
                    }
                }
                if (isset($item->isHazmatLineItem) && $item->isHazmatLineItem == 'Y') {
                    array_push($orderWidget[$zip]['accessorials'], 'Hazardous Material');
                    $addHazmat = true;
                } else if ($addHazmat) {
                    array_push($orderWidget[$zip]['accessorials'], 'Hazardous Material');
                }
            } else {
                if ((isset($item->product_insurance_active) && $item->product_insurance_active == 1 && $carrierHasInsurance) || in_array('Insurance', $oldAccessorial)) {
                    array_push($orderWidget[$zip]['accessorials'], 'Insurance');
                }
                if ((isset($item->isHazmatLineItem) && $item->isHazmatLineItem == 'Y') || $addedHazmat) {
                    array_push($orderWidget[$zip]['accessorials'], 'Hazardous Material');
                    $addHazmat = true;
                }
            }
            if ($isMulti) {
                strpos(strtolower($code), '+r') ? array_push($orderWidget[$zip]['accessorials'], 'Residential Delivery') : '';
            } else {
                $autoResidentialsStatus != 'n' ? array_push($orderWidget[$zip]['accessorials'], 'Residential Delivery') : '';
            }

            $isHAT ? array_push($orderWidget[$zip]['accessorials'], 'Hold At Terminal') : '';
            if (!$isSmall && !$isFlatRate) {
                $residentialsPickup != 'n' ? array_push($orderWidget[$zip]['accessorials'], 'Residential Pickup') : '';
                $liftGateStatus != 'n' ? array_push($orderWidget[$zip]['accessorials'], 'Lift Gate Delivery') : '';
                $liftGatePickup != 'n' ? array_push($orderWidget[$zip]['accessorials'], 'Lift Gate Pickup') : '';
                $insideDelivery != 'n' ? array_push($orderWidget[$zip]['accessorials'], 'Inside Delivery') : '';
                $LimitedAccessDel != 'n' ? array_push($orderWidget[$zip]['accessorials'], 'Limited Access Delivery') : '';
                $notifyBeforeDel != 'n' ? array_push($orderWidget[$zip]['accessorials'], 'Notify before Delivery') : '';
                $isTruckLoad != 'n' ? array_push($orderWidget[$zip]['accessorials'], 'Truck Load Delivery') : '';
                $isFreightTruckLoad != 'n' ? array_push($orderWidget[$zip]['accessorials'], 'Truck Load Delivery') : '';
                $isTwoManDel != 'n' ? array_push($orderWidget[$zip]['accessorials'], 'Two Man Delivery') : '';
                $isAppointmentDel != 'n' ? array_push($orderWidget[$zip]['accessorials'], 'Appointment Delivery') : '';
            }
            $accessorials = $this->formatAccessorials($orderWidget[$zip]['accessorials']);
            $orderWidget[$zip]['accessorials'] = $accessorials;

            $orderWidget[$zip]['packing_detail'] = $packagingDetail[$zip] ?? [];
            $orderWidget[$zip]['items'][] = $item;
            $typeOfShip = $orderWidget[$zip]['ship_type'] == 'Warehouse' ? 'w' : 'd';
            $locType = $typeOfShip . $zip;
            $orderWidget[$zip]['loc_code'] = $locType;
            $orderWidget[$zip]['carrier_type'] = 'small';
            if (!$isSmall) {
                $orderWidget[$zip]['carrier_type'] = 'ltl';
                $orderWidget[$zip]['handlingUnitWeight'] = $handlingUnitWeight;
                $orderWidget[$zip]['maxWeightPerHandlingUnit'] = $maxWeightPerHandlingUnit;
                $orderWidget[$zip]['handling_unit_details'] = $handlingUnitDetails;
            }

            $orderDetails[$locType]['ship_details'] = $orderWidget[$zip];
            // For Overriding Buf;c
            $orderDetails[$locType]['ship_details']['items'] = $orderWidget[$zip]['items'];
            // $orderWidget = [];
            $count++;
        }
        /*
  * Added For Catering items that ship as SHippping Group*/
        $itemsWithShipGroup = collect($items)->where('shipping_group', '!=', null)->all();
        if (!blank($itemsWithShipGroup)) {
            $itemsForm = [];
            foreach ($itemsWithShipGroup as $item) {
                $itemsForm[] = $item;
            }
            foreach ($orderDetails as $key => $data) {
                $items = data_get($data, 'items');
                if (count($orderDetails) > 1) {
                    if ($data['locationtype'] == "Warehouse") {
                        $items = array_merge($items, $itemsForm);
                    }
                } else {
                    $items = array_merge($items, $itemsForm);
                }
                $orderDetails[$key]['items'] = $items;
            }
        }
        return $this->formatOrderDetailItems($orderDetails);
    }


    public function formatOrderDetailItems($orderDetails): array
    {
        foreach ($orderDetails as $locId => $orderDetail) {
            $formattedItems = [];
            foreach ($orderDetail['ship_details']['items'] as $item) {
                $item = (array)$item;
                if (array_key_exists($item['id'], $formattedItems)) {
                    $formattedItems[$item['id']]['quantity'] = $formattedItems[$item['id']]['quantity'] + $item['piecesOfLineItem'];
                } else {
                    $formattedItems[$item['id']] = $item;
                    $formattedItems[$item['id']]['quantity'] = $item['piecesOfLineItem'];
                }

            }
            $orderDetails[$locId]['ship_details']['items'] = array_values($formattedItems);
        }
        return $orderDetails;
    }

    public function formatItems($oldItems, $items)
    {
        $tempItems = $items;
        foreach ($tempItems as $key => $item) {
            $variant_id = $item->variant_id;
            $oldItems->$variant_id = $item;
            $oldItems->$key = $item;
            $oldItems->$key->lineItemDescription = $item->lineItemName ?? "";
        };
        return $oldItems;
    }


    public function formatOrigins($carriers)
    {
        $newOrigin = new \stdClass();
        foreach ($carriers as $carrier) {
            foreach ($carrier->originAddress as $key => $origin) {
                $newOrigin->$key = $origin;
            }
        }
        return $newOrigin;
    }


    public function formatAccessorials($accessorials)
    {
        $formAccess = ['residential' => false, 'liftgate' => false, 'hazmat' => false];
        foreach ($accessorials as $accessorial) {
            if ($accessorial == "Residential Delivery") {
                $formAccess['residential'] = true;
            }
            if ($accessorial == "Lift Gate Delivery") {
                $formAccess['liftgate'] = true;
            }
            if ($accessorial == "Hazardous Material") {
                $formAccess['hazmat'] = true;
            }
            if ($accessorial == "Inside Delivery") {
                $formAccess['inside'] = true;
            }
            if ($accessorial == "Limited Access Delivery") {
                $formAccess['limitedaccess'] = true;
            }
            if ($accessorial == "Truck Load Delivery") {
                $formAccess['truckload'] = true;
            }
            if ($accessorial == "Notify before Delivery") {
                $formAccess['notify'] = true;
            }
            if ($accessorial == "Two Man Delivery") {
                $formAccess['twoman'] = true;
            }
            if ($accessorial == "Appointment Delivery") {
                $formAccess['appointment'] = true;
            }
            if ($accessorial == "Residential Pickup") {
                $formAccess['residentialpickup'] = true;
            }
            if ($accessorial == "Lift Gate Pickup") {
                $formAccess['liftgatepickup'] = true;
            }
            if ($accessorial == "Insurance") {
                $formAccess['insurance'] = true;
            }
        }
        return $formAccess;
    }

    public function getPackagingDetail($responseFromWS, $isSmallrate, $rateType)
    {
        $packagingDetail = [];
        foreach ($responseFromWS as $carrierName => $WsResp) {
            foreach ($WsResp as $zip => $ws) {

                if (!(isset($ws->severity) && $ws->severity == 'ERROR')) {

                    $totalBoxes = 1;
                    if (isset($ws->binPackagingData) && !empty($ws->binPackagingData) && $isSmallrate) {
                        if ($rateType == "ground") {
                            $sbsData = $ws->binPackagingData->response->ground->bins_packed ?? $ws->binPackagingData->response->bins_packed ?? [];
                        } else if ($rateType == "air") {
                            $sbsData = $ws->binPackagingData->response->air->bins_packed ?? $ws->binPackagingData->response->ground->bins_packed ?? $ws->binPackagingData->response->bins_packed ?? [];
                        } else if ($rateType == "one_rate") {
                            $sbsData = $ws->binPackagingData->response->oneRate->bins_packed ?? [];
                        } else {
                            $sbsData = $ws->binPackagingData->response->bins_packed ?? $ws->binPackagingData->response->ground->bins_packed ?? $ws->binPackagingData->response->air->bins_packed ?? $ws->binPackagingData->response->oneRate->bins_packed ?? [];
                        }
                        $itemCount = 0;
                        foreach ($sbsData as $key => $binPacked) {
                            $type = optional($binPacked->bin_data)->type ?? '';
                            $quantity = 1;
                            if ($type == 'item' || $type == 'weight_based') {
                                $type = 'item';
                                $product_id = $binPacked->bin_data->id;
                                $quantity = $binPacked->bin_data->quantity ?? 1;
                                $itemCount++;
                            }
                            $count = 0;
                            $orderWidgetData['type'] = $type;
                            $orderWidgetData['image_complete'] = $binPacked->image_complete;
                            $orderWidgetData['bin_data']['d'] = $binPacked->bin_data->d;
                            $orderWidgetData['bin_data']['w'] = $binPacked->bin_data->w;
                            $orderWidgetData['bin_data']['h'] = $binPacked->bin_data->h;
                            $orderWidgetData['bin_data']['weight'] = $binPacked->bin_data->weight ?? 0;
                            $orderWidgetData['bin_data']['used_weight'] = $binPacked->bin_data->used_weight ?? 0;
                            $orderWidgetData['quantity'] = $quantity;

                            $orderWidgetData['nickname'] = Functions::getBoxName($binPacked->bin_data->id);
                            foreach ($binPacked->items as $item) {
                                $productid = $item->id;

                                $orderWidgetData['items'][$count]['product_name'] = $lineItem->items->$productid->lineItemName ?? '';
                                $orderWidgetData['items'][$count]['w'] = $item->w;
                                $orderWidgetData['items'][$count]['h'] = $item->h;
                                $orderWidgetData['items'][$count]['d'] = $item->d;

                                $orderWidgetData['items'][$count]['image_separated'] = $item->image_separated;
                                $orderWidgetData['items'][$count]['image_sbs'] = $item->image_sbs;

                                $packagingDetail[$zip]['all_boxes_rtsq'][$key] = $orderWidgetData;
                                $packagingDetail[$zip]['all_boxes_rtsq'][$key] = $orderWidgetData;
                                ++$count;

                            }
                            unset($orderWidgetData);
                            if ($count) {
                                $packagingDetail[$zip]['all_boxes_rtsq'][$key]['number_of_items'] = $count;
                                $packagingDetail[$zip]['all_boxes_rtsq'][$key]['number_of_items'] = $count;
                            }
                        }
                        $totalBoxes = isset($key) ? $key + 1 - $itemCount : 0;


                    }
                }
            }
        }
        return $packagingDetail;
    }

    public function save($detail, $request)
    {
        $storeId = $detail['store_id'];
        $order = $detail['order_detail'];
        $rateId = $order['rate_id'] ?? null;
        $cartId = $order['cart_id'] ?? null;
        $orderId = $request['order_id'] ?? null;
        unset($request['order_id']);
        $newShipment[] = $request->all() ?? []; 

        $data = $this->getRequestDataFromDB('RequestData', $storeId, $rateId, $cartId, $order);        
        if (!blank($data)) {
            $data = RequestData::where('id', $data['id'])
            ->where('store_id', $storeId)
            ->first();

            $oldShipments = json_decode($data->fdo_shipments_data, true) ?? []; 
            $shipmentsData = array_merge($oldShipments, $newShipment);
            $data->fdo_shipments_data = json_encode($shipmentsData);
            $data->order_id = $orderId;
            $data->save();
            return $shipmentsData ?? [];
        }
        return [];
    }

    public function saveFdoShipments(Request $request)
    {
        try {
            $orderId = $request->order_id ?? '';
            $storeHash = $request->header('store-hash') ?? null;
            if (blank($storeHash) || blank($orderId)) {
                return Helpers::sendJsonResponseFdo(true, 'Store hash and order id required');

            }
            $order = $this->getBCOrderByID($storeHash, $orderId);
            if (blank($order['order_detail'])) {
                return Helpers::sendJsonResponseFdo(true, 'No Shipment Detail Found From BigCommerce');
            }
            $orderDetail = $this->save($order, $request);
            if (empty($orderDetail)) {
                return Helpers::sendJsonResponseFdo(true, 'No Shipment detail found from DB');
            }
            return Helpers::sendJsonResponseFdo(false, 'Shipment details saved successfully', $orderDetail);
        } catch (\Exception $exception) {
            return Helpers::sendJsonResponseFdo(true, 'Exception on saving shipments', ['exception' => $exception->getMessage()]);
        }
    }

}
