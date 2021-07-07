<?php

namespace App\CustomClasses;

use Illuminate\Support\Facades\DB;
use App\CustomClasses\Bin3D\Bin3D;
/**
 * class that generated request data
 */
class GenerateRequestData
{
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
     * constructor of class that accepts request object
     * @param $quoteSettings
     * @param $connectionSettings
     * @param $storeData
     */
    public function _init(
        $quoteSettings,
        $connectionSettings,
        $storeData
    )
    {
        $this->storeData = $storeData;
        $this->quoteSettings = $quoteSettings;
        $this->connectionSettings = $connectionSettings;
    }

    /**
     * function that generates Wwe array
     * @return array
     */
    public function generateEnitureArray($origin)
    {
        $carriersArr['carriers'] = [];
        //dd($this->connectionSettings);
        foreach ($this->connectionSettings as $key => $con1) {
            switch ($key) {
                case "ltl-quotes":
                    $wweLtlArr = $this->wweLtlEnitArr($con1);
                    $wweLtlArr['originAddress'] = $origin;
                    if (count($wweLtlArr['originAddress']) > 1) {
                        $whIDs = [];
                        foreach ($wweLtlArr['originAddress'] as $wh) {
                            $whIDs[] = $wh['locationId'];
                        }
                        if (count(array_unique($whIDs)) > 1) {
                            foreach ($wweLtlArr['originAddress'] as $id => $wh) {
                                if (isset($wh['InstorPickupLocalDelivery'])) {
                                    $wweLtlArr['originAddress'][$id]['InstorPickupLocalDelivery'] = [];
                                }
                            }
                        }
                    }
                    $carriersArr['carriers']['wweLTL'] = $wweLtlArr;
                    break;
                case "small-package":
                    $wweLtlArr = $this->wweSmallEnitArr($con1);
                    $wweLtlArr['originAddress'] = $origin;
                    if (count($wweLtlArr['originAddress']) > 1) {
                        $whIDs = [];
                        foreach ($wweLtlArr['originAddress'] as $wh) {
                            $whIDs[] = $wh['locationId'];
                        }
                        if (count(array_unique($whIDs)) > 1) {
                            foreach ($wweLtlArr['originAddress'] as $id => $wh) {
                                if (isset($wh['InstorPickupLocalDelivery'])) {
                                    $wweLtlArr['originAddress'][$id]['InstorPickupLocalDelivery'] = [];
                                }
                            }
                        }
                    }
                    $carriersArr['carriers']['wweSmall'] = $wweLtlArr;
                    break;
            }
        }
        return $carriersArr;

    }

    public function wweLtlEnitArr($connSettings)
    {
        //dd($connSettings['quote_settings']);
        return [
            'licenseKey' => $connSettings['creds']['license_key'] ?? '',//$this->connectionSettings['license_key'],
            'serverName' => "https://" . $this->storeData['store']['name'],//"https://store-".$this->storeData['store'].".mybigcommerce.com", //https://store-uann2u.mybigcommerce.com/
            'carrierMode' => 'pro',
            'quotestType' => 'ltl', // ltl / small
            'version' => '1.0.0',
            // 'returnQuotesOnExceedWeight' => $connSettings['quote_settings']['weightExeeds'],
            'returnQuotesOnExceedWeight' => 1,

            'liftGateAsAnOption' => $connSettings['quote_settings']['offerLiftGateDelivery'],
            'api' => $this->getApiInfoArrWweLtl($connSettings),
            'getDistance' => 0,
        ];
    }

    // WWE SMALL QUOTE SETTINGS AND CREDENTIALS

    public function wweSmallEnitArr($connSettings)
    {
        // TODO: Need to set dynamic parameters of wwe small
        return [
            'licenseKey' => $connSettings['creds']['license_key'],//$this->connectionSettings['license_key'],
            'serverName' => "https://" . $this->storeData['store']['name'],//"https://store-".$this->storeData['store'].".mybigcommerce.com", //https://store-uann2u.mybigcommerce.com/
            'carrierMode' => 'pro',
            'quotestType' => 'small', // ltl / small
            'version' => '2.0.4',
            'api' => $this->getApiInfoArrWweSmall($connSettings),
            'getDistance' => 0,
        ];
    }

