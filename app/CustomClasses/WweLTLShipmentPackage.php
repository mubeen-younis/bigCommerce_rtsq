<?php

namespace App\CustomClasses;

use App\Constants\Constant;
use App\Http\Controllers\LocationsController;
use App\Models\Connection;
use App\Models\InstalledCarrier;
use App\Models\Store;
use Illuminate\Support\Facades\Log;

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
    public function _init($scopeConfig,
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
        $storeData,
        $connectionSettings
    )
    {
        //dd(1,$request,$_product,$receiverZipCode,$storeData,$connectionSettings);
        //Todo: need to check which warehouse is selected and method params conflict also must be fixed. fetchWarehouseSecData()
        $this->request = $request;
        $this->storeData = $storeData;
        $this->connectionSettings = $connectionSettings;
        $whQuery = LocationsController::getAllLocations($storeData['store']->id, 1);

        $dropship_enabled = $_product['dropship_enabled'] ?? false;

        if ($dropship_enabled) {
            $dropShipID = $_product['dropship_location'];
            $originList = LocationsController::getLocationById($dropShipID);
            //dd($whQuery, $dropShipID, $originList);
            if (empty($originList)) {
                $origin = $whQuery;
            } else {
                $origin[] = $originList;
            }
        } else {
            $origin = $whQuery;
        }
        $originLoca = [];

        foreach ($origin as $key => $ori) {
            /*   echo '<pre>';
               print_r($ori);
               echo '</pre>';
               die();*/
            $originLoca[$key]['warehouse_id'] = $ori->id ?? '';

            $originLoca[$key]['city'] = $ori->city ?? '';
            $originLoca[$key]['state'] = $ori->state ?? '';
            $originLoca[$key]['zip'] = $ori->zip_code ?? '';
            $originLoca[$key]['country'] = $ori->country ?? '';
            $originLoca[$key]['additionals'] = $ori->additionals ?? [];
        }

        $origin = $originLoca;
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
        // Here we are getting plans f carriers and seeing if any of carrier has standard or advance plan
        // if they have and origin address is more then 1 then we are firing multiwarehouse request
        $planInfo = $this->getPlanNumberFromInstalledCarriers($this->storeData['installed_carriers']);
        $planNumber = $planInfo['pkg'] ?? 1;
        $planLicenseKey = $planInfo['license_key'] ?? '';
        //$planNumber=1;
        // $planNumber = $this->dataHelper->planInfo()['planNumber'];
        if (!empty($warehouseList)) {

            if (count($warehouseList) == 1) {
                $warehouseList = reset($warehouseList);
                return $this->wweLTLOriginArray($warehouseList, $receiverZipCode, $planNumber);
            } elseif (count($warehouseList) > 1 && ($planNumber == 0 || $planNumber == 1)) {
                return $this->wweLTLOriginArray($warehouseList[0], $receiverZipCode, $planNumber);
            }

            $response = (object)$this->wweLTLAddress($warehouseList, $planLicenseKey);

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
            //$origin = reset($origin);
            $origin = isset($shortOrigin['origin']) ? $shortOrigin['origin'] : $shortOrigin;
            $zip = $origin['zip'] ?? '';
            $city = $origin['city'] ?? '';
            $state = $origin['state'] ?? '';
            $country = ($origin['country'] == "United State") ? "US" : $origin['country'];
            $location = isset($origin['type']) && $origin['type'] == 1 ? 'warehouse' : 'dropship';
            $locationId = $shortOrigin['warehouse_id'] ?? '';
            $data = [
                'location' => $location,
                'locationId' => $locationId,
                'senderZip' => $zip,
                'senderCity' => $city,
                'senderState' => $state,
                'senderCountryCode' => $country,
                'InstorPickupLocalDelivery' => $planNumber == 3 ? $this->instorePickupLdData($origin, $receiverZipCode) : '',
            ];
            $origin = reset($origin);
            return $data;
        }
    }

    /**
     * This function returns response from google api
     * @param $originAddress
     * @return array
     */
    public function wweLTLAddress($originAddress, $planLicenseKey)
    {

        $originAddress = $this->changeWarehouseIdKey($originAddress);
        $post = [
            'acessLevel' => 'MultiDistance',
            'address' => $originAddress,
            'originAddresses' => $originAddress,
            'destinationAddress' => [
                'city' => $this->request['destination']['city'],
                'state' => $this->request['destination']['state'],
                'zip' => $this->request['destination']['zip'],
                'country' => $this->request['destination']['country']
            ],
            'ServerName' => $this->storeData['store']->name,
            'eniureLicenceKey' => $planLicenseKey,
        ];
        $shipping = new Shipping();
        $url = Constant::GOOGLE_URL;

        $curlRes = $shipping->sendCurlRequest($url, $post);
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

        if (isset($additionalData['instore_pickup']) && $additionalData['instore_pickup'] == true) {
            if (!empty($additionalData['instore_pickup_data'])) {
                $inStore = $additionalData['instore_pickup_data'];
                $array['inStorePickup'] = [
                    'addressWithInMiles' => $inStore['miles'],
                    'postalCodeMatch' => $this->checkPostalCodeMatch($receiverZipCode, $inStore['postalCodes']),
                ];
            }
        }

        if (isset($additionalData['local_delivery']) && $additionalData['local_delivery'] == true) {
            if (!empty($additionalData['local_delivery_data']) && $additionalData['local_delivery_data'] != null) {
                $locDel = $additionalData['local_delivery_data'];
                $array['localDelivery'] = [
                    'addressWithInMiles' => $locDel['miles'],
                    'postalCodeMatch' => $this->checkPostalCodeMatch($receiverZipCode, $locDel['postalCodes']),
                    'suppressOtherRates' => isset($additionalData['ld_enable_supress']) && $additionalData['ld_enable_supress'] == true ? 1 : 0,
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

    public function getPlanNumberFromInstalledCarriers($installedCarriers)
    {
        $plansArray = [];
        if (!empty($installedCarriers)) {
            foreach ($installedCarriers as $c1) {
                $connectionSettings = Connection::where('installed_carrier_id', $c1->id)->first();
                if ($connectionSettings === null) {
                    continue;
                }
                $connectionSettings = json_decode($connectionSettings->value);
                $licenseKey = $connectionSettings->license_key;
                $store = Store::find($c1->store_id);

                $query = array(
                    'platform' => '',
                    'carrier' => $this->getCarrierForPlanInfoRequest($c1->id), // required wwltl -> 1, wweSmall -> 2
                    'store_url' => $store->url, // required store url
                    'license_key' => $licenseKey, //required license key
                    'webhook_url' => '',
                    'plugin_version' => '',
                );

                $query = http_build_query($query);
                $end_point = Constant::PLAN_URL . '?' . $query;
                $res = json_decode(file_get_contents($end_point), true);
                if (isset($res['pakg_group'])) {
                    $plansArray[$licenseKey] = $res['pakg_group'];
                    //kkdkdd=3;
                }
            }
            if (in_array(3, $plansArray)) {
                $plansArray = array_flip($plansArray);
                return ['license_key' => $plansArray[3],
                    'pkg' => 3];
            }
            if (in_array(2, $plansArray)) {
                $plansArray = array_flip($plansArray);
                return ['license_key' => $plansArray[2],
                    'pkg' => 2];
            }
            return ['licenseKey' => '',
                'pkg' => 0];
        }
        return ['licenseKey' => '',
            'pkg' => 0];

    }

    public function getCarrierForPlanInfoRequest($installedCarrierId)
    {
        $slug = InstalledCarrier::where('installed_carriers.id', $installedCarrierId)
            ->join('carriers', 'carriers.id', '=', 'installed_carriers.carrier_id')
            ->select('carriers.slug')->first();
        if (!isset($slug->slug)) {
            return 0;
        }
        $slug = $slug->slug;
        switch ($slug) {
            case 'ltl-quotes':
                return 1;
                break;
            case 'small-package':
                return 2;
                break;
            default:
                return 0;
                break;
        }
    }
}
