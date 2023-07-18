<?php

namespace App\CustomClasses;
use Illuminate\Support\Facades\Log;
use App\Models\Store;
use App\Models\InstalledCarrier;
use App\CustomClasses\UpsShipEngineSmall\QuotesResults as upsShipEngineSmallQuotesResults;
use App\CustomClasses\WWESMALL\WweSmallQuoteResults;


class CompareRates
{
    public function formatCompareRateRequest($data, $storeData, $connectionSettings, $carriers)
    {
        $storeId = $storeData['store']['id'];
        $this->storeData = $storeData;
        $this->connectionSettings = $connectionSettings ?? [];
        $this->carriers = $carriers ?? [];
        $this->isResidentail = isset($data['isResidentail']) && $data['isResidentail'] ?? false;
        $destination = [
            'destination' => [
                'street_1' => $data['street_1'] ?? null,
                'street_2' => $data['street_2'] ?? null,
                'zip' => $data['destination_zip'] ?? null,
                'city' => $data['destination_city'] ?? null,
                'state' => $data['destination_state'] ?? null,
                'country' => $data['destination_country'] ?? null,
                'address_type' => $data['address_type'] ?? null,
            ]
        ];
        $origin['origin'][$data['origin_zip']] = [

            'location' => 'warehouse',
            'locationId' => (int) $data['origin_zip'] ?? time(),
            'address' => $data['street_1'] ?? null,
            'senderZip' => $data['origin_zip'] ?? null,
            'senderCity' => $data['origin_city'] ?? null,
            'senderState' => $data['origin_state'] ?? null,
            'senderCountryCode' => $data['origin_country'] ?? null,
        ];

        $item['items'][$data['origin_zip']] = [
            'piecesOfLineItem' => 1,
            'originalPiecesOfLineItem' => 1,
            'lineItemPrice' => 0,
            'lineItemName' => '',
            'lineItemLength' => number_format($data['length'] ?? 0, 2, '.', ''),
            'lineItemWidth' => number_format($data['width'] ?? 0, 2, '.', ''),
            'lineItemHeight' => number_format($data['height'] ?? 0, 2, '.', ''),
            'lineItemWeight' => number_format($data['weight'], 2, '.', ''),
            'freightClass' => '',
        ];
        $details = array_merge($destination, $origin, $item);

        $originAddress = $details['origin'] ?? [];
        $lineItems = $details['items'] ?? [];
        $destination = $details['destination'] ?? [];

        $generateCarriersArray = $this->generateCarriersArray($originAddress, $destination, $lineItems);
        $carriersArray = $generateCarriersArray['carriersArr'];
        
        return $this->generateRequestArray($details, $carriersArray, $lineItems);
    }

    public function generateCarriersArray($origin, $destination, $lineItems)
    {
        
        $carriersArr['carriers'] = [];
        $GenerateRequestData = new GenerateRequestData();
        $this->storeDateTime = $GenerateRequestData->getBCStoreDateTime();
        Log::info('Store Time' . $this->storeDateTime);

        foreach ($this->connectionSettings as $key => $con1) {
            if(isset($this->carriers[$key]) && $this->carriers[$key]){
                switch ($key) {
                    case "small-package":
                        $wweLtlArr = $this->wweSmallEnitArr($con1, $destination);
                        $wweLtlArr['originAddress'] = $origin;
                        $carriersArr['carriers']['wweSmall'] = $wweLtlArr;
                        break;
                    case "ups-ship-engine":
                        $upsShipEngineArr = $this->upsShipEngineSmallEnitArr($con1, $destination);
                        $upsShipEngineArr['originAddress'] = $origin;
                        $carriersArr['carriers']['shipEngine'] = $upsShipEngineArr;
                        break;
                }
            }
        }
        return ['carriersArr' => $carriersArr];
    }

    public function wweSmallEnitArr($connSettings, $destination)
    {
        return [
            'licenseKey' => '',
            'serverName' => Functions::getServerName($this->storeData),
            'carrierMode' => 'pro',
            'quotestType' => 'small', // ltl / small
            'version' => '2.0.4',
            'api' => $this->getApiInfoArrWweSmall($connSettings, $destination),
            'getDistance' => 0,
        ];
    }