    /**
     * function for generate request array
     * @param $request
     * @param $originArr
     * @param $itemsArr
     * @return array|bool
     */
    public function generateRequestArray($request, $carriersArray, $itemsArr)
    {
 /*       if (count($carriersArray['originAddress']) > 1) {
            $whIDs = [];
            foreach ($carriersArray['originAddress'] as $wh) {
                $whIDs[] = $wh['locationId'];
            }
            if (count(array_unique($whIDs)) > 1) {
                foreach ($carriersArray['originAddress'] as $id => $wh) {
                    if (isset($wh['InstorPickupLocalDelivery'])) {
                        $carriersArray['originAddress'][$id]['InstorPickupLocalDelivery'] = [];
                    }
                }
            }
        }*/
       // $carriers = $this->registry->registry('enitureCarriers');
        $carriers = $carriersArray['carriers'];
        $receiverAddress = $this->getReceiverData($request);

        $autoResidential = $liftGateWithAuto = '0';
        $isRAD = isset($this->storeData['installed_addons']) && isset($this->storeData['installed_addons'][0]->is_enabled) && isset($this->storeData['installed_addons'][0]->is_enabled) && $this->storeData['installed_addons'][0]->is_enabled == 1 && isset($this->storeData['installed_addons'][0]->is_suspend) && $this->storeData['installed_addons'][0]->is_suspend == 0;
        if ($isRAD) {
            $autoResidential = '1';
            $liftGateWithAuto = '1';
        }
       /*print_r([
            'apiVersion' => '2.0',
            'platform' => 'bigcommerce',
            'dont_auth' => 1,
            'binPackagingMultiCarrier' => $this->storeData['installed_addon_sbs'],
            'autoResidentials' => $autoResidential,
            'liftGateWithAutoResidentials' => $liftGateWithAuto,
            'requestKey' => md5(microtime() . rand()),
            'carriers' => $carriers,
            'receiverAddress' => $receiverAddress,
            'commdityDetails' => $itemsArr,
        ]);
*/
        $binReponse = [];
        if ($this->storeData['installed_addon_sbs'])
        {
            if(isset($carriers['wweSmall'])){
                $olditemsArr = $itemsArr;
                $sbsResponse = $this->getStoreBoxes($this->storeData['store']->id, $itemsArr, $carriers['wweSmall']['originAddress'] );
                $itemsArr = $sbsResponse['items'] ?? $itemsArr;
                $carriers['wweSmall']['originAddress'] = $sbsResponse['originAddress'] ?? $carriers['wweSmall']['originAddress'];
                $binReponse = $sbsResponse['binResponse'];
                if(isset($carriers['wweLTL'])){
                    $itemsArr = $olditemsArr + $itemsArr;
                }
            }
            //print_r($olditemsArr); print_r($itemsArr);

            //print_r($itemsArr); exit;
        }

        $requestArr = [
            'apiVersion' => '2.0',
            'platform' => 'bigcommerce',
            'dont_auth' => 1,
            //'binPackagingMultiCarrier' => $this->storeData['installed_addon_sbs'],
            'autoResidentials' => $autoResidential,
            'liftGateWithAutoResidentials' => $liftGateWithAuto,
            'requestKey' => md5(microtime() . rand()),
            'carriers' => $carriers,
            'receiverAddress' => $receiverAddress,
            'commdityDetails' => $itemsArr,
        ];
        //print_r($requestArr); exit;
        $resp = ['requestArr' => $requestArr, 'binReponse' => $binReponse];
        return $resp;
    }

    /**
     * function that returns API array
     * @return array
     */
    public function getApiInfoArrWweLtl($connSettings)
    {
        //Todo: need to review this function
        $accessorials = [];
        if (isset($this->storeData['installed_addons']['RAD']) && !$this->storeData['installed_addons']['RAD']) {
            ($connSettings['quote_settings']['residentialDlvry']) ? array_push($accessorials, 'RESDEL') : '';
        }
        ($connSettings['quote_settings']['alwaysLiftGateDelivery']) ? array_push($accessorials, 'LFTGATDEST') : '';

        if (isset($this->storeData['installed_addons']['RAD']) && $this->storeData['installed_addons']['RAD']) {
            $residential = 'N';
        } else {
            $residential = ($connSettings['quote_settings']['alwaysResidentialDelivery']) ? 'Y' : 'N';
        }

        $liftGate = ($connSettings['quote_settings']['alwaysLiftGateDelivery'] ||
            $connSettings['quote_settings']['offerLiftGateDelivery']) ? 'Y' : 'N';

        $residentialPickup = ($connSettings['quote_settings']['residentialPickup'] && $connSettings['quote_settings']['residentialPickup'] == true) ? 'Y' : 'N';

        $apiArray = [
            'speed_freight_username' => $connSettings['creds']['username'],
            'speed_freight_password' => $connSettings['creds']['password'],
            'speed_freight_authentication_key' => $connSettings['creds']['authentication_key'],
            'speed_freight_account_number' => $connSettings['creds']['account_number'],
            'speed_freight_residential_delivery' => $residential,
            'speed_freight_lift_gate_delivery' => $liftGate,
            'speed_freight_residential_pickup' => $residentialPickup,
        ];

        //Todo: need to review this functionality
        /*
         * $shipperRelation = $this->getConfigData('shipperRelation');
         * if ($shipperRelation == 'ThirdParty') {
            $apiArray['payerAddress'] = [
                'name' => 'name',
                'addressLine' => 'addressLine',
                'country' => $this->getConfigData('thirdPartyCountry'),
                'zip' => $this->getConfigData('thirdPartyPostalCode'),
                'state' => $this->getConfigData('thirdPartyState'),
                'city' => $this->getConfigData('thirdPartyCity')
            ];
        }*/

        return $apiArray;
    }

