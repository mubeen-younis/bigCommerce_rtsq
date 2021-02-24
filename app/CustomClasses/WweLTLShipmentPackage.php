<?php

namespace App\CustomClasses;

use App\Constants\Constant;
use App\Http\Controllers\LocationsController;

/**
 * Class WweLTLShipmentPackage
 * @package Eniture\WweLtlFreightQuotes\Model\Carrier
 */
class WweLTLShipmentPackage
{
    /**
     * @var
     */
    private $httpRequest;
    /**
     * @var
     */
    private $productLoader;
    /**
     * @var
     */
    private $compileQuotes;
    /**
     * @var
     */
    private $scopeConfig;
    /**
     * @var
     */
    private $request;

    /**
     * @var Object
     */
    public $quoteSettings;
    /**
     * @var Object
     */
    public $connectionSettings;
    /**
     * @var Object
     */
    public $storeData;

    /**
     * @param $scopeConfig
     * @param $dataHelper
     * @param $productLoader
     * @param $httpRequest
     */
    public function _init(
        $scopeConfig,
        $dataHelper,
        $productLoader,
        $httpRequest
    )
    {
        $this->scopeConfig = $scopeConfig;
        $this->compileQuotes = $dataHelper;
        $this->productLoader = $productLoader;
        $this->httpRequest = $httpRequest;
    }

    /**
     * function that returns address array
     * @param $request
     * @param $_product
     * @param $receiverZipCode
     * @param $storeData
     * @return array
     */
    public function wweLTLOriginAddress(
        $request,
        $_product,
        $receiverZipCode,
        $storeData
    )
    {
        //Todo: need to check which warehouse is selected and method params conflict also must be fixed. fetchWarehouseSecData()
        $this->request = $request;
        $whQuery = LocationsController::getAllLocations($storeData['store']->id, 1);
        if ($_product['dropship_enabled']) {
            $dropShipID = $_product['dropship_location'];
            $originList = LocationsController::getLocationById($dropShipID);
            $origin = (!$originList) ? $whQuery : $originList;
        } else {
            $origin = $whQuery;
        }
        if ($origin !== null && count($origin)) {
            return $this->multiWarehouse($origin, $receiverZipCode);
        }
    }

    /**
     * This function returns the closest warehouse if multiple warehouse exists otherwise
     * return single.
     * @param $warehouseList
     * @param $receiverZipCode
     * @return array
     */
    public function multiWarehouse($warehouseList, $receiverZipCode)
    {
        //Todo: we need to get plans from DB
        $planNumber = 3;
        //$planNumber = $this->dataHelper->planInfo()['planNumber'];

        if (!empty($warehouseList)) {
            if (count($warehouseList) == 1) {
                $warehouseList = reset($warehouseList);
                return $this->wweLTLOriginArray($warehouseList, $receiverZipCode, $planNumber);
            } elseif (count($warehouseList) > 1 && ($planNumber == 0 || $planNumber == 1)) {
                return $this->wweLTLOriginArray($warehouseList[0], $receiverZipCode, $planNumber);
            }

            $response = $this->wweLTLAddress($warehouseList);
            if (!empty($response)) {
                $originWithMinDist = (isset($response->origin_with_min_dist) && !empty($response->origin_with_min_dist)) ? (array)$response->origin_with_min_dist : [];
                return $this->wweLTLOriginArray($originWithMinDist, $receiverZipCode, $planNumber);
            }
        }
    }

