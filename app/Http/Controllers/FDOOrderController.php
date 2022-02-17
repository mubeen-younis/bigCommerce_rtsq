<?php

namespace App\Http\Controllers;

use App\Constants\Constant;
use App\CurlRequest;
use App\Endpoints\Endpoints;
use App\Helpers\Helpers;
use App\Models\RequestData;
use App\Models\Store;
use Illuminate\Http\Request;

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
            $storeHash = $request->header('store_hash') ?? null;
            if (blank($storeHash) || blank($orderId)) {
                Helpers::sendJsonResponseFdo(true, 'Store hash and order id required');

            }
            $order = $this->getBCOrderByID($storeHash, $orderId);
            if (blank($order)) {
                Helpers::sendJsonResponseFdo(true, 'No Order Detail Found From BigCommerce');
            }
            $orderDetail = $this->getDetail($order);
            if (empty($orderDetail)) {
                Helpers::sendJsonResponseFdo(true, 'No order detail found from DB');
            }
            Helpers::sendJsonResponseFdo(false, '', $orderDetail);
        } catch (\Exception $exception) {
            Helpers::sendJsonResponseFdo(true, 'Something went wrong', ['exception' => $exception->getMessage()]);
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
                    $resp['rate_id'] = $response->rate_id;
                    $resp['full_rate_id'] = $response->shipping_provider_quote->rateId ?? '';
                    $resp['shipping_name'] = $response->shipping_provider_quote->name ?? '';
                    $resp['shipping_rate'] = $response->shipping_provider_quote->rate->value ?? '';
                }
            }
        }
        return $resp;
    }


    public function getDetail($detail)
    {
        $storeId = $detail['store_id'];
        $order = $detail['order_detail'];
        $rateId = $order['rate_id'] ?? null;

        $data = optional(RequestData::where('rate_id', $rateId)
                ->where('cart_id', $order['cart_id'])
                ->where('store_id', $storeId)
                ->first())->toArray() ?? null;

        if (blank($data) && !blank($order['full_rate_id'])) {
            $rateId = $order['full_rate_id'] ?? null;
            $data = optional(RequestData::where('rate_id', $order['full_rate_id'])
                    ->where('cart_id', $order['cart_id'])
                    ->where('store_id', $storeId)
                    ->first())->toArray() ?? null;
        }
        if (blank($data)) {
            return [];
        }
        dd(1234, $data);
        $carrierHasInsurance = $this->hasInsureCarrier($rateId);
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

        $requestToWS = json_decode($data['request']);
        $lineItem->items = $this->formatItems($lineItem->items, $requestToWS->requestArr->commdityDetails);
//print_r($lineItem->items); exit;
        $lineItem->origin = $this->formatOrigins($requestToWS->requestArr->carriers);
        $multiShipmentresponse = $data['multiShipmentresponse'] === '{}' ? null : json_decode($data['multiShipmentresponse']);
        $autoResidentialsStatus = 'n';
        $residentialsPickup = 'n';
        $liftGateStatus = 'n';
        $binPackagingData = '';
        $orderWidget = [];
        $isOneRate = strpos($rateId, '+or');
        $isGround = strpos($rateId, '+gd');
        $isAir = strpos($rateId, '+as');
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
                            $sbsData = $ws->binPackagingData->response->ground->bins_packed;
                        } else if ($isAir) {
                            $sbsData = $ws->binPackagingData->response->air->bins_packed;
                        } else if ($isOneRate) {
                            $sbsData = $ws->binPackagingData->response->oneRate->bins_packed;
                        } else {
                            $sbsData = $ws->binPackagingData->response->bins_packed ?? $ws->binPackagingData->response->ground->bins_packed ?? $ws->binPackagingData->response->air->bins_packed ?? $ws->binPackagingData->response->oneRate->bins_packed;
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

                            $orderWidgetData['nickname'] = $this->getBoxName($binPacked->bin_data->id, $storeId, $rateId, $order['cart_id']);
                            foreach ($binPacked->items as $item) {
                                $productid = $item->id;

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
                        $totalBoxes = $key + 1 - $itemCount;


                    }
                }
            }
        }
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
            } else {
                if ((!in_array($item->id, $insertedIds))) {
                    $insertedIds[] = $item->id;
                    $orderWidget[$zip]['items'][] = $item->originalPiecesOfLineItem . ' X ' . $item->lineItemName;
                }
            }
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
            $isSmall = $this->isSmallQuote($sName);
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
        $sbs = '';
        //print_r($orderWidget); exit;
        $resp = [
            'widget' => $this->objectToArray($orderWidget),
            'sbs' => $sbs
        ];
        return $resp;
    }

    public function formatItems($oldItems, $items)
    {
        $tempItems = $items;
        foreach ($tempItems as $key => $item) {
            $variant_id = $item->variant_id;
            $oldItems->$variant_id = $item;
            $oldItems->$key = $item;
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

}