    public function getApiInfoArrWweSmall($connSettings)
    {
        //dd($connSettings);
        //Todo: need to review this function
        if (isset($this->storeData['installed_addons']['RAD']) && $this->storeData['installed_addons']['RAD']) {
            $residential = 'N';
        } else {
            $residential = ($connSettings['quote_settings']['alwaysResidentialDelivery']) ? 'Y' : 'N';
        }

        $apiArray = [
            'speed_ship_username' => $connSettings['creds']['username'],
            'speed_ship_password' => $connSettings['creds']['password'],
            'authentication_key' => $connSettings['creds']['authentication_key'],
            'world_wide_express_account_number' => $connSettings['creds']['account_number'],
            'residential_delivery' => $residential,
            'prefferedCurrency' => 'USD',
            'includeDeclaredValue' => "1"
        ];
        //Todo: need to review this functionality
        /*
         * $shipperRelation = $this->getConfigData('shipperRelation');
         * if ($shipperRelation == 'ThirdParty') {
            $apiArray['payerAddress'] = [
                'name' => 'name',
                'addressLine' => 'addressLine',
                'country' => $this->getConfigData('thirdPartyCountry'),
                'zip' => $this->getConfigData('thirdPartyPostalCode'),
                'state' => $this->getConfigData('thirdPartyState'),
                'city' => $this->getConfigData('thirdPartyCity')
            ];
        }*/

        return $apiArray;
    }

    public function getStoreBoxes($storeId, $itemsArr, $origins)
    {
        $items = [];

        foreach ($origins as $key => $origin){
            $items[$origin['locationId']][] = [
                "variant_id" => $key,
                "id" => $key,
                "wg" => $itemsArr[$key]['lineItemWeight'] ?? 0,
                "h" => $itemsArr[$key]['lineItemHeight'] ?? 0,
                "d" => $itemsArr[$key]['lineItemLength'] ?? 0,
                "w" => $itemsArr[$key]['lineItemWidth'] ?? 0,
                "q" => $itemsArr[$key]['piecesOfLineItem'] ?? 0,
                "vr" => 0 //vertical 0 or 1
            ];
        }
        $boxBins = [];
        $boxes = DB::table('box_sizes')->where('store_id', $storeId)
            ->where('is_available', 1)->get();
        foreach ($boxes as $box) {
            $boxBins[$box->id] = array(
                'nickname' => $box->nickname,
                'w' => $box->width,
                'h' => $box->height,
                'd' => $box->length,
                'id' => $box->id,
                'max_wg' => $box->max_weight,
                'box_weight' => $box->box_weight,
            );
        }
        $hits = count($items);
        $Bin3D = new Bin3D();
        $binResponse = $Bin3D->getBinResponse($storeId, $boxBins, $items, $hits);
//print_r($binResponse);
        //print_r($binResponse);
        //todo we have to save $binResponse for order widget
        if(count($binResponse)) {
            $newOrigins = $newitemsArr = [];
            foreach ($items as $locationId => $item) {
                foreach ($item as $itm) {
                    $origin = $itm['variant_id'];
                    $bins = $binResponse[$locationId]->bins_packed ?? [];
                    $hasBins = false;
                    foreach ($bins as $key => $bin) {
                        $newkey = $origin . $key;
                        $newOrigins[$newkey] = $origins[$origin];
                        $newitemsArr[$newkey] = $this->updatCommdityDetails($itemsArr[$origin], $bin, $boxBins);
                        $hasBins = true;
                    }
                    /*if ($hasBins) {
                        unset($origins[$origin]);
                        unset($itemsArr[$origin]);
                    }*/
                    break;
                }
            }
        }else{
            $newOrigins = $origins;
            $newitemsArr = $itemsArr;
        }
        $resp['items'] = $newitemsArr;
        $resp['originAddress'] = $newOrigins;
        $resp['binResponse'] = $binResponse;
        return $resp;

    }