    public function upsShipEngineSmallEnitArr($connSettings, $destination)
    {
        return [
            'licenseKey' => '',
           // 'serverName' => Functions::getServerName($this->storeData),
            'carrierMode' => 'pro',
            'quotestType' => 'small', // ltl / small
            'version' => '1.0.0',
            'api' => $this->getApiInfoArrUpsShipEngineSmall($connSettings, $destination),
            'getDistance' => 0,
        ];
    }

    public function getApiInfoArrWweSmall($connSettings, $destination)
    {

        $apiArray = [
            'speed_ship_username' => isset($connSettings['creds']['username']) ? $connSettings['creds']['username'] : '',
            'speed_ship_password' => isset($connSettings['creds']['password']) ? $connSettings['creds']['password'] : '',
            'authentication_key' => isset($connSettings['creds']['authentication_key']) ? $connSettings['creds']['authentication_key'] : '',
            'world_wide_express_account_number' => isset($connSettings['creds']['account_number']) ? $connSettings['creds']['account_number'] : '',
            'clientId' => isset($connSettings['creds']['clientId']) ? $connSettings['creds']['clientId'] : '',
            'clientSecret' => isset($connSettings['creds']['clientSecret']) ? $connSettings['creds']['clientSecret'] : '',
            'ApiVersion' => '2.0',
            'residentials_delivery' => ($this->isResidentail ? 'Y' : 'no'),
            'prefferedCurrency' => 'USD',
            'includeDeclaredValue' => "1",
        ];

        if (isset($connSettings['creds']['api_type']) && $connSettings['creds']['api_type'] === 'new_api'){
            unset(
                $apiArray['speed_ship_username'],
                $apiArray['speed_ship_password'],
                $apiArray['authentication_key'],
                $apiArray['world_wide_express_account_number'],
            );
        }else {
            unset(
                $apiArray['clientId'],
                $apiArray['clientSecret'],
                $apiArray['ApiVersion'],
            );
        }
        return array_merge($apiArray, $this->getCutOffDetails($connSettings));
    }

    public function getApiInfoArrUpsShipEngineSmall($connSettings, $destination)
    {
        $apiArray = [
            'apiVersion' => '2.0',
            'modifyShipmentDateTime' => isset($connSettings['quote_settings']['delivery_estimate_options']) && $connSettings['quote_settings']['delivery_estimate_options'] > 1 ? '1' : '0',
            'OrderCutoffTime' => $connSettings['quote_settings']['order_cut_off_time'] ?? '',
            'shipmentOffsetDays' => $connSettings['quote_settings']['fulfillment_offset_days'] ?? '',
            'storeDateTime' => $this->storeDateTime,
            'shipmentWeekDays' => isset($connSettings['quote_settings']['week_days']) ? $this->getDays($connSettings['quote_settings']['week_days']) : '', //array('1','2','3','4','5'),
            'residentialDelivery' => ($this->isResidentail) ? 'yes' : 'no',
            'prefferedCurrency' => 'USD',

        ];


        return $apiArray;
    }

    public function getCutOffDetails($connSettings): array
    {
        $delEstimateOption = $connSettings['quote_settings']['delivery_estimate_options'] ?? 1;
        $fulfillmentOffsetDays = $connSettings['quote_settings']['fulfillment_offset_days'] ?? null;
        $orderCutOffTime = $connSettings['quote_settings']['order_cut_off_time'] ?? null;
        $shipmentWeekDays = isset($connSettings['quote_settings']['week_days']) ? $this->getDays($connSettings['quote_settings']['week_days']) : null;
        $modifyShipmentDateTime = $delEstimateOption != 1 && (!blank($fulfillmentOffsetDays) || !blank($orderCutOffTime) || !blank($shipmentWeekDays)) ? '1' : '0';
        return [
            'modifyShipmentDateTime' => $modifyShipmentDateTime,
            'OrderCutoffTime' => $orderCutOffTime,
            'shipmentOffsetDays' => $fulfillmentOffsetDays,
            'storeDateTime' => $this->storeDateTime,
            'shipmentWeekDays' => $shipmentWeekDays,
        ];
    }

