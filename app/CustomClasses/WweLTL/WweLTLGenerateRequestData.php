<?php

namespace App\CustomClasses\WweLTL;

/**
 * class that generated request data
 */
class WweLTLGenerateRequestData
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
        $this->connectionSettings = $connectionSettings['WweLtl'];
    }

    /**
     * function that generates Wwe array
     * @return array
     */
    public function generateEnitureArray()
    {
        return [
            'licenseKey' => $this->connectionSettings['license_key'],
            'serverName' => "https://store-uann2u.mybigcommerce.com",//"https://store-".$this->storeData['store'].".mybigcommerce.com", //https://store-uann2u.mybigcommerce.com/
            'carrierMode' => 'pro',
            'quotestType' => 'ltl', // ltl / small
            'version' => '1.0.0',
            //'returnQuotesOnExceedWeight' => $this->quoteSettings['WweLtl']['weightExeeds'],
            'liftGateAsAnOption' => $this->quoteSettings['WweLtl']['offerLiftGateDelivery'],
            'api' => $this->getApiInfoArr(),
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
    public function generateRequestArray($request, $originArr, $itemsArr)
    {
        if (count($originArr['originAddress']) > 1) {
            $whIDs = [];
            foreach ($originArr['originAddress'] as $wh) {
                $whIDs[] = $wh['locationId'];
            }
            if (count(array_unique($whIDs)) > 1) {
                foreach ($originArr['originAddress'] as $id => $wh) {
                    if (isset($wh['InstorPickupLocalDelivery'])) {
                        $originArr['originAddress'][$id]['InstorPickupLocalDelivery'] = [];
                    }
                }
            }
        }
        //$carriers = $this->registry->registry('enitureCarriers');
        $carriers['wweLTL'] = $originArr;
        $receiverAddress = $this->getReceiverData($request);

        $autoResidential = $liftGateWithAuto = '0';
        if (isset($this->storeData['installed_addons']['RAD']) && $this->storeData['installed_addons']['RAD']) {
            $autoResidential = '1';
            $liftGateWithAuto = $this->quoteSettings['WweLtl']['RADforLiftgate'] ?? '0';
        }

        return [
            'apiVersion' => '2.0',
            'platform' => 'bigcommerce',
            'binPackagingMultiCarrier' => $this->storeData['installed_addons']['SBS'] ?? '',
            'autoResidentials' => $autoResidential,
            'liftGateWithAutoResidentials' => $liftGateWithAuto,
            'requestKey' => 'asasdasdasdasdasdasdasd',
            'carriers' => $carriers,
            'receiverAddress' => $receiverAddress,
            'commdityDetails' => $itemsArr,
        ];
    }

    /**
     * function that returns API array
     * @return array
     */
    public function getApiInfoArr()
    {
        //Todo: need to review this function
        $accessorials = [];
        if (isset($this->storeData['installed_addons']['RAD']) && !$this->storeData['installed_addons']['RAD']) {
            ($this->quoteSettings['WweLtl']['residentialDlvry']) ? array_push($accessorials, 'RESDEL') : '';
        }
        ($this->quoteSettings['WweLtl']['alwaysLiftGateDelivery']) ? array_push($accessorials, 'LFTGATDEST') : '';

        if (isset($this->storeData['installed_addons']['RAD']) && $this->storeData['installed_addons']['RAD']) {
            $residential = 'N';
        } else {
            $residential = ($this->quoteSettings['WweLtl']['alwaysResidentialDelivery']) ? 'Y' : 'N';
        }

        $liftGate = ($this->quoteSettings['WweLtl']['alwaysLiftGateDelivery'] ||
            $this->quoteSettings['WweLtl']['offerLiftGateDelivery']) ? 'Y' : 'N';

        $apiArray = [
            'speed_freight_username' => $this->connectionSettings['username'],
            'speed_freight_password' => $this->connectionSettings['password'],
            'speed_freight_authentication_key' => $this->connectionSettings['authentication_key'],
            'speed_freight_account_number' => $this->connectionSettings['account_number'],
            'speed_freight_residential_delivery' => $residential,
            'speed_freight_lift_gate_delivery' => $liftGate,
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