    public function updatCommdityDetails($item, $bin, $boxBins){
        $boxWeight = 0;
        if(isset($bin->bin_data->id) && isset($boxBins[$bin->bin_data->id])){
            $boxWeight = $boxBins[$bin->bin_data->id]['box_weight'];
        }
        $item['lineItemLength'] = $bin->bin_data->d ?? 0;
        $item['lineItemWidth'] = $bin->bin_data->w ?? 0;
        $item['lineItemHeight'] = $bin->bin_data->h ?? 0;
        $item['lineItemWeight'] = $bin->bin_data->weight + $boxWeight;
        $item['piecesOfLineItem'] = 1 ?? 0;
        $item['shipItemAlone'] = 1;
        if(isset($bin->bin_data->type) && $bin->bin_data->type == 'item' && isset($bin->bin_data->id)) {
            $item['variant_id'] = $bin->bin_data->id ?? 0;
        }
        return $item;
    }


    /**
     * This function returns Receiver Data Array
     * @param array $request
     * @return array
     */
    public function getReceiverData(array $request)
    {
        //$addressType = $this->scopeConfig->getValue($addressTypePath, ScopeInterface::SCOPE_STORE);
        return [
            'addressLine' => $request['lineItemData']['destination']['street_1'],
            'receiverCity' => $request['lineItemData']['destination']['city'],
            'receiverState' => $request['lineItemData']['destination']['state'],
            'receiverZip' => preg_replace('/\s+/', '', $request['lineItemData']['destination']['zip']),
            'receiverCountryCode' => $request['lineItemData']['destination']['country'],
            'defaultRADAddressType' => 'residential'//$addressType ?? 'residential', //get value from RAD
        ];
    }

    /**
     *
     * @param $request
     * @return int
     */
    public function checkEnablePickupDelivery($request)
    {
        $getDistance = 0;
        $originArr = $this->registry->registry('shipmentOrigin');
        $idMatchArr = [];

        foreach ($originArr as $origin) {
            if (count($idMatchArr) == 0) {
                $idMatchArr = $origin;
            } else {
                $locationId = $origin['locationId'];
                if ($locationId != $idMatchArr['locationId']) {
                    return 0;
                }
            }
        }

        // Register origin for Addon
        if ($this->registry->registry('pickupDeliveryLocation') === null) {
            $this->registry->register('pickupDeliveryLocation', $idMatchArr);
            $_SESSION['pickupDeliveryLocation'] = $idMatchArr;
        }

        $locationId = $idMatchArr['locationId'];
        $readresult = $this->_connection->query("SELECT enable_store_pickup, miles_store_pickup, match_postal_store_pickup, checkout_desc_store_pickup, enable_local_delivery, miles_local_delivery, match_postal_local_delivery, checkout_desc_local_delivery, fee_local_delivery, suppress_local_delivery FROM " . $this->whTableName . " WHERE warehouse_id IN ('" . $locationId . "')");
        $pickupDlvryOptions = $readresult->fetch();

        $instorePickup = $this->addInstorePickup($pickupDlvryOptions, $request);
        $localDelivery = $this->addLocalDelivery($pickupDlvryOptions, $request);

        if ($instorePickup == 'yes' || $localDelivery == 'yes') {
            $getDistance = 1;
        }

        return $getDistance;
    }

    /**
     *
     * @param $pickupDeliveryOptions
     * @param $request
     * @return string
     */
    public function addInstorePickup($pickupDeliveryOptions, $request)
    {
        $receiver = $this->getReceiverData($request);

        $getMilesGoogleApi = 'no';
        $pickupEnable = $pickupDeliveryOptions['enable_store_pickup'];
        $inStoreZips = $pickupDeliveryOptions['match_postal_store_pickup'];
        if ($pickupEnable == 1) {
            $matchPostals = explode(',', $inStoreZips);
            if (empty($inStoreZips) || !in_array($receiver['receiverZip'], $matchPostals)) {
                $getMilesGoogleApi = 'yes';
            }
        }
        return $getMilesGoogleApi;
    }

    /**
     *
     * @param $pickupDeliveryOptions
     * @param $request
     * @return string
     */
    public function addLocalDelivery($pickupDeliveryOptions, $request)
    {
        $receiver = $this->getReceiverData($request);
        $getMilesGoogleApi = 'no';
        $pickupEnable = $pickupDeliveryOptions['enable_local_delivery'];
        $localDeliveryZips = $pickupDeliveryOptions['match_postal_local_delivery'];
        if ($pickupEnable == 1) {
            $matchPostals = explode(',', $localDeliveryZips);
            if (empty($localDeliveryZips) || !in_array($receiver['receiverZip'], $matchPostals)) {
                $getMilesGoogleApi = 'yes';
            }
        }
        return $getMilesGoogleApi;
    }
}