    private function getDays($days)
    {
        $daysNameKey = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5];
        $selectedDays = [];
        foreach ($days as $dayName => $day) {
            if (isset($daysNameKey[$day])) {
                array_push($selectedDays, $daysNameKey[$day]);
            }
        }
        return $selectedDays;
    }

    public function getData($storeHash)
    {
        if ($storeHash == null) {
            return null;
        }
        $store = Store::where(['hash' => $storeHash, 'app_status' => 1])->first();
        if (!empty($store)) {
            $installedCarriers = InstalledCarrier::join('carriers', 'carriers.id', 'installed_carriers.carrier_id')
                ->where(['store_id' => $store->id])
                ->select('installed_carriers.*', 'carriers.slug')
                ->get();
            
            if (!empty($installedCarriers) && count($installedCarriers)) {
                return [
                    'installed_carriers' => $installedCarriers,
                    'store' => $store,
                ];
            }
        }
        return null;
    }

    public function generateRequestArray($request, $carriersArray, $itemsArr)
    {
        $carriers = $carriersArray['carriers'];
        $receiverAddress = $this->getReceiverData($request);

        $requestArr = [
            'apiVersion' => '2.0',
            'platform' => 'bigcommerce',
            'dont_auth' => 1,
            'requestKey' => md5(microtime() . rand()),
            'carriers' => $carriers,
            'receiverAddress' => $receiverAddress,
            'commdityDetails' => $itemsArr,
        ];

        return ['requestArr' => $requestArr];
    }

    public function getReceiverData(array $request)
    {
        return [
            'addressLine' => $request['destination']['street_1'],
            'receiverCity' => $request['destination']['city'],
            'receiverState' => $request['destination']['state'],
            'receiverZip' => preg_replace('/\s+/', '', $request['destination']['zip']),
            'receiverCountryCode' => $request['destination']['country'],
            'defaultRADAddressType' => 'residential', //$addressType ?? 'residential', //get value from RAD
        ];
    }

    public function getCompareRates($quotes, $connectionSettings)
    {
        $this->connectionSettings = $connectionSettings ?? [];
        $resp = [];
        
        if(!empty($quotes)){
            foreach ($quotes as $key => $shipment) {
                switch ($key) {
                    case "wweSmall":
                        $compiledQuotes = $this->compileWweSmallQuotes($shipment);
                        if(gettype($compiledQuotes) === 'string'){
                            $resp = $compiledQuotes;
                            break;
                        }
                        foreach($compiledQuotes as $quote){
                            $resp['small_package'][] = $quote;
                        }
                        
                        break;
                    case "shipEngine":
                        $compiledQuotes = $this->compileUpsShipEngineQuotes($shipment);
                        if(gettype($compiledQuotes) === 'string'){
                            $resp = $compiledQuotes;
                            break;
                        }
                        foreach($compiledQuotes as $quote){
                            $resp['ups_ship_engine'][] = $quote;
                        }
                        break;
                }
            }
        }

        return $resp;
    }
    
    public function compileUpsShipEngineQuotes($shipments)
    {

        $quoteResults = new upsShipEngineSmallQuotesResults();

        try {
            $res = $quoteResults->compileCompareQuotes($shipments, $this->connectionSettings);
        } catch (\Exception $exception) {
            Log::info('Exception on shipengine results ' . json_encode([
                'line' => $exception->getLine(),
                'message' => $exception->getMessage()
            ]));

            return [];
        }

        return $res ?? [];
    }

    public function compileWweSmallQuotes($shipments)
    {

        $quoteResults = new WweSmallQuoteResults();

        try {
            $res = $quoteResults->compileCompareQuotes($shipments, $this->connectionSettings);
        } catch (\Exception $exception) {
            Log::info('Exception on shipengine results ' . json_encode([
                'line' => $exception->getLine(),
                'message' => $exception->getMessage()
            ]));

            return [];
        }

        return $res ?? [];
    }
}