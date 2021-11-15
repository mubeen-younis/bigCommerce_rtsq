<?php

namespace App\CustomClasses;

use App\Http\Controllers\BoxSizeController;
use Illuminate\Support\Facades\DB;
use App\CustomClasses\Bin3D\Bin3D;
use App\CustomClasses\SmartyStreet\SmartyStreet;
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

    public $radHitConsumed = 0;
    public $resiCarrier = [];
    public $residential = "N";
    public $origins = [];
    public $itemsArr = [];

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
    ) {
        $this->storeData = $storeData;
        $this->quoteSettings = $quoteSettings;
        $this->connectionSettings = $connectionSettings;
    }

    /**
     * function that generates Wwe array
     * @return array
     */
    public function generateEnitureArray($origin, $destination)
    {
        $carriersArr['carriers'] = [];
        $enitOrigin = $this->getEnitOrigin($origin);
        foreach ($this->connectionSettings as $key => $con1) {
            switch ($key) {
                case "ltl-quotes":
                    $wweLtlArr = $this->wweLtlEnitArr($con1, $destination);
                    $wweLtlArr['originAddress'] = $enitOrigin;
                    $carriersArr['carriers']['wweLTL'] = $wweLtlArr;
                    break;
                case "small-package":
                    $wweLtlArr = $this->wweSmallEnitArr($con1, $destination);
                    $wweLtlArr['originAddress'] = $enitOrigin;
                    $carriersArr['carriers']['wweSmall'] = $wweLtlArr;
                    break;
                case "ups-ltl":
                    $wweLtlArr = $this->upsLtlEnitArr($con1, $destination);
                    $wweLtlArr['originAddress'] = $enitOrigin;
                    $carriersArr['carriers']['upsLTL'] = $wweLtlArr;
                    break;
                case "ups-small":
                    $wweLtlArr = $this->upsSmallEnitArr($con1, $destination);
                    $wweLtlArr['originAddress'] = $enitOrigin;
                    $carriersArr['carriers']['upsSmall'] = $wweLtlArr;
                    break;
                case "fedex-ltl":
                    $wweLtlArr = $this->fedexLtlEnitArr($con1, $destination, $enitOrigin);
                    $wweLtlArr['originAddress'] = $enitOrigin;
                    $carriersArr['carriers']['fedexLTL'] = $wweLtlArr;
                    break;

                case "fedex-small":
                    $wweLtlArr = $this->fedexSmallEnitArr($con1, $destination, $enitOrigin);
                    $wweLtlArr['originAddress'] = $enitOrigin;
                    $carriersArr['carriers']['fedexSmall'] = $wweLtlArr;
                    break;
                case "gtz-ltl":
                    $carName = isset($con1['creds']['api_type']) && $con1['creds']['api_type'] === 'CRS' ? 'cerasis' : 'globalTranz';
                    $wweLtlArr = $this->gtzLtlEnitArr($con1, $destination, $enitOrigin, $carName);

                    $wweLtlArr['originAddress'] = $enitOrigin;

                    $carriersArr['carriers'][$carName] = $wweLtlArr;

                    break;
            }
        }
        return ['carriersArr' => $carriersArr, 'residential' => $this->resiCarrier];
    }


    public function getEnitOrigin($origin){
        $wweLtlArr1['originAddress'] = $origin;

        if (count($wweLtlArr1['originAddress']) > 1) {
            $whIDs = [];
            foreach ($wweLtlArr1['originAddress'] as $wh) {
                if (isset($wh['locationId'])) {
                    $whIDs[] = $wh['locationId'];
                }
            }

            if (count(array_unique($whIDs)) > 1) {
                foreach ($wweLtlArr1['originAddress'] as $id => $wh) {
                    if (isset($wh['InstorPickupLocalDelivery'])) {
                        $wweLtlArr1['originAddress'][$id]['InstorPickupLocalDelivery'] = [];
                    }
                }
            }
        }
        return $wweLtlArr1['originAddress'];
    }

    public function wweLtlEnitArr($connSettings, $destination)
    {
        return [

            'licenseKey' => $connSettings['creds']['license_key'] ?? '',
            'serverName' => "https://" . $this->storeData['store']['name'],
            'carrierMode' => 'pro',
            'quotestType' => 'ltl', // ltl / small
            'version' => '1.0.0',
            // 'returnQuotesOnExceedWeight' => $connSettings['quote_settings']['weightExeeds'],
            'returnQuotesOnExceedWeight' => 1,
            'liftGateAsAnOption' => $connSettings['quote_settings']['offerLiftGateDelivery'] ?? '0',
            'api' => $this->getApiInfoArrWweLtl($connSettings, $destination),
            'getDistance' => 0,
        ];
    }

    function gtzLtlEnitArr($connSettings, $destination, $enitOrigin, $carName){
        $api = $this->getApiInfoArrGTZLtl($connSettings, $destination, $carName);
        return [
            'licenseKey' => $connSettings['creds']['license_key'] ?? '',
            'serverName' => "https://" . $this->storeData['store']['name'],
            'carrierMode' => 'pro',
            'quotestType' => 'ltl', // ltl / small
            'version' => '1.0.0',
            'returnQuotesOnExceedWeight' => 1,
            'liftGateAsAnOption' => isset($api['accessorial']['LFTGATDEST']) ? 1 : 0,
            'api' => $api,
            'getDistance' => 0,
        ];
    }

    // WWE SMALL QUOTE SETTINGS AND CREDENTIALS

    public function wweSmallEnitArr($connSettings, $destination)
    {
        return [
            'licenseKey' => $connSettings['creds']['license_key'] ?? '',
            'serverName' => "https://" . $this->storeData['store']['name'],
            'carrierMode' => 'pro',
            'quotestType' => 'small', // ltl / small
            'version' => '2.0.4',
            'api' => $this->getApiInfoArrWweSmall($connSettings, $destination),
            'getDistance' => 0,
        ];
    }

    public function upsLtlEnitArr($connSettings, $destination)
    {
        return [
            'licenseKey' => $connSettings['creds']['license_key'] ?? '', //$this->connectionSettings['license_key'],
            'serverName' => "https://" . $this->storeData['store']['name'], //"https://store-".$this->storeData['store'].".mybigcommerce.com", //https://store-uann2u.mybigcommerce.com/
            'carrierMode' => 'pro',
            'quotestType' => 'ltl', // ltl / small
            'version' => '1.0.0',
            'returnQuotesOnExceedWeight' => 1,
            'api' => $this->getApiInfoArrUpsLtl($connSettings, $destination),
            'getDistance' => 0,
        ];
    }

    public function upsSmallEnitArr($connSettings, $destination){
        return [
            'licenseKey' => $connSettings['creds']['license_key'] ?? '',
            'serverName' => "https://" . $this->storeData['store']['name'],
            'carrierMode' => 'pro',
            'quotestType' => 'small', // ltl / small
            'version' => '1.0.0',
            'api' => $this->getApiInfoArrUpsSmall($connSettings, $destination),
            'getDistance' => 0,
        ];
    }

    public function fedexSmallEnitArr($connSettings, $destination){
        return [
            'licenseKey' => $connSettings['creds']['license_key'] ?? '',
            'serverName' => "https://" . $this->storeData['store']['name'],
            'carrierMode' => 'pro',
            'quotestType' => 'small', // ltl / small
            'version' => '1.0.0',
            'api' => $this->getApiInfoArrFedexSmall($connSettings, $destination),
            'getDistance' => 0,
        ];
    }

    public function fedexLtlEnitArr($connSettings, $destination, $enitOrigin)
    {
        return [
            'licenseKey' => $connSettings['creds']['license_key'] ?? '', //$this->connectionSettings['license_key'],
            'serverName' => "https://" . $this->storeData['store']['name'], //"https://store-".$this->storeData['store'].".mybigcommerce.com", //https://store-uann2u.mybigcommerce.com/
            'carrierMode' => 'pro',
            'quotestType' => 'ltl', // ltl / small
            'version' => '1.0.0',
            'returnQuotesOnExceedWeight' => 1,
            'api' => $this->getApiInfoArrFedexLtl($connSettings, $destination, $enitOrigin),
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
    public function generateRequestArray($request, $carriersArray, $itemsArr, $cartInfo)
    {
        $carriers = $carriersArray['carriers'];
        $receiverAddress = $this->getReceiverData($request);

        $autoResidential = $liftGateWithAuto = '0';
        $isRAD = isset($this->storeData['installed_addons']) && isset($this->storeData['installed_addons'][0]->is_enabled) && isset($this->storeData['installed_addons'][0]->is_enabled) && $this->storeData['installed_addons'][0]->is_enabled == 1 && isset($this->storeData['installed_addons'][0]->is_suspend) && $this->storeData['installed_addons'][0]->is_suspend == 0;
        if ($isRAD) {
            $autoResidential = '1';
            $liftGateWithAuto = '1';
        }
        $binReponse = $boxBins =[];

        if ($this->storeData['installed_addon_sbs'])
        {
            $this->origins = $carriersoriginAddress = $carriers['wweSmall']['originAddress'] ?? $carriers['upsSmall']['originAddress'];
            $this->itemsArr = $itemsArr;
            $multiplePackaging = $this->handleShipAsMultiplePackaging($carriers, $itemsArr);
            if(empty($multiplePackaging)){
                return null;
            }
            $itemsArr = $multiplePackaging['itemsArr'];
            $isMultishipment = $multiplePackaging['isMultishipment'];
            $carriers = $multiplePackaging['carriers'];
            $hasSmall = isset($carriers['wweSmall'])
                || isset($carriers['upsSmall'])
                || isset($carriers['fedexSmall']);
            if($hasSmall){

                $olditemsArr = $itemsArr;
                $carriersoriginAddress = $carriers['wweSmall']['originAddress']
                    ?? $carriers['upsSmall']['originAddress']
                    ?? $carriers['fedexSmall']['originAddress'];
                $sbsResponse = $this->getStoreBoxes($this->storeData['store']->id, $itemsArr, $carriersoriginAddress, $cartInfo, $isMultishipment );
                $itemsArr = $sbsResponse['items'] ?? $itemsArr;
                if(isset($carriers['wweSmall'])) {
                    $carriers['wweSmall']['originAddress'] = $sbsResponse['originAddress'] ?? $carriersoriginAddress;
                }
                if(isset($carriers['upsSmall'])) {
                    $carriers['upsSmall']['originAddress'] = $sbsResponse['originAddress'] ?? $carriersoriginAddress;
                }
                if(isset($carriers['fedexSmall'])) {
                    $carriers['fedexSmall']['originAddress'] = $sbsResponse['originAddress'] ?? $carriersoriginAddress;
                }
                $binReponse = $sbsResponse['binResponse'];
                $boxBins = $sbsResponse['boxBins'];
                $isLtl = isset($carriers['wweLTL'])
                    || isset($carriers['upsLTL'])
                    || isset($carriers['fedexLTL'])
                    || isset($carriers['cerasis'])
                    || isset($carriers['globalTranz']);
                if($isLtl){
                    $itemsArr = $olditemsArr + $itemsArr;
                }
            }
        }

        $requestArr = [
            'apiVersion' => '2.0',
            'platform' => 'bigcommerce',
            'dont_auth' => 1,
            //'binPackagingMultiCarrier' => $this->storeData['installed_addon_sbs'],
            // 'autoResidentials' => $autoResidential,
            // 'liftGateWithAutoResidentials' => $liftGateWithAuto,
            'requestKey' => md5(microtime() . rand()),
            'carriers' => $carriers,
            'receiverAddress' => $receiverAddress,
            'commdityDetails' => $itemsArr,
        ];
        $resp = ['requestArr' => $requestArr, 'binReponse' => $binReponse, 'boxBins' => $boxBins];
        return $resp;
    }

    /**
     * ship as multiple packaging
     * handle if item marked as ship as multiple packaging
     * get box related to item id and re create items array according to boxes
     */
    public function handleShipAsMultiplePackaging($carriers, $itemsArr){
        //print_r($carriers); print_r($itemsArr); //exit;
        $locationIds = [];
        foreach ($carriers as $carrierName => $carrier){
            //print_r($carrier['originAddress']); exit;
            foreach($carrier['originAddress'] as $varriantId => $origin){
                $isShipAsMultiplePackage = $itemsArr[$varriantId]['shipMultiplePackage'] ?? false;
                if(!in_array($origin['locationId'], $locationIds)){
                    $locationIds[] = (int) $origin['locationId'];
                }
                if($isShipAsMultiplePackage){
                    $boxSizeController = new BoxSizeController();
                    $getBoxes = $boxSizeController->getBoxesByProductId($itemsArr[$varriantId]['id']);
                    if(empty($getBoxes)){
                        return [];
                    }else{
                        foreach ($getBoxes as $key => $box){
                            $key = substr(str_shuffle("0123456789"), 0, 5);
                            $boxFee = $box['box_fee'] ?? 0;
                            $variantId = $this->generateVariantId($itemsArr, 'id',$box['product_id']);
                            $price = $itemsArr[$variantId]['lineItemPrice'] ?? 0;
                            $price = (($price/count($getBoxes))/$box['quantity'])+$boxFee;
                            $itemsArr[$key] = $itemsArr[$varriantId];
                            $itemsArr[$key]['piecesOfLineItem'] = $itemsArr[$key]['piecesOfLineItem']*$box['quantity'];
                            $itemsArr[$key]['lineItemLength'] = $box['length'] ?? 0;
                            $itemsArr[$key]['lineItemWidth'] = $box['width'] ?? 0;
                            $itemsArr[$key]['lineItemHeight'] = $box['height'] ?? 0;
                            $itemsArr[$key]['lineItemWeight'] = $box['weight'] ?? 0;
                            $itemsArr[$key]['lineItemPrice'] = $price;
                            $itemsArr[$key]['shipBinAlone'] = 1;
                            $itemsArr[$key]['boxFee'] = $boxFee;
                            $carriers[$carrierName]['originAddress'][$key] = $origin;
                        }
                        unset($carriers[$carrierName]['originAddress'][$varriantId]);
                    }
                }
            }
        }

        $res = [
            'carriers' => $carriers,
            'itemsArr' => $itemsArr,
            'isMultishipment' => count($locationIds) > 1 ? true: false
        ];
        //print_r($res); exit;
        return $res;
    }

    public function generateVariantId($products, $field, $value){
        foreach($products as $key => $product)
        {
            if ( $product[$field] === $value )
                return $key;
        }
        return 0;
    }


    /**
     * function that returns API array
     * @return array
     */
    public function getApiInfoArrWweLtl($connSettings, $destination)
    {
        $liftGate = ( (isset($connSettings['quote_settings']['alwaysLiftGateDelivery']) && $connSettings['quote_settings']['alwaysLiftGateDelivery']) ||
            (isset($connSettings['quote_settings']['offerLiftGateDelivery']) && $connSettings['quote_settings']['offerLiftGateDelivery'])) ? 'Y' : 'N';
        /*
         * Check if rad hit not consumed and residential is enables
         * **/
        $residential = 'N';
        $alwaysResi = false;
        $radStatus = $this->checkRadIsSuspend($this->storeData['store']['id']);
        if( $this->storeData['installed_addon_rad'] && ( (isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses']))){
            if($this->radHitConsumed == 0){
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($this->storeData['store']['id'], $destination);
                $this->residential = $residential;
            }else{
                $residential = $this->residential;
            }
            if($liftGate != 'Y'){
                $liftGate = ($residential == 'Y' && isset($connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) && $connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) ? 'Y' : 'N';
            }
        }else{
            $alwaysResi = ($radStatus) && (isset($connSettings['quote_settings']['alwaysResidentialDelivery']) && $connSettings['quote_settings']['alwaysResidentialDelivery']) ? true : false;
        }

        $this->resiCarrier['wweLtl'] = $residential;
        $this->resiCarrier['alwaysResi']['wweLtl'] = $alwaysResi;

        $residentialPickup = ( isset($connSettings['quote_settings']['residentialPickup']) && $connSettings['quote_settings']['residentialPickup'] && $connSettings['quote_settings']['residentialPickup'] == true) ? 'Y' : 'N';

        $insurance = [
            'code' => '',
            'value' => ''
        ];
        if(isset($connSettings['quote_settings']['insurance_category'])){
            $insuranceCategory = explode('-', $connSettings['quote_settings']['insurance_category']);
            $insurance = [
                'code' => $insuranceCategory[0] ?? '',
                'value' => $insuranceCategory[1] ?? ''
            ];
        }
        $apiArray = [
            'speed_freight_username' => $connSettings['creds']['username'],
            'speed_freight_password' => $connSettings['creds']['password'],
            'speed_freight_authentication_key' => $connSettings['creds']['authentication_key'],
            'speed_freight_account_number' => $connSettings['creds']['account_number'],
            'speed_freight_residential_delivery' => $alwaysResi ? 'Y' : $residential,
            'speed_freight_lift_gate_delivery' => $liftGate,
            'speed_freight_residential_pickup' => $residentialPickup,
            'insureShipment' => 0,
            'insuranceCategory' => $insurance
        ];
        return $apiArray;
    }

    public function getApiInfoArrGTZLtl($connSettings, $destination, $carName){
        //print_r($connSettings['quote_settings']['show_guaranteed_options']); exit;
        $liftGate = ( (isset($connSettings['quote_settings']['alwaysLiftGateDelivery']) && $connSettings['quote_settings']['alwaysLiftGateDelivery']) ||
            (isset($connSettings['quote_settings']['offerLiftGateDelivery']) && $connSettings['quote_settings']['offerLiftGateDelivery'])) ? 'Y' : 'N';
        /*
         * Check if rad hit not consumed and residential is enables
         * **/
        $residential = 'N';
        $alwaysResi = false;
        $radStatus = $this->checkRadIsSuspend($this->storeData['store']['id']);

        if( $this->storeData['installed_addon_rad'] && ( (isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses']))){

            if($this->radHitConsumed == 0){
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($this->storeData['store']['id'], $destination);
                $this->residential = $residential;

            }else{
                $residential = $this->residential;
            }
            if($liftGate != 'Y'){
                $liftGate = ($residential == 'Y' && isset($connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) && $connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) ? 'Y' : 'N';
            }
        }else{
            $alwaysResi = ($radStatus) && (isset($connSettings['quote_settings']['alwaysResidentialDelivery']) && $connSettings['quote_settings']['alwaysResidentialDelivery']) ? true : false;
        }


        $this->resiCarrier['gtzLtl'] = $residential;
        $this->resiCarrier['alwaysResi']['gtzLtl'] = $alwaysResi;

        $residentialPickup = ( isset($connSettings['quote_settings']['residentialPickup']) && $connSettings['quote_settings']['residentialPickup'] && $connSettings['quote_settings']['residentialPickup'] == true) ? 'Y' : 'N';

        $insurance = [
            'code' => '',
            'value' => ''
        ];
        if(isset($connSettings['quote_settings']['insurance_category'])){
            $insuranceCategory = explode('-', $connSettings['quote_settings']['insurance_category']);
            $insurance = [
                'code' => $insuranceCategory[0] ?? '',
                'value' => $insuranceCategory[1] ?? ''
            ];
        }
        $accessorial = [];

        if($carName === 'globalTranz'){ // for globaltranz
            $notify = (isset($connSettings['quote_settings']['always_quote_notify']) && $connSettings['quote_settings']['always_quote_notify']) || (isset($connSettings['quote_settings']['offer_notify_as_option']) && $connSettings['quote_settings']['offer_notify_as_option']);
            $limitedAccess = $connSettings['quote_settings']['offer_limited_access_delivery'] ?? false;
            if($residential === 'Y' || $alwaysResi) {
                $accessorial['RSD'] = 14;
            }
            if($liftGate === 'Y') {
                $accessorial['LGD'] = 12;
            }
            if($notify){
                $accessorial['NBD'] = 17;
            }
            if($limitedAccess){
                $accessorial['LAD'] = 139;
            }
            $connSettings['creds'] = $connSettings['creds']['global_tranz'];
            $guaranteedService = isset($connSettings['quote_settings']['show_guaranteed_options']) && $connSettings['quote_settings']['show_guaranteed_options'] && isset($connSettings['quote_settings']['showDeliveryEstimate']) && $connSettings['quote_settings']['showDeliveryEstimate'];
            $apiArray = [
                'username' => $connSettings['creds']['user_name'],
                'password' => $connSettings['creds']['password'],
                'accessKey' => $connSettings['creds']['access_key'],
                'customerId' => $connSettings['creds']['customer_id'],
                'version' => '2.0',
                'accessLevel' => 'pro',
                'billingType' => 'Prepaid',
                'handlingUnitWeight' => $connSettings['quote_settings']['weight_of_handling_unit'] ?? '',
                'maxWeightPerHandlingUnit' => $connSettings['quote_settings']['max_weight_per_handling_unit'] ?? '',
                'accessorial' => $accessorial,
                'guaranteedRates' => $guaranteedService
            ];
        }else { // for cerasis
            if($residential === 'Y' || $alwaysResi) {
                $accessorial['RESDEL'] = 'RESDEL';
            }
            if($liftGate === 'Y') {
                $accessorial['LFTGATDEST'] = 'LFTGATDEST';
            }
            $finalMileService = '';
            if(isset($connSettings['quote_settings']['final_mile_service_level']) && $connSettings['quote_settings']['final_mile_service_level']){
                if($connSettings['quote_settings']['final_mile_service_level'] == 'premium'){
                    $finalMileService = 'PREMIUM_FM';
                }else if($connSettings['quote_settings']['final_mile_service_level'] == 'threshold'){
                    $finalMileService = 'THRSHLD_FM';
                }else if($connSettings['quote_settings']['final_mile_service_level'] == 'room_of_choice'){
                    $finalMileService = 'ROOMCHC_FM';
                }
            }
            $connSettings['creds'] = $connSettings['creds']['cerasis'];
            $apiArray = [
                'username' => $connSettings['creds']['user_name'],
                'password' => $connSettings['creds']['password'],
                'accessKey' => $connSettings['creds']['access_key'],
                'shipperID' => $connSettings['creds']['customer_id'],
                'isFinalMile' => isset($connSettings['quote_settings']['shipping_service']) && $connSettings['quote_settings']['shipping_service'] == 'final_mile' ? 1:0,
                'finalMileService' => $finalMileService,
                'cerasisApiVersion' => '2.0',
                'direction' => 'Dropship',
                'billingType' => 'Prepaid',
                'handlingUnitWeight' => $connSettings['quote_settings']['weight_of_handling_unit'] ?? '',
                'maxWeightPerHandlingUnit' => $connSettings['quote_settings']['max_weight_per_handling_unit'] ?? '',
                'accessorial' => $accessorial
            ];
        }
        return $apiArray;
    }

    public function getApiInfoArrFedexLtl($connSettings, $destination, $enitOrigin){
        //print_r($connSettings); exit;
        $liftGate = ( (isset($connSettings['quote_settings']['alwaysLiftGateDelivery']) && $connSettings['quote_settings']['alwaysLiftGateDelivery']) ||
            (isset($connSettings['quote_settings']['offerLiftGateDelivery']) && $connSettings['quote_settings']['offerLiftGateDelivery'])) ? 'Y' : 'N';
        /*
         * Check if rad hit not consumed and residential is enables
         * **/
        $residential = 'N';
        $alwaysResi = false;
        $radStatus = $this->checkRadIsSuspend($this->storeData['store']['id']);
        if( $this->storeData['installed_addon_rad'] && ( (isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses']))){
            if($this->radHitConsumed == 0){
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($this->storeData['store']['id'], $destination);
                $this->residential = $residential;

            }else{
                $residential = $this->residential;
            }
            if($liftGate != 'Y'){
                $liftGate = ($residential == 'Y' && isset($connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) && $connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) ? 'Y' : 'N';
            }
        }else{
            $alwaysResi = ($radStatus) && (isset($connSettings['quote_settings']['alwaysResidentialDelivery']) && $connSettings['quote_settings']['alwaysResidentialDelivery']) ? true : false;
        }


        $this->resiCarrier['fedexLtl'] = $residential;
        $this->resiCarrier['alwaysResi']['fedexLtl'] = $alwaysResi;
        $residentialPickup = ( isset($connSettings['quote_settings']['residentialPickup']) && $connSettings['quote_settings']['residentialPickup'] && $connSettings['quote_settings']['residentialPickup'] == true) ? 'Y' : 'N';

        $accessorial = [];
        if($liftGate == 'Y') {
            array_push($accessorial, 'LIFTGATE_DELIVERY');
        }
        $discount = 0;
        if(isset($connSettings['quote_settings']['account_discount']) && $connSettings['quote_settings']['account_discount'] === 2){
            $discount = (int) $connSettings['quote_settings']['account_discount_price'] ?? 0;
        }
        $isShipper = false;
        if(isset($connSettings['creds']['physical_zip'])){
            foreach ($enitOrigin as $origin){
                if($connSettings['creds']['physical_zip'] === $origin['senderZip']){
                    $isShipper = true;
                    break;
                }
            }
        }
        //print_r($connSettings['creds']); exit;
        $apiArray = [
            'AccountNumber' => $connSettings['creds']['account_number'] ?? '',
            'MeterNumber' => $connSettings['creds']['meter_number'] ?? '',
            'password' => $connSettings['creds']['password'] ?? '',
            'key' => $connSettings['creds']['api_access_key'] ?? '',
            'shippingChargesAccount' => $connSettings['creds']['shipping_account_number'] ?? '',
            'billingLineAddress' => $connSettings['creds']['billing_address'] ?? '',
            'billingCountry' => $connSettings['creds']['billing_country'] ?? '',
            'billingCity' => $connSettings['creds']['billing_city'] ?? '',
            'billingState' => $connSettings['creds']['billing_state'] ?? '',
            'billingZip' => $connSettings['creds']['billing_zip'] ?? '',
            'physicalCountry' => $connSettings['creds']['physical_country'] ?? '',
            'physicalAddress' => $connSettings['creds']['physical_address'] ?? '',
            'physicalCity' => $connSettings['creds']['physical_city'] ?? '',
            'physicalStateOrProvinceCode' => $connSettings['creds']['physical_state'] ?? '',
            'physicalPostalCode' => $connSettings['creds']['physical_zip'] ?? '',
            'shippingChargesBy' => 'SENDER', // valid values RECIPIENT, SENDER and THIRD_PARTY
            'thirdPartyAccount' => $connSettings['creds']['third_party_account'] ?? '',
            'freightAccountType' => 'SENDER', //   SENDER / THIRD_PARTY
            'accountType' => $isShipper ? 'shipper':'thirdParty', // thirdParty / shipper
            //shipper if origin zip and physical address zip is same
            // -------------API INFO------------- //
            'residentialDelivery' => $alwaysResi ? 'Y' : $residential, // Y/N
            'prefferedCurrency' => 'USD',
            'percentDiscount' => $discount, //quote settings
//                'holdAtTerminal' => '1',
            'shipmentDate' => date('m/d/Y'),
            'transactionId' => time(),
            'handlingUnitWeight' => $connSettings['quote_settings']['weight_of_handling_unit'] ?? 0,
            'maxWeightPerHandlingUnit' => $connSettings['quote_settings']['max_weight_per_handling_unit'] ?? 0,
            'role' => 'SHIPPER',

//                'modifyShipmentDateTime' => '0',
//                'OrderCutoffTime' => '16:00',
//                'shipmentOffsetDays' => '4',
//              //  'storeDateTime' => '2019-06-11 16:02:23',
//                'storeDateTime' => date('Y-m-d H:i:s'),

            'paymentType' => 'PREPAID',
            'collectTermsType' => 'STANDARD',
            'Version' => array(
                'ServiceId' => 'crs',
                'Major' => '18',
                'Intermediate' => '0',
                'Minor' => '0'
            ),

            'accessorial' => $accessorial,
            /*array('DANGEROUS_GOODS', 'LIFTGATE_DELIVERY'),*/
        ];


        return $apiArray;
    }

    /*
     * checkRadStatus to check Rad plan if enabled and
     * **/

    private function checkRadStatus($storeId, $address){
        $smarty = new SmartyStreet();
        return $smarty->getSmartyResponse($storeId, $address);
    }

    public function getApiInfoArrWweSmall($connSettings, $destination)
    {
        $residential = 'N';
        $alwaysResi = false;
        $radStatus = $this->checkRadIsSuspend($this->storeData['store']['id']);
        if( $this->storeData['installed_addon_rad'] && (isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses'])){
            if($this->radHitConsumed == 0){
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($this->storeData['store']['id'], $destination);
                $this->residential = $residential;

            }else{
                $residential = $this->residential;
            }

        }else{
            $alwaysResi = ($radStatus) && (isset($connSettings['quote_settings']['alwaysResidentialDelivery']) && $connSettings['quote_settings']['alwaysResidentialDelivery']) ? true : false;
        }

        $this->resiCarrier['wweSmall'] = $residential;
        $this->resiCarrier['alwaysResi']['wweSmall'] = $alwaysResi;
        $apiArray = [
            'speed_ship_username' => $connSettings['creds']['username'],
            'speed_ship_password' => $connSettings['creds']['password'],
            'authentication_key' => $connSettings['creds']['authentication_key'],
            'world_wide_express_account_number' => $connSettings['creds']['account_number'],
            'residentials_delivery' =>  ( $alwaysResi ? 'Y' : $residential == 'Y' ) ? 'yes':'no',
            'prefferedCurrency' => 'USD',
            'includeDeclaredValue' => "1",
        ];

        return $apiArray;
    }

    public function getApiInfoArrUpsSmall($connSettings, $destination){
        $residential = 'N';
        $alwaysResi = false;
        $radStatus = $this->checkRadIsSuspend($this->storeData['store']['id']);
        if( $this->storeData['installed_addon_rad'] && (isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses'])){
            if($this->radHitConsumed == 0){
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($this->storeData['store']['id'], $destination);
                $this->residential = $residential;

            }else{
                $residential = $this->residential;
            }

        }else{
            $alwaysResi = ($radStatus) && (isset($connSettings['quote_settings']['alwaysResidentialDelivery']) && $connSettings['quote_settings']['alwaysResidentialDelivery']) ? true : false;
        }
       // print_r($connSettings['quote_settings']); exit;
        $carrierServices = $connSettings['quote_settings']['carrier_services'] ?? [];
        $this->resiCarrier['upsSmall'] = $residential;
        $this->resiCarrier['alwaysResi']['upsSmall'] = $alwaysResi;
        $apiArray = [
            'ups_small_pkg_username' => $connSettings['creds']['username'],
            'ups_small_pkg_password' => $connSettings['creds']['password'],
            'ups_small_pkg_authentication_key' => $connSettings['creds']['ups_api_access_key'],
            'ups_small_pkg_account_number' => $connSettings['creds']['account_number'],

            'modifyShipmentDateTime' => isset($connSettings['quote_settings']['delivery_estimate_options']) && $connSettings['quote_settings']['delivery_estimate_options'] > 1 ? '1' : '0',
            'OrderCutoffTime' => $connSettings['quote_settings']['order_cut_off_time'] ?? '',
            'shipmentOffsetDays' => $connSettings['quote_settings']['fulfillment_offset_days'] ?? '',
            'storeDateTime' => date("Y-m-d H:i:s"), //2020-10-22 14:00:00
            'shipmentWeekDays' => isset($connSettings['quote_settings']['week_days']) ? $this->getDays($connSettings['quote_settings']['week_days']) : '', //array('1','2','3','4','5'),

            'ups_small_pkg_resid_delivery' =>  ( $alwaysResi ? 'Y' : $residential == 'Y' ) ? 'yes':'no',
            'prefferedCurrency' => 'USD',
            'services'=>[
                'ups_small_pkg_Ground' => $this->issetIndex($carrierServices, 'ups_ground'),
                'ups_small_pkg_3_Day_Select' => $this->issetIndex($carrierServices, 'ups_3_day_select'),

                'ups_small_pkg_2nd_Day_Air' => $this->issetIndex($carrierServices, 'ups_2nd_day_air'),
                'ups_small_pkg_2nd_Day_Air_AM' => $this->issetIndex($carrierServices, 'ups_2nd_day_air_am'),

                'ups_small_pkg_Next_Day_Air' => $this->issetIndex($carrierServices, 'ups_next_day_air'),
                'ups_small_pkg_Next_Day_Air_Saver' => $this->issetIndex($carrierServices, 'ups_next_day_air_saver'),
                'ups_small_pkg_Next_Day_Air_Early_AM' => $this->issetIndex($carrierServices, 'ups_next_day_air_early'),

                "ups_small_surepost_less_than_1LB" => $this->issetIndex($carrierServices, 'ups_surepost_less_than_1lb'),
                "ups_small_surepost_1LB_or_greater" => $this->issetIndex($carrierServices, 'ups_surepost_1lb_or_greater'),
                "ups_small_surepost_bpm" => $this->issetIndex($carrierServices, 'ups_surepost_bound_printed_matter'),
                "ups_small_surepost_media_mail" => $this->issetIndex($carrierServices, 'ups_surepost_media_mail'),
                "ups_small_pkg_Ground_Freight_Pricing" => $this->issetIndex($carrierServices, 'ups_ground_with_freight_pricing'),

                'ups_small_pkg_Standard' => $this->issetIndex($carrierServices, 'ups_standard'),
                'ups_small_pkg_Worldwide_Express' => $this->issetIndex($carrierServices, 'ups_worldwide_express'),
                'ups_small_pkg_Worldwide_Express_Plus' => $this->issetIndex($carrierServices, 'ups_worldwide_express_plus'),
                'ups_small_pkg_Worldwide_Expedited' => $this->issetIndex($carrierServices, 'ups_worldwide_expedited'),
                'ups_small_pkg_Saver' => $this->issetIndex($carrierServices, 'ups_worldwide_saver'),
                'ups_small_pkg_aditional_handling' => $this->issetIndex($carrierServices, 'ups_ground_with_freight_pricing')
            ],
        ];
        return $apiArray;
    }

    function getApiInfoArrFedexSmall($connSettings, $destination){
        $residential = 'N';
        $alwaysResi = false;
        $radStatus = $this->checkRadIsSuspend($this->storeData['store']['id']);
        if( $this->storeData['installed_addon_rad'] && (isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses'])){
            if($this->radHitConsumed == 0){
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($this->storeData['store']['id'], $destination);
                $this->residential = $residential;

            }else{
                $residential = $this->residential;
            }

        }else{
            $alwaysResi = ($radStatus) && (isset($connSettings['quote_settings']['alwaysResidentialDelivery']) && $connSettings['quote_settings']['alwaysResidentialDelivery']) ? true : false;
        }
        $this->resiCarrier['fedexSmall'] = $residential;
        $this->resiCarrier['alwaysResi']['fedexSmall'] = $alwaysResi;
        $apiArray = [

            'modifyShipmentDateTime' => isset($connSettings['quote_settings']['delivery_estimate_options']) && $connSettings['quote_settings']['delivery_estimate_options'] > 1 ? '1' : '0',
            'OrderCutoffTime' => $connSettings['quote_settings']['order_cut_off_time'] ?? '',
            'shipmentOffsetDays' => $connSettings['quote_settings']['fulfillment_offset_days'] ?? '',
            'storeDateTime' => date("Y-m-d H:i:s"), //2020-10-22 14:00:00
            'shipmentWeekDays' => isset($connSettings['quote_settings']['week_days'])?$this->getDays($connSettings['quote_settings']['week_days']):'', //array('1','2','3','4','5'),

            'residentialDelivery' =>  ( $alwaysResi ? 'Y' : $residential == 'Y' ) ? 'on':'off',

            'MeterNumber' => $connSettings['creds']['meter_number'],
            'password' => $connSettings['creds']['password'],
            'key' => $connSettings['creds']['api_access_key'],
            'AccountNumber' => $connSettings['creds']['account_number'],
            'prefferedCurrency' => 'USD',
            'includeDeclaredValue' => '1', //insurance active with sbs active 0 or 1
            'pkgType' => '00',
            'saturdayDelivery' => 'on',
        ];
        return $apiArray;
    }

    private function getDays($days){
        $daysNameKey = ['Monday'=>1, 'Tuesday'=>2, 'Wednesday'=>3, 'Thursday'=>4, 'Friday' => 5];
        $selectedDays = [];
        foreach ($days as $dayName=>$day){
            if(isset($daysNameKey[$day])){
                array_push($selectedDays, $daysNameKey[$day]);
            }
        }
        return $selectedDays;
    }

    private function issetIndex($quoteSettings, $index){
        $resp = 'N';
        if(isset($quoteSettings[$index]) && $quoteSettings[$index] === true) {
            $resp = 'yes';
        }
        return $resp;
    }


    public function getApiInfoArrUpsLtl($connSettings, $destination)
    {
        $liftGate = ( (isset($connSettings['quote_settings']['alwaysLiftGateDelivery']) && $connSettings['quote_settings']['alwaysLiftGateDelivery']) ||
            (isset($connSettings['quote_settings']['offerLiftGateDelivery']) && $connSettings['quote_settings']['offerLiftGateDelivery'])) ? 'Y' : 'N';
        /*
         * Check if rad hit not consumed and residential is enables
         */
        $residential = 'N';
        $alwaysResi = false;
        $radStatus = $this->checkRadIsSuspend($this->storeData['store']['id']);
        if( $this->storeData['installed_addon_rad'] && ( (isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses']))){
            if($this->radHitConsumed == 0){
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($this->storeData['store']['id'], $destination);
                $this->residential = $residential;
            }else{
                $residential = $this->residential;
            }
            if($liftGate != 'Y'){
                $liftGate = ($residential == 'Y' && isset($connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) && $connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) ? 'Y' : 'N';
            }
        }else{
            $alwaysResi = ($radStatus) && (isset($connSettings['quote_settings']['alwaysResidentialDelivery']) && $connSettings['quote_settings']['alwaysResidentialDelivery']) ? true : false;
        }


        //$this->resiCarrier['wweLtl'] = $residential;

        $residentialPickup = ( isset($connSettings['quote_settings']['residentialPickup']) && $connSettings['quote_settings']['residentialPickup'] && $connSettings['quote_settings']['residentialPickup'] == true) ? 'Y' : 'N';
        //print_r($connSettings); dd($liftGate, $residentialPickup);exit;


        $this->resiCarrier['upsLtl'] = $residential;
        $this->resiCarrier['alwaysResi']['upsLtl'] = $alwaysResi;
        $paymentType = isset($connSettings['quote_settings']['shipper_relationship']) && $connSettings['quote_settings']['shipper_relationship'] === 'third_party' ? 'ThirdParty':'shipper';
        $apiArray = [
            'accessLevel' => $connSettings['creds']['access_level'],
            'APIKey' => $connSettings['creds']['ups_api_access_key'],
            'AccountNumber' => $connSettings['creds']['account_number'],
            'UserName' => $connSettings['creds']['username'],
            'Password' => $connSettings['creds']['password'],
            'paymentCode' => '10',
            'paymentDescription' => 'PREPAID',
            'paymentType' => $paymentType,
            //'handlingUnitWeight' => $connSettings['quote_settings']['weight_of_handling_unit'],
            //'maxWeightPerHandlingUnit' => '',
            'serviceCode' => '308',
            'serviceCodeDescription' => 'UPS Freight LTL',
            'timeInTransitIndicator' => 'N',
            'accessorial' => [
                'liftgateDelivery' => $liftGate,
                'residentialDelivery' => $alwaysResi ? 'Y' : $residential,
            ],
            'payerAddress' => [
                'payerName' => 'name',
                'payerAddressLine' => 'address',
                'payerCountryCode' => $connSettings['quote_settings']['third_party_country'] ?? '',
                'payerZip' => $connSettings['quote_settings']['third_party_zip'] ?? '',
                'payerState' => $connSettings['quote_settings']['third_party_state'] ?? '',
                'payerCity' => $connSettings['quote_settings']['third_party_city'] ?? '',
            ],
        ];
        if($apiArray['paymentType'] === 'shipper'){
            unset($apiArray['payerAddress']);
        }
        return $apiArray;
    }

    private function checkRadIsSuspend($storeId)
    {
        $currentPackageSub = DB::table('package_subscriptions as ps')
            ->leftjoin('package_sub_to_be_charge as pstbc', 'pstbc.subscription_id', '=', 'ps.id')
            ->leftjoin('packages as p', 'ps.package_id', '=', 'p.id')
            ->select('ps.id', 'ps.package_id as package_id', 'ps.expiry_time', 'ps.status', 'ps.created_at', 'ps.total_count as consumed_hits', 'p.htis as total_hits', 'pstbc.status as package_to_to_charge_status', 'pstbc.package_id as to_be_charge_package_id')
            ->where('store_id', $storeId)->where('p.addon_type', 'RAD')->latest()->first();
        if(!isset($currentPackageSub->status)){
            return true;
        }else if($currentPackageSub->status == 3){
            return true;
        }else{
            return false;
        }
    }

    public function getStoreBoxes($storeId, $itemsArr, $origins, $cartInfo, $isMultishipment)
    {
        //print_r($origins); print_r($itemsArr); exit;
        $items = $itemsAlone = [];
        foreach ($origins as $key => $origin){
            $isNotLtl = !(isset($itemsArr[$key]['freightClass']) && $itemsArr[$key]['freightClass'] === 'ltl');
            $shipBinAlone = $itemsArr[$key]['shipBinAlone'] ?? false;
            if($isNotLtl) {
                if($shipBinAlone){
                    $itemsAlone[$origin['locationId']][] = [
                        "variant_id" => $key,
                        "id" => $key,
                        "wg" => $itemsArr[$key]['lineItemWeight'] ?? 0,
                        "h" => $itemsArr[$key]['lineItemHeight'] ?? 0,
                        "d" => $itemsArr[$key]['lineItemLength'] ?? 0,
                        "w" => $itemsArr[$key]['lineItemWidth'] ?? 0,
                        "q" => $itemsArr[$key]['piecesOfLineItem'] ?? 0,
                        "vr" => $itemsArr[$key]['vertical_rotation'] ?? 0,
                        "boxFee" => $itemsArr[$key]['boxFee'] ?? 0 //vertical 0 or 1
                    ];
                }else {
                    $items[$origin['locationId']][] = [
                        "variant_id" => $key,
                        "id" => $key,
                        "wg" => $itemsArr[$key]['lineItemWeight'] ?? 0,
                        "h" => $itemsArr[$key]['lineItemHeight'] ?? 0,
                        "d" => $itemsArr[$key]['lineItemLength'] ?? 0,
                        "w" => $itemsArr[$key]['lineItemWidth'] ?? 0,
                        "q" => $itemsArr[$key]['piecesOfLineItem'] ?? 0,
                        "vr" => $itemsArr[$key]['vertical_rotation'] ?? 0 //vertical 0 or 1
                    ];
                }
            }
        }
        //print_r($itemsArr); print_r($items); print_r($itemsAlone); exit;
        $boxBins = $newOrigins = $newitemsArr = [];
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
        if((count($items) && count($boxBins) ) || count($itemsAlone) ) {
            $Bin3D = new Bin3D();
            $binResponse = $Bin3D->getBinResponse($storeId, $boxBins, $items, $itemsAlone, $hits, $cartInfo, $isMultishipment);
            if (count($binResponse)) {
                //print_r($itemsAlone); print_r($items); exit;

                foreach ($itemsAlone as $key => $itemAlone) {
                    foreach ($itemAlone as $alone) {
                        if(count($items) && isset($items[$key])){
                            array_push($items[$key], $alone);
                        }else{
                            $items[$key][] = $alone;
                        }
                    }
                }
                //print_r($items); print_r($binResponse); exit;
                foreach ($items as $locationId => $item) {
                    foreach ($item as $itm) {
                        if(!empty($itm)) {
                            // print_r($itm); exit;
                            $bins = $binResponse[$locationId]->bins_packed ?? [];
                            // print_r($bins); exit;
                            $hasBoth = true;
                            foreach ($bins as $key => $bin) {
                                //$itm = $bin->items;
                                //$origin = $itm[0]->id;
                                //print_r($itm); exit;
                                //print_r($bin); exit;
                                $binId = $bin->bin_data->id;

                                $items = $bin->items;
                                $itemId = $items[0]->id ?? 0;
                                if($itemId !== $binId){
                                    $origin = $itm['id'];
                                    if(isset($itemsArr[$origin]['shipBinAlone']) && $itemsArr[$origin]['shipBinAlone'] == 1){
                                        $newkey = $origin;
                                    }else {
                                        $newkey = $origin . $key;
                                    }
                                    $newOrigins[$newkey] = $origins[$origin];
                                    $newitemsArr[$newkey] = $this->updatCommdityDetails($itemsArr[$origin], $bin, $boxBins, $itemsArr);
                                    $hasBoth = false;
                                }else{
                                    if($hasBoth){
                                        $origin = $itm['id'];
                                        $newkey = $origin;// . $key;
                                        $newOrigins[$newkey] = $origins[$origin];
                                        $newitemsArr[$newkey] = $this->updatCommdityDetails($itemsArr[$origin], $bin, $boxBins, $itemsArr);
                                    }
                                }

                            }
                        }
                        //break;
                    }
                }
            } else {
                $newOrigins = $this->origins;
                $newitemsArr = $this->itemsArr;
            }
        }else{
            $newOrigins = $this->origins;
            $newitemsArr = $this->itemsArr;
        }
        $resp['items'] = $newitemsArr;
        $resp['originAddress'] = $newOrigins;
        $resp['binResponse'] = $binResponse ?? [];
        $resp['boxBins'] = $boxBins;
        //print_r($resp); exit;
        return $resp;

    }

    public function updatCommdityDetails($item, $bin, $boxBins, $itemsArr){

        $boxWeight = 0;
        $price = $item['lineItemPrice'] ?? 0;
        if(isset($bin->bin_data->id) && isset($boxBins[$bin->bin_data->id])){
            $boxWeight = $boxBins[$bin->bin_data->id]['box_weight'];
            $price = 0;
            if(isset($bin->items)) {
                foreach ($bin->items as $itemData){
                    $price += $itemsArr[$itemData->id]['lineItemPrice'] ?? 0;
                }
            }
        }
        $item['lineItemLength'] = $bin->bin_data->d ?? 0;
        $item['lineItemWidth'] = $bin->bin_data->w ?? 0;
        $item['lineItemHeight'] = $bin->bin_data->h ?? 0;
        $item['lineItemPrice'] = $price;//$item['lineItemPrice']*$quantityPacked;
        $item['lineItemWeight'] = $bin->bin_data->weight + $boxWeight;

        //$item['piecesOfLineItem'] = 1 ?? 0;
        $item['shipItemAlone'] = 1;
        if( (isset($item['shipBinAlone']) && $item['shipBinAlone'] == 0 )){
            $item['piecesOfLineItem'] = 1;
        }
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
        return [
            'addressLine' => $request['lineItemData']['destination']['street_1'],
            'receiverCity' => $request['lineItemData']['destination']['city'],
            'receiverState' => $request['lineItemData']['destination']['state'],
            'receiverZip' => preg_replace('/\s+/', '', $request['lineItemData']['destination']['zip']),
            'receiverCountryCode' => $request['lineItemData']['destination']['country'],
            'defaultRADAddressType' => 'residential', //$addressType ?? 'residential', //get value from RAD
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