    /**
     * function that returns shortest origin managed array
     * @param $shortOrigin
     * @param $receiverZipCode
     * @param $planNumber
     * @return array
     */
    public function wweLTLOriginArray($shortOrigin, $receiverZipCode, $planNumber)
    {
        if (isset($shortOrigin) && count($shortOrigin)) {
            $origin = isset($shortOrigin['origin']) ? $shortOrigin['origin'] : $shortOrigin;
            $origin = reset($origin);
            $zip = $origin['zip_code'] ?? '';
            $city = $origin['city'] ?? '';
            $state = $origin['state'] ?? '';
            $country = ($origin['country'] == "United State") ? "US" : $origin['country'];
            $location = isset($origin['type']) && $origin['type'] == 1 ? 'warehouse' : 'dropship';
            $locationId = $shortOrigin->id ?? '';
            return [
                'location' => $location,
                'locationId' => $locationId,
                'senderZip' => $zip,
                'senderCity' => $city,
                'senderState' => $state,
                'senderCountryCode' => $country,
                'InstorPickupLocalDelivery' => $planNumber == 3 ? $this->instorePickupLdData($origin, $receiverZipCode) : '',
            ];
        }
    }

    /**
     * This function returns response from google api
     * @param $originAddress
     * @return array
     */
    public function wweLTLAddress($originAddress)
    {
        $originAddress = $this->changeWarehouseIdKey($originAddress);
        $post = [
            'acessLevel' => 'MultiDistance',
            'address' => $originAddress,
            'originAddresses' => (isset($originAddress)) ? $originAddress : "",
            'destinationAddress' => [
                'city' => $this->request->getDestCity(),
                'state' => $this->request->getDestRegionCode(),
                'zip' => $this->request->getDestPostcode(),
                'country' => $this->request->getDestCountryId(),
            ],
            'ServerName' => $this->httpRequest->getServer('SERVER_NAME'),
            'eniureLicenceKey' => $this->scopeConfig->getValue('wweLtlConnSettings/first/WweLtllicnsKey', ScopeInterface::SCOPE_STORE),
        ];
        $curlRes = $this->dataHelper->wweLTLSendCurlRequest(Constant::GOOGLE_URL, $post);

        if (!isset($curlRes->error)) {
            $response = $curlRes;
        } else {
            $response = [];
        }
        return $response;
    }

    /**
     * @param $origins
     * @return array
     */
    public function changeWarehouseIdKey($origins)
    {
        $result = [];
        foreach ($origins as $key => $origin) {
            if ($origin['warehouse_id']) {
                $origin['id'] = $origin['warehouse_id'];
                unset($origin['warehouse_id']);
            }
            $result[$key] = $origin;
        }

        return $result;
    }

    /**
     * @param array $shortOrigin
     * @param string $receiverZipCode
     * @return array
     */
    public function instorePickupLdData($shortOrigin, $receiverZipCode)
    {
        $additionalData = isset($shortOrigin['additionals']) ? \GuzzleHttp\json_decode($shortOrigin['additionals'], true) : null;

        $array = [];
        if (!empty($additionalData['in_store']) && $additionalData['in_store'] != null) {
            $inStore = json_decode($shortOrigin['in_store']);
            if ($inStore->enable_store_pickup == 1) {
                $array['inStorePickup'] = [
                    'addressWithInMiles' => $inStore->miles_store_pickup,
                    'postalCodeMatch' => $this->checkPostalCodeMatch($receiverZipCode, $inStore->match_postal_store_pickup),
                ];
            }
        }

        if (!empty($additionalData['local_delivery']) && $additionalData['local_delivery'] != null) {
            $locDel = json_decode($shortOrigin['local_delivery']);
            if ($locDel->enable_local_delivery == 1) {
                $array['localDelivery'] = [
                    'addressWithInMiles' => $locDel->miles_local_delivery,
                    'postalCodeMatch' => $this->checkPostalCodeMatch($receiverZipCode, $locDel->match_postal_local_delivery),
                    'suppressOtherRates' => $locDel->suppress_other,
                ];
            }
        }
        return $array;
    }

    /**
     * @param $receiverZipCode
     * @param $originZipCodes
     * @return bool
     */
    public function checkPostalCodeMatch($receiverZipCode, $originZipCodes)
    {
        $receiverZipCode = preg_replace('/\s+/', '', $receiverZipCode);
        $originZipCodes = preg_replace('/\s+/', '', $originZipCodes);
        return in_array($receiverZipCode, explode(',', $originZipCodes)) ? 1 : 0;
    }
}
