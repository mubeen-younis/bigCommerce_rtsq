<?php

namespace App\CustomClasses;

use App\Helpers\Helpers;
use App\Http\Controllers\BoxSizeController;
use Illuminate\Support\Facades\DB;
use App\CustomClasses\Bin3D\Bin3D;
use App\CustomClasses\SmartyStreet\SmartyStreet;
use App\CustomClasses\UspsSmall\QuotesResults as UspsSmallQuotesResults;
use App\CustomClasses\UspsSmall\PackagingRequest as UspsSmallPackagingRequest;
use Illuminate\Support\Facades\Log;

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
    public $oneRate = false;
    public $air = false;
    public $ground = false;
    public $smartPost = false;
    public $fedexType = 'normal';
    public $origins = [];
    public $itemsArr = [];
    public $carriers = [];
    public $isPoBOX = false;

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
    public function generateEnitureArray($origin, $destination, $lineItems)
    {

        $this->destinationIsPOBox($destination);
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
                case "xpo-ltl":
                    $wweLtlArr = $this->xpoLtlEnitArr($con1, $destination, $enitOrigin);
                    $wweLtlArr['originAddress'] = $enitOrigin;
                    $carriersArr['carriers']['xpoLogistics'] = $wweLtlArr;
                    break;
                case "rl-ltl":
                    $wweLtlArr = $this->rnlLtlEnitArr($con1, $destination, $enitOrigin, $lineItems);
                    if (!empty($wweLtlArr)) {
                        $wweLtlArr['originAddress'] = $enitOrigin;
                        $carriersArr['carriers']['rnl'] = $wweLtlArr;
                    }
                    break;
                case 'unishippers-small':
                    $wweLtlArr = $this->unishippersSmallEnitArr($con1, $destination);
                    $wweLtlArr['originAddress'] = $enitOrigin;
                    $carriersArr['carriers']['unishippersSmall'] = $wweLtlArr;
                    break;
                case 'yrc-ltl':
                    $yrcLtlArr = $this->yrcLtlEnitArr($con1, $destination);
                    $yrcLtlArr['originAddress'] = $enitOrigin;
                    $carriersArr['carriers']['yrc'] = $yrcLtlArr;
                    break;
                case 'usps-small':
                    $uspsSmallArr = $this->uspsSmallEnitArr($con1, $destination, $enitOrigin, $lineItems);
                    $uspsSmallArr['originAddress'] = $enitOrigin;
                    $carriersArr['carriers']['usps'] = $uspsSmallArr;
                    break;
            }
        }
        return ['carriersArr' => $carriersArr, 'residential' => $this->resiCarrier];
    }

    function destinationIsPOBox($destination)
    {
        $this->isPoBOX = strpos(strtolower($destination['street_1']), 'po box') !== false
            || strpos(strtolower($destination['street_1']), 'post office box') !== false
            || strpos(strtolower($destination['street_1']), 'p.o. box') !== false
            || strpos(strtolower($destination['street_2']), 'po box') !== false
            || strpos(strtolower($destination['street_2']), 'post office box') !== false
            || strpos(strtolower($destination['street_2']), 'po box') !== false;
    }


    public function getEnitOrigin($origin)
    {
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

    function gtzLtlEnitArr($connSettings, $destination, $enitOrigin, $carName)
    {
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

    public function upsSmallEnitArr($connSettings, $destination)
    {
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

    public function fedexSmallEnitArr($connSettings, $destination)
    {
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

    public function xpoLtlEnitArr($connSettings, $destination, $enitOrigin)
    {
        return [
            'licenseKey' => $connSettings['creds']['license_key'] ?? '', //$this->connectionSettings['license_key'],
            'serverName' => "https://" . $this->storeData['store']['name'], //"https://store-".$this->storeData['store'].".mybigcommerce.com", //https://store-uann2u.mybigcommerce.com/
            'carrierMode' => 'pro',
            'quotestType' => 'ltl', // ltl / small
            'version' => '1.0.0',
            'returnQuotesOnExceedWeight' => 1,
            'api' => $this->getApiInfoArrXPOLtl($connSettings, $destination, $enitOrigin),
            'getDistance' => 0,
        ];
    }

    function rnlLtlEnitArr($connSettings, $destination, $enitOrigin, $lineItems)
    {
        if (isset($connSettings['quote_settings']['returnRates']) && $connSettings['quote_settings']['returnRates'] && $this->isPoBOX) {
            return [];
        }
        $shipmentPrice = $this->calculatePrice($lineItems);
        if (isset($connSettings['quote_settings']['free_shipping_on_orders']) && $connSettings['quote_settings']['free_shipping_on_orders'] < $shipmentPrice) {
            return [
                'freeShipment' => true
            ];
        }
        return [
            'licenseKey' => $connSettings['creds']['license_key'] ?? '', //$this->connectionSettings['license_key'],
            'serverName' => "https://" . $this->storeData['store']['name'], //"https://store-".$this->storeData['store'].".mybigcommerce.com", //https://store-uann2u.mybigcommerce.com/
            'carrierMode' => 'pro',
            'quotestType' => 'ltl', // ltl / small
            'version' => '1.0.0',
            'returnQuotesOnExceedWeight' => 1,
            'api' => $this->getApiInfoArrRNLLtl($connSettings, $destination, $enitOrigin),
            'getDistance' => 0
        ];
    }

    public function unishippersSmallEnitArr($connSettings, $destination)
    {
        return [
            'licenseKey' => $connSettings['creds']['license_key'] ?? '',
            'serverName' => "https://" . $this->storeData['store']['name'],
            'carrierMode' => 'pro',
            'quotestType' => 'small', // ltl / small
            'version' => '1.0.0',
            'api' => $this->getApiInfoArrUnishippersSmall($connSettings, $destination),
            'getDistance' => 0,
        ];
    }

    public function yrcLtlEnitArr($connSettings, $destination)
    {
        return [
            'licenseKey' => $connSettings['creds']['license_key'] ?? '',
            'serverName' => "https://" . $this->storeData['store']['name'],
            'carrierMode' => 'pro',
            'quotestType' => 'ltl', // ltl / small
            'version' => '1.0.0',
            'liftGateAsAnOption' => $connSettings['quote_settings']['offerLiftGateDelivery'] ?? '0',
            'returnQuotesOnExceedWeight' => '1',
            'api' => $this->getApiInfoArrYrcLtl($connSettings, $destination),
        ];
    }

    private function uspsSmallEnitArr($connSettings, $destination, $enitOrigin, $lineItems)
    {
        return [
            'licenseKey' => $connSettings['creds']['license_key'] ?? '',
            'serverName' => "https://" . $this->storeData['store']['name'],
            'carrierMode' => 'pro',
            'quotestType' => 'small',
            'version' => '1.0',
            'returnQuotesOnExceedWeight' => 1,
            'api' => $this->getApiInfoArrUspsSmall($connSettings, $destination, $enitOrigin, $lineItems),
        ];
    }

    function calculatePrice($lineItems)
    {
        $price = 0;
        foreach ($lineItems as $item) {
            $price += $item['originalPiecesOfLineItem'] * $item['lineItemPrice'];
        }
        return $price;
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
        Log::info('Carriers ' . json_encode($carriers));
        $receiverAddress = $this->getReceiverData($request);

        $autoResidential = $liftGateWithAuto = '0';
        //$isRAD = isset($this->storeData['installed_addons']) && isset($this->storeData['installed_addons'][0]->is_enabled) && isset($this->storeData['installed_addons'][0]->is_enabled) && $this->storeData['installed_addons'][0]->is_enabled == 1 && isset($this->storeData['installed_addons'][0]->is_suspend) && $this->storeData['installed_addons'][0]->is_suspend == 0;
        $isRAD = isset($this->storeData['enabled_addon_rad']) && $this->storeData['enabled_addon_rad'];

        if ($isRAD) {
            $autoResidential = '1';
            $liftGateWithAuto = '1';
        }
        $binReponse = $boxBins = [];
        //

        if (isset($this->storeData['enabled_addon_sbs']) && $this->storeData['enabled_addon_sbs']) {
            $this->origins = $carriersoriginAddress = $carriers['wweSmall']['originAddress'] ?? $carriers['upsSmall']['originAddress'] ?? $carriers['fedexSmall']['originAddress'] ?? $carriers['unishippersSmall']['originAddress'] ?? [];
            $this->itemsArr = $itemsArr;
            $this->carriers = $carriers;
            $hasSmall = isset($carriers['wweSmall'])
                || isset($carriers['upsSmall'])
                || isset($carriers['fedexSmall'])
                || isset($carriers['unishippersSmall']);
            if ($hasSmall) {
                $multiplePackaging = $this->handleShipAsMultiplePackaging($carriers, $itemsArr);
                if (empty($multiplePackaging)) {
                    return null;
                }
                $itemsArr = $multiplePackaging['itemsArr'];
                $isMultishipment = $multiplePackaging['isMultishipment'];
                $carriers = $multiplePackaging['carriers'];

                $olditemsArr = $itemsArr;
                $carriersoriginAddress = $carriers['wweSmall']['originAddress']
                    ?? $carriers['upsSmall']['originAddress']
                    ?? $carriers['fedexSmall']['originAddress'] ?? $carriers['unishippersSmall']['originAddress'] ?? [];

                if (isset($carriers['fedexSmall'])) {
                    $this->checkServiceEnabled();
                    if ($this->ground) {
                        $this->fedexType = 'normal'; // ground services
                        $sbsResponseGround = $this->getStoreBoxes($this->storeData['store']->id, $itemsArr, $carriersoriginAddress, $cartInfo, $isMultishipment);
                        $itemsArrGround = $sbsResponseGround['items'] ?? $itemsArr;
                        unset($carriers['fedexSmall']['originAddress']);
                        foreach ($sbsResponseGround['originAddress'] as $key => $origin) {
                            $carriers['fedexSmall']['originAddress'][$key] = $origin;
                        }
                        /*
                       * Added Condition if in case of combination of ups small and fedex small
                       * Only Fedex SMall rates was returning
                       * We need to cater all small carriers here as well
                       * */
                        if (isset($carriers['upsSmall'])) {
                            unset($carriers['upsSmall']['originAddress']);

                            foreach ($sbsResponseGround['originAddress'] as $key => $origin) {
                                $carriers['upsSmall']['originAddress'][$key] = $origin;
                            }
                        }

                        if (isset($carriers['wweSmall'])) {
                            unset($carriers['wweSmall']['originAddress']);

                            foreach ($sbsResponseGround['originAddress'] as $key => $origin) {
                                $carriers['wweSmall']['originAddress'][$key] = $origin;
                            }
                        }

                        if (isset($carriers['unishippersSmall'])) {
                            unset($carriers['unishippersSmall']['originAddress']);

                            foreach ($sbsResponseGround['originAddress'] as $key => $origin) {
                                $carriers['unishippersSmall']['originAddress'][$key] = $origin;
                            }
                        }
                        ///////////////////////////////////////////
                        $binReponse['ground'] = $sbsResponseGround['binResponse'];
                    }

                    if ($this->oneRate) {
                        $this->fedexType = 'fedex'; // one rate services
                        $sbsResponseOneRate = $this->getStoreBoxes($this->storeData['store']->id, $itemsArr, $carriersoriginAddress, $cartInfo, $isMultishipment);
                        if (empty($sbsResponseOneRate['binResponse'])) {
                            $this->oneRate = false;
                        } else {
                            $this->allPacked($sbsResponseOneRate);
                        }
                        if ($this->oneRate) {
                            $itemsArrOneRate = $sbsResponseOneRate['items'] ?? $itemsArr;
                            $commdityDetails['one_rate_commdityDetails'] = $this->lineItems($itemsArrOneRate, $carriers['fedexSmall']['originAddress'], true, $sbsResponseOneRate['binResponse']);
                            $binReponse['oneRate'] = $sbsResponseOneRate['binResponse'];
                        }

                    }

                    if ($this->air) {
                        $this->fedexType = 'both'; // air services
                        $sbsResponseAir = $this->getStoreBoxes($this->storeData['store']->id, $itemsArr, $carriersoriginAddress, $cartInfo, $isMultishipment);
                        $itemsArrAir = $sbsResponseAir['items'] ?? $itemsArr;
                        $commdityDetails['air_services_commdityDetails'] = $this->lineItems($itemsArrAir, $carriers['fedexSmall']['originAddress']);
                        $binReponse['air'] = $sbsResponseAir['binResponse'];

                    }
                    $itemsArr = !empty($itemsArrGround) ? $itemsArrGround : $itemsArr;
                    $sbsResponse['binResponse'] = $binReponse;

                } else {
                    $sbsResponse = $this->getStoreBoxes($this->storeData['store']->id, $itemsArr, $carriersoriginAddress, $cartInfo, $isMultishipment);
                    $itemsArr = $sbsResponse['items'] ?? $itemsArr;
                    if (isset($carriers['wweSmall'])) {
                        $carriers['wweSmall']['originAddress'] = $sbsResponse['originAddress'] ?? $carriersoriginAddress;
                    }
                    if (isset($carriers['upsSmall'])) {
                        $carriers['upsSmall']['originAddress'] = $sbsResponse['originAddress'] ?? $carriersoriginAddress;
                    }
                    if (isset($carriers['unishippersSmall'])) {
                        $carriers['unishippersSmall']['originAddress'] = $sbsResponse['originAddress'] ?? $carriersoriginAddress;
                    }
                }

                $binReponse = $sbsResponse['binResponse'] ?? [];
                $boxBins = $sbsResponse['boxBins'] ?? [];
                $isLtl = isset($carriers['wweLTL'])
                    || isset($carriers['upsLTL'])
                    || isset($carriers['fedexLTL'])
                    || isset($carriers['cerasis'])
                    || isset($carriers['globalTranz'])
                    || isset($carriers['xpoLogistics'])
                    || isset($carriers['yrc']);
                if ($isLtl) {
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
        if (isset($carriers['fedexSmall'])) {
            if ($this->smartPost) {
                $requestArr['FedexSmartPostPricing'] = 1;
            }
            if ($this->air) {
                $requestArr['FedexAirServicesPricing'] = 1;
                $requestArr['air_services_commdityDetails'] = $commdityDetails['air_services_commdityDetails'];
            }
            if ($this->oneRate) {
                $requestArr['FedexOneRatePricing'] = 1;
                $requestArr['one_rate_commdityDetails'] = $commdityDetails['one_rate_commdityDetails'];
            }
        }
        $resp = ['requestArr' => $requestArr, 'binReponse' => $binReponse, 'boxBins' => $boxBins];
        return $resp;
    }

    public function lineItems($items, $origins, $isOneRate = false, $binResponse = [])
    {
        $newItems = [];
        foreach ($items as $key => $item) {
            $locationId = $origins[$key]['locationId'] ?? 1;
            $newItems[$locationId]['lineItems'][] = $item;
            if ($isOneRate) {
                $newItems[$locationId]['one_rate_package_type'] = $binResponse[$locationId]->bins_packed[0]->bin_data->name ?? '';
            }
        }
        return $newItems;
    }

    public function allPacked($sbsResponseOneRate)
    {
        if ($this->oneRate && isset($sbsResponseOneRate['binResponse'])) {
            foreach ($sbsResponseOneRate['binResponse'] as $shipment => $binResponse) {
                $bins_packed = $binResponse->bins_packed ?? [];
                if (!empty($bins_packed)) {
                    foreach ($bins_packed as $packed) {
                        if (isset($packed->bin_data->type) && ($packed->bin_data->type == 'item' || $packed->bin_data->type == 'weight_based')) {
                            $this->oneRate = false;
                            break 2;
                        }
                    }
                } else {
                    $this->oneRate = false;
                    break;
                }
            }
        }
    }

    public function checkServiceEnabled()
    {
        $carrierServices = $this->connectionSettings['fedex-small']['quote_settings']['carrier_services'] ?? [];
        foreach ($carrierServices as $key => $service) {
            $oneRate = ['one_rate_express_saver', 'one_rate_2_day', 'one_rate_2_day_am', 'one_rate_standard_overnight', 'one_rate_priority_overnight', 'one_rate_first_overnight'];
            if (!$this->oneRate && $service && in_array($key, $oneRate)) {
                $this->oneRate = true;
            }

            $ground = ['fedex_home_delivery', 'fedex_appointment_home_delivery', 'fedex_ground', 'international_ground', 'fedex_evening_home_delivery', 'fedex_date_certain_home_delivery', 'fedex_smartpost'];
            if (!$this->ground && $service && in_array($key, $ground)) {
                $this->ground = true;
            }

            // CHecking if we have any fedex box
            if (DB::table('box_sizes')->where('store_id', $this->storeData['store']->id)
                ->where('is_available', 1)->where('box_type', 2)->count()) {
                $air = ['fedex_express_saver', 'fedex_2_day', 'fedex_2_day_am', 'fedex_priority_overnight', 'fedex_first_overnight', 'international_distribution_freight', 'international_economy', 'international_economy_distribution', 'international_economy_freight', 'international_first', 'international_priority', 'international_priority_distribution', 'international_priority_freight', 'priority_overnight', 'standard_overnight'];
                if (!$this->air && $service && in_array($key, $air)) {
                    $this->air = true;
                }
            }
        }

    }

    /**
     * ship as multiple packaging
     * handle if item marked as ship as multiple packaging
     * get box related to item id and re create items array according to boxes
     */
    public function handleShipAsMultiplePackaging($carriers, $itemsArr)
    {
        $locationIds = [];
        foreach ($carriers as $carrierName => $carrier) {
            foreach ($carrier['originAddress'] as $varriantId => $origin) {
                $isShipAsMultiplePackage = $itemsArr[$varriantId]['shipMultiplePackage'] ?? false;
                if (!in_array($origin['locationId'], $locationIds)) {
                    $locationIds[] = (int)$origin['locationId'];
                }
                if ($isShipAsMultiplePackage) {
                    $boxSizeController = new BoxSizeController();
                    $getBoxes = $boxSizeController->getBoxesByProductId($itemsArr[$varriantId]['id']);
                    if (empty($getBoxes)) {
                        return [];
                    } else {
                        foreach ($getBoxes as $key => $box) {
                            $key = substr(str_shuffle("0123456789"), 0, 5);
                            $boxFee = $box['box_fee'] ?? 0;
                            $variantId = $this->generateVariantId($itemsArr, 'id', $box['product_id']);
                            $price = $itemsArr[$variantId]['lineItemPrice'] ?? 0;
                            $price = (($price / count($getBoxes)) / $box['quantity']) + $boxFee;
                            $itemsArr[$key] = $itemsArr[$varriantId];
                            $itemsArr[$key]['piecesOfLineItem'] = $itemsArr[$key]['piecesOfLineItem'] * $box['quantity'];
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
            'isMultishipment' => count($locationIds) > 1 ? true : false
        ];
        return $res;
    }

    public function generateVariantId($products, $field, $value)
    {
        foreach ($products as $key => $product) {
            if ($product[$field] === $value)
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
        $liftGate = ((isset($connSettings['quote_settings']['alwaysLiftGateDelivery']) && $connSettings['quote_settings']['alwaysLiftGateDelivery']) ||
            (isset($connSettings['quote_settings']['offerLiftGateDelivery']) && $connSettings['quote_settings']['offerLiftGateDelivery'])) ? 'Y' : 'N';
        /*
         * Check if rad hit not consumed and residential is enables
         * **/
        $residential = 'N';
        $alwaysResi = false;
        $radStatus = $this->checkRadIsSuspend($this->storeData['store']['id']);
        if ($this->storeData['installed_addon_rad'] && ((isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses']))) {
            if ($this->radHitConsumed == 0) {
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($this->storeData['store']['id'], $destination);
                $this->residential = $residential;
            } else {
                $residential = $this->residential;
            }
            if ($liftGate != 'Y') {
                $liftGate = ($residential == 'Y' && isset($connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) && $connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) ? 'Y' : 'N';
            }
        } else {
            $alwaysResi = $this->checkIsALwaysQuoteResDel($connSettings);
        }

        $this->resiCarrier['wweLtl'] = $residential;
        $this->resiCarrier['alwaysResi']['wweLtl'] = $alwaysResi;

        $residentialPickup = (isset($connSettings['quote_settings']['residentialPickup']) && $connSettings['quote_settings']['residentialPickup'] && $connSettings['quote_settings']['residentialPickup'] == true) ? 'Y' : 'N';

        $insurance = [
            'code' => '',
            'value' => ''
        ];
        if (isset($connSettings['quote_settings']['insurance_category'])) {
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
            'insuranceCategory' => $insurance,
            'handlingUnitWeight' => $connSettings['quote_settings']['weight_of_handling_unit'] ?? '',
            'maxWeightPerHandlingUnit' => $connSettings['quote_settings']['max_weight_per_handling_unit'] ?? '',
        ];
        return array_merge($apiArray, $this->getCutOffDetails($connSettings));

    }

    public function getApiInfoArrGTZLtl($connSettings, $destination, $carName)
    {
        $liftGate = ((isset($connSettings['quote_settings']['alwaysLiftGateDelivery']) && $connSettings['quote_settings']['alwaysLiftGateDelivery']) ||
            (isset($connSettings['quote_settings']['offerLiftGateDelivery']) && $connSettings['quote_settings']['offerLiftGateDelivery'])) ? 'Y' : 'N';
        /*
         * Check if rad hit not consumed and residential is enables
         * **/
        $residential = 'N';
        $alwaysResi = false;
        $radStatus = $this->checkRadIsSuspend($this->storeData['store']['id']);

        if ($this->storeData['installed_addon_rad'] && ((isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses']))) {

            if ($this->radHitConsumed == 0) {
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($this->storeData['store']['id'], $destination);
                $this->residential = $residential;

            } else {
                $residential = $this->residential;
            }
            if ($liftGate != 'Y') {
                $liftGate = ($residential == 'Y' && isset($connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) && $connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) ? 'Y' : 'N';
            }
        } else {
            $alwaysResi = $this->checkIsALwaysQuoteResDel($connSettings);
        }


        $this->resiCarrier['gtzLtl'] = $residential;
        $this->resiCarrier['alwaysResi']['gtzLtl'] = $alwaysResi;

        $residentialPickup = (isset($connSettings['quote_settings']['residentialPickup']) && $connSettings['quote_settings']['residentialPickup'] && $connSettings['quote_settings']['residentialPickup'] == true) ? 'Y' : 'N';

        $insurance = [
            'code' => '',
            'value' => ''
        ];
        if (isset($connSettings['quote_settings']['insurance_category'])) {
            $insuranceCategory = explode('-', $connSettings['quote_settings']['insurance_category']);
            $insurance = [
                'code' => $insuranceCategory[0] ?? '',
                'value' => $insuranceCategory[1] ?? ''
            ];
        }
        $accessorial = [];

        if ($carName === 'globalTranz') { // for globaltranz
            $notify = (isset($connSettings['quote_settings']['always_quote_notify']) && $connSettings['quote_settings']['always_quote_notify']) || (isset($connSettings['quote_settings']['offer_notify_as_option']) && $connSettings['quote_settings']['offer_notify_as_option']);
            $limitedAccess = $connSettings['quote_settings']['offer_limited_access_delivery'] ?? false;
            if ($residential === 'Y' || $alwaysResi) {
                $accessorial['RSD'] = 14;
            }
            if ($liftGate === 'Y') {
                $accessorial['LGD'] = 12;
            }
            if ($notify) {
                $accessorial['NBD'] = 17;
            }
            if ($limitedAccess) {
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
        } else { // for cerasis
            if ($residential === 'Y' || $alwaysResi) {
                $accessorial['RESDEL'] = 'RESDEL';
            }
            if ($liftGate === 'Y') {
                $accessorial['LFTGATDEST'] = 'LFTGATDEST';
            }
            $finalMileService = '';
            if (isset($connSettings['quote_settings']['final_mile_service_level']) && $connSettings['quote_settings']['final_mile_service_level']) {
                if ($connSettings['quote_settings']['final_mile_service_level'] == 'premium') {
                    $finalMileService = 'PREMIUM_FM';
                } else if ($connSettings['quote_settings']['final_mile_service_level'] == 'threshold') {
                    $finalMileService = 'THRSHLD_FM';
                } else if ($connSettings['quote_settings']['final_mile_service_level'] == 'room_of_choice') {
                    $finalMileService = 'ROOMCHC_FM';
                }
            }
            $connSettings['creds'] = $connSettings['creds']['cerasis'];
            $apiArray = [
                'username' => $connSettings['creds']['user_name'],
                'password' => $connSettings['creds']['password'],
                'accessKey' => $connSettings['creds']['access_key'],
                'shipperID' => $connSettings['creds']['customer_id'],
                'isFinalMile' => isset($connSettings['quote_settings']['shipping_service']) && $connSettings['quote_settings']['shipping_service'] == 'final_mile' ? 1 : 0,
                'finalMileService' => $finalMileService,
                'cerasisApiVersion' => '2.0',
                'direction' => 'Dropship',
                'billingType' => 'Prepaid',
                'handlingUnitWeight' => $connSettings['quote_settings']['weight_of_handling_unit'] ?? '',
                'maxWeightPerHandlingUnit' => $connSettings['quote_settings']['max_weight_per_handling_unit'] ?? '',
                'accessorial' => $accessorial
            ];
        }
        return array_merge($apiArray, $this->getCutOffDetails($connSettings));

    }

    public function getApiInfoArrFedexLtl($connSettings, $destination, $enitOrigin)
    {
        $liftGate = ((isset($connSettings['quote_settings']['alwaysLiftGateDelivery']) && $connSettings['quote_settings']['alwaysLiftGateDelivery']) ||
            (isset($connSettings['quote_settings']['offerLiftGateDelivery']) && $connSettings['quote_settings']['offerLiftGateDelivery'])) ? 'Y' : 'N';
        /*
         * Check if rad hit not consumed and residential is enables
         * **/
        $residential = 'N';
        $alwaysResi = false;
        $radStatus = $this->checkRadIsSuspend($this->storeData['store']['id']);
        if ($this->storeData['installed_addon_rad'] && ((isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses']))) {
            if ($this->radHitConsumed == 0) {
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($this->storeData['store']['id'], $destination);
                $this->residential = $residential;

            } else {
                $residential = $this->residential;
            }
            if ($liftGate != 'Y') {
                $liftGate = ($residential == 'Y' && isset($connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) && $connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) ? 'Y' : 'N';
            }
        } else {
            $alwaysResi = $this->checkIsALwaysQuoteResDel($connSettings);
        }


        $this->resiCarrier['fedexLtl'] = $residential;
        $this->resiCarrier['alwaysResi']['fedexLtl'] = $alwaysResi;
        $residentialPickup = (isset($connSettings['quote_settings']['residentialPickup']) && $connSettings['quote_settings']['residentialPickup'] && $connSettings['quote_settings']['residentialPickup'] == true) ? 'Y' : 'N';

        $accessorial = [];
        if ($liftGate == 'Y') {
            array_push($accessorial, 'LIFTGATE_DELIVERY');
        }
        $discount = 0;
        if (isset($connSettings['quote_settings']['account_discount']) && $connSettings['quote_settings']['account_discount'] === 2) {
            $discount = (float)$connSettings['quote_settings']['account_discount_price'] ?? 0;
        }
        $isShipper = false;
        if (isset($connSettings['creds']['physical_zip'])) {
            foreach ($enitOrigin as $origin) {
                if ($connSettings['creds']['physical_zip'] === $origin['senderZip']) {
                    $isShipper = true;
                    break;
                }
            }
        }

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
            'accountType' => $isShipper ? 'shipper' : 'thirdParty', // thirdParty / shipper
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
        return array_merge($apiArray, $this->getCutOffDetails($connSettings));
    }

    function getApiInfoArrXPOLtl($connSettings, $destination, $enitOrigin)
    {
        $liftGate = ((isset($connSettings['quote_settings']['alwaysLiftGateDelivery']) && $connSettings['quote_settings']['alwaysLiftGateDelivery']) ||
            (isset($connSettings['quote_settings']['offerLiftGateDelivery']) && $connSettings['quote_settings']['offerLiftGateDelivery'])) ? 'Y' : 'N';
        /*
         * Check if rad hit not consumed and residential is enables
         * **/
        $residential = 'N';
        $alwaysResi = false;
        $radStatus = $this->checkRadIsSuspend($this->storeData['store']['id']);
        if ($this->storeData['installed_addon_rad'] && ((isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses']))) {
            if ($this->radHitConsumed == 0) {
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($this->storeData['store']['id'], $destination);
                $this->residential = $residential;

            } else {
                $residential = $this->residential;
            }
            if ($liftGate != 'Y') {
                $liftGate = ($residential == 'Y' && isset($connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) && $connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) ? 'Y' : 'N';
            }
        } else {
            $alwaysResi = $this->checkIsALwaysQuoteResDel($connSettings);
        }


        $this->resiCarrier['xpoLtl'] = $residential;
        $this->resiCarrier['alwaysResi']['xpoLtl'] = $alwaysResi;
        $residentialPickup = (isset($connSettings['quote_settings']['residentialPickup']) && $connSettings['quote_settings']['residentialPickup'] && $connSettings['quote_settings']['residentialPickup'] == true) ? 'Y' : 'N';

        $accessorial = [];

        if ($residential === 'Y' || $alwaysResi) {
            $accessorial['RSD'] = 'RSD';
        }
        if ($liftGate === 'Y') {
            $accessorial['DLG'] = 'DLG';
        }
        $apiArray = [
            'UserName' => $connSettings['creds']['username'] ?? '',
            'Password' => $connSettings['creds']['password'] ?? '',
            'CUSTNMBR' => $connSettings['creds']['delivery_account_number'] ?? '',
            'physicalZipCode' => $connSettings['creds']['delivery_postal_code'] ?? '',
            'thirdPartyAccountNumber' => $connSettings['creds']['bill_to_account_number'] ?? '',
            'handlingUnitWeight' => $connSettings['quote_settings']['weight_of_handling_unit'] ?? 0,
            'maxWeightPerHandlingUnit' => $connSettings['quote_settings']['max_weight_per_handling_unit'] ?? 0,
            'accessorial' => $accessorial
        ];

        return array_merge($apiArray, $this->getCutOffDetails($connSettings));
    }

    function getApiInfoArrRNLLtl($connSettings, $destination, $enitOrigin)
    {
        $liftGate = ((isset($connSettings['quote_settings']['alwaysLiftGateDelivery']) && $connSettings['quote_settings']['alwaysLiftGateDelivery']) ||
            (isset($connSettings['quote_settings']['offerLiftGateDelivery']) && $connSettings['quote_settings']['offerLiftGateDelivery'])) ? 'Y' : 'N';
        /*
         * Check if rad hit not consumed and residential is enables
         * **/
        $residential = 'N';
        $alwaysResi = false;
        $radStatus = $this->checkRadIsSuspend($this->storeData['store']['id']);
        if ($this->storeData['installed_addon_rad'] && ((isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses']))) {
            if ($this->radHitConsumed == 0) {
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($this->storeData['store']['id'], $destination);
                $this->residential = $residential;

            } else {
                $residential = $this->residential;
            }
            if ($liftGate != 'Y') {
                $liftGate = ($residential == 'Y' && isset($connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) && $connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) ? 'Y' : 'N';
            }
        } else {
            $alwaysResi = $this->checkIsALwaysQuoteResDel($connSettings);
        }


        $this->resiCarrier['rnlLtl'] = $residential;
        $this->resiCarrier['alwaysResi']['rnlLtl'] = $alwaysResi;

        $apiArray = [
            'UserName' => $connSettings['creds']['username'] ?? '',
            'Password' => $connSettings['creds']['password'] ?? '',
            'APIKey' => $connSettings['creds']['authentication_key'] ?? '',
            'handlingUnitWeight' => $connSettings['quote_settings']['weight_of_handling_unit'] ?? 0,
            'maxWeightPerHandlingUnit' => $connSettings['quote_settings']['max_weight_per_handling_unit'] ?? 0,
            'liftgateDelivery' => $liftGate,
            'residentialDelivery' => $alwaysResi ? 'Y' : $residential,
            'insideDelAsAnOption' => $connSettings['quote_settings']['offer_inside_delivery'] ?? 0,

            'QuoteType' => 'Domestic', //'Domestic or International or AlaskaHawaii'
            'CODAmount' => '0',
            'collectOnDeliveryAmount' => '0',
            'DeclaredValue' => '0',

            'holdAtTerminal' => $connSettings['quote_settings']['hold_at_terminal'] ?? 0,

            /*'modifyShipmentDateTime' => '1',
            'OrderCutoffTime' => '16:00',
            'shipmentOffsetDays' => '2',
            'storeDateTime' => date('Y-m-d H:i:s'),
            'shipmentWeekDays' => array('4','5'),*/
        ];
        return array_merge($apiArray, $this->getCutOffDetails($connSettings));

    }

    /*
     * checkRadStatus to check Rad plan if enabled and
     * **/

    private function checkRadStatus($storeId, $address)
    {
        $smarty = new SmartyStreet();
        return $smarty->getSmartyResponse($storeId, $address);
    }

    public function getApiInfoArrWweSmall($connSettings, $destination)
    {
        $residential = 'N';
        $alwaysResi = false;
        $radStatus = $this->checkRadIsSuspend($this->storeData['store']['id']);
        if ($this->storeData['installed_addon_rad'] && (isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses'])) {
            if ($this->radHitConsumed == 0) {
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($this->storeData['store']['id'], $destination);
                $this->residential = $residential;

            } else {
                $residential = $this->residential;
            }

        } else {
            $alwaysResi = $this->checkIsALwaysQuoteResDel($connSettings);
        }

        $this->resiCarrier['wweSmall'] = $residential;
        $this->resiCarrier['alwaysResi']['wweSmall'] = $alwaysResi;
        $apiArray = [
            'speed_ship_username' => $connSettings['creds']['username'],
            'speed_ship_password' => $connSettings['creds']['password'],
            'authentication_key' => $connSettings['creds']['authentication_key'],
            'world_wide_express_account_number' => $connSettings['creds']['account_number'],
            'residentials_delivery' => ($alwaysResi ? 'Y' : $residential == 'Y') ? 'yes' : 'no',
            'prefferedCurrency' => 'USD',
            'includeDeclaredValue' => "1",
        ];
        return array_merge($apiArray, $this->getCutOffDetails($connSettings));

    }

    public function getApiInfoArrUpsSmall($connSettings, $destination)
    {
        $residential = 'N';
        $alwaysResi = false;
        $radStatus = $this->checkRadIsSuspend($this->storeData['store']['id']);
        if ($this->storeData['installed_addon_rad'] && (isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses'])) {
            if ($this->radHitConsumed == 0) {
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($this->storeData['store']['id'], $destination);
                $this->residential = $residential;

            } else {
                $residential = $this->residential;
            }

        } else {
            $alwaysResi = $this->checkIsALwaysQuoteResDel($connSettings);
        }
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

            'ups_small_pkg_resid_delivery' => ($alwaysResi ? 'Y' : $residential == 'Y') ? 'yes' : 'no',
            'prefferedCurrency' => 'USD',
            'services' => [
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

    function getApiInfoArrFedexSmall($connSettings, $destination)
    {
        $residential = 'N';
        $alwaysResi = false;
        $radStatus = $this->checkRadIsSuspend($this->storeData['store']['id']);
        if ($this->storeData['installed_addon_rad'] && (isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses'])) {
            if ($this->radHitConsumed == 0) {
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($this->storeData['store']['id'], $destination);
                $this->residential = $residential;

            } else {
                $residential = $this->residential;
            }

        } else {
            $alwaysResi = $this->checkIsALwaysQuoteResDel($connSettings);
        }
        $this->setIsSmartPost($connSettings);
        $this->resiCarrier['fedexSmall'] = $residential;
        $this->resiCarrier['alwaysResi']['fedexSmall'] = $alwaysResi;
        $hubIdindicia = isset($connSettings['creds']['hub_id']) ? explode('(', $connSettings['creds']['hub_id']) : '';
        $hubId = isset($hubIdindicia[0]) ? trim($hubIdindicia[0]) : '';
        $indicia = 'PARCEL_SELECT';//trim(explode(')',$hubIdindicia[1])[0]);
        $apiArray = [

            'modifyShipmentDateTime' => isset($connSettings['quote_settings']['delivery_estimate_options']) && $connSettings['quote_settings']['delivery_estimate_options'] > 1 ? '1' : '0',
            'OrderCutoffTime' => $connSettings['quote_settings']['order_cut_off_time'] ?? '',
            'shipmentOffsetDays' => $connSettings['quote_settings']['fulfillment_offset_days'] ?? '',
            'storeDateTime' => date("Y-m-d H:i:s"), //2020-10-22 14:00:00
            'shipmentWeekDays' => isset($connSettings['quote_settings']['week_days']) ? $this->getDays($connSettings['quote_settings']['week_days']) : '', //array('1','2','3','4','5'),

            'residentialDelivery' => ($alwaysResi ? 'Y' : $residential == 'Y') ? 'on' : 'off',

            'MeterNumber' => $connSettings['creds']['meter_number'],
            'password' => $connSettings['creds']['password'],
            'key' => $connSettings['creds']['api_access_key'],
            'AccountNumber' => $connSettings['creds']['account_number'],
            'prefferedCurrency' => 'USD',
            'includeDeclaredValue' => '1', //insurance active with sbs active 0 or 1
            'pkgType' => '00',
            'saturdayDelivery' => 'on'
        ];
        if ($this->smartPost) {
            $apiArray['smartPostData'] = [
                'hubId' => $hubId,
                'indicia' => $indicia
            ];
        }
        return $apiArray;
    }

    public function getApiInfoArrUnishippersSmall($connSettings, $destination)
    {
        $residential = 'N';
        $alwaysResi = false;

        if ($this->storeData['installed_addon_rad'] && (isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses'])) {
            if ($this->radHitConsumed == 0) {
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($this->storeData['store']['id'], $destination);
                $this->residential = $residential;

            } else {
                $residential = $this->residential;
            }

        } else {
            $alwaysResi = $this->checkIsALwaysQuoteResDel($connSettings);
        }

        $this->resiCarrier['unishippersSmall'] = $residential;
        $this->resiCarrier['alwaysResi']['unishippersSmall'] = $alwaysResi;
        $accessorial = ($alwaysResi ? 'Y' : $residential == 'Y') ? ['REP'] : [];

        $apiArray = [
            'username' => $connSettings['creds']['username'],
            'password' => $connSettings['creds']['password'],
            'requestkey' => $connSettings['creds']['request_key'] ?? '',
            'upsaccountnumber' => $connSettings['creds']['ups_account_number'],
            'unishipperscustomernumber' => $connSettings['creds']['unishippers_customer_number'],
            'packagetype' => 'P',
            'doNesting' => '0',

            'modifyShipmentDateTime' => isset($connSettings['quote_settings']['delivery_estimate_options']) && $connSettings['quote_settings']['delivery_estimate_options'] > 1 ? '1' : '0',
            'OrderCutoffTime' => $connSettings['quote_settings']['order_cut_off_time'] ?? '',
            'shipmentOffsetDays' => $connSettings['quote_settings']['fulfillment_offset_days'] ?? '',
            'storeDateTime' => date("Y-m-d H:i:s"), //2020-10-22 14:00:00
            'shipmentWeekDays' => isset($connSettings['quote_settings']['week_days']) ? $this->getDays($connSettings['quote_settings']['week_days']) : '', //array('1','2','3','4','5'),

            'prefferedCurrency' => 'USD',
            'includeDeclaredValue' => '1',
            'service' => 'ALL',
            'accessorial' => $accessorial,
            'residentials_delivery' => isset($accessorial) && !blank($accessorial) ? 'yes' : 'no'
        ];

        return $apiArray;
    }

    public function getApiInfoArrYrcLtl($connSettings, $destination)
    {
        $liftGate = ((isset($connSettings['quote_settings']['alwaysLiftGateDelivery']) && $connSettings['quote_settings']['alwaysLiftGateDelivery']) ||
            (isset($connSettings['quote_settings']['offerLiftGateDelivery']) && $connSettings['quote_settings']['offerLiftGateDelivery'])) ? 'Y' : 'N';
            
        $residential = 'N';
        $alwaysResi = false;            
        /*
            * Check if rad hit not consumed and residential is enables
        * **/
        if ($this->storeData['installed_addon_rad'] && ((isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses']))) {
            if ($this->radHitConsumed == 0) {
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($this->storeData['store']['id'], $destination);
                $this->residential = $residential;
            } else {
                $residential = $this->residential;
            }
            if ($liftGate != 'Y') {
                $liftGate = ($residential == 'Y' && isset($connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) && $connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) ? 'Y' : 'N';
            }
        } else {
            $alwaysResi = $this->checkIsALwaysQuoteResDel($connSettings);
        }

        $this->resiCarrier['yrcLtl'] = $residential;
        $this->resiCarrier['alwaysResi']['yrcLtl'] = $alwaysResi;

        $accessorial = [];
        if($alwaysResi || $residential == 'Y'){
            array_push($accessorial, 'HOMD');
        }
        if ($liftGate == 'Y') {
            array_push($accessorial, 'LFTD');
        }

        $apiArray = [
            'userId' => $connSettings['creds']['username'],
            'password' => $connSettings['creds']['password'],
            'busId' => $connSettings['creds']['business_id'],
            'dimWeightBaseAccount' => $connSettings['creds']['yrc_rates'],
            // -------------------------- //
            'RequestOption' => 'Rate',
            'ServiceClass' => 'STD',

            // -------------API INFO------------- //
            'prefferedCurrency' => 'USD',
            'handlingUnitWeight' => $connSettings['quote_settings']['weight_of_handling_unit'] ?? 0,
            'maxWeightPerHandlingUnit' => $connSettings['quote_settings']['max_weight_per_handling_unit'] ?? 0,
            'accessorial' => $accessorial,
        ];

        return array_merge($apiArray, $this->getCutOffDetails($connSettings));
    }

    private function getApiInfoArrUspsSmall($connSettings, $destination, $enitOrigin, $lineItems)
    {
        $residential = 'N';
        $alwaysResi = false;
        $uspsSmallQuotesResutls = new UspsSmallQuotesResults();
        $uspsSmallPkgReq = new UspsSmallPackagingRequest();
        $storeId = $this->storeData['store']['id'] ?? '';

        if ($this->storeData['installed_addon_rad'] && (isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses'])) {
            if ($this->radHitConsumed == 0) {
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($storeId, $destination);
                $this->residential = $residential;

            } else {
                $residential = $this->residential;
            }

        } else {
            $alwaysResi = $this->checkIsALwaysQuoteResDel($connSettings);
        }

        $sbsEnabled = isset($this->storeData['enabled_addon_sbs']) && $this->storeData['enabled_addon_sbs'] ?? false;
        $this->resiCarrier['uspsSmall'] = $residential;
        $this->resiCarrier['alwaysResi']['uspsSmall'] = $alwaysResi;
        $carrierServices = $connSettings['quote_settings']['carrier_services'] ?? [];
        $apiArray = [
            'rateTier' => $connSettings['quote_settings']['rate_tier'] ?? 'retail', //retail, commercialBase, commercialPlus
            'includeDeclaredValue' => '1',
            'activeServices' => $uspsSmallQuotesResutls->getUspsActiveServices($carrierServices),
            // if packaging successfully done by SBS
            'sbsPackaging' => $sbsEnabled ? '1' : '0',
        ];

        $req = $uspsSmallPkgReq->setUspsPckgEligAndUspsBoxes($storeId, $connSettings, $enitOrigin, $lineItems);
        $wsBoxesReq = $req['wsBoxesReq'] ?? [];
        $apiArray['binsReqArr'] = $wsBoxesReq;

        $apiArray = array_merge($apiArray, $this->getCutOffDetails($connSettings));
        dd(1351, $apiArray);
        return $apiArray;
    }

    public function setIsSMartPost($connectionSettings)
    {
        if (isset($connectionSettings['quote_settings']['carrier_services']['fedex_smart_post']) &&
            $connectionSettings['quote_settings']['carrier_services']['fedex_smart_post']) {
            $this->smartPost = true;
        }
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

    private function issetIndex($quoteSettings, $index)
    {
        $resp = 'N';
        if (isset($quoteSettings[$index]) && $quoteSettings[$index] === true) {
            $resp = 'yes';
        }
        return $resp;
    }


    public function getApiInfoArrUpsLtl($connSettings, $destination)
    {
        $liftGate = ((isset($connSettings['quote_settings']['alwaysLiftGateDelivery']) && $connSettings['quote_settings']['alwaysLiftGateDelivery']) ||
            (isset($connSettings['quote_settings']['offerLiftGateDelivery']) && $connSettings['quote_settings']['offerLiftGateDelivery'])) ? 'Y' : 'N';
        /*
         * Check if rad hit not consumed and residential is enables
         */
        $residential = 'N';
        $alwaysResi = false;
        $radStatus = $this->checkRadIsSuspend($this->storeData['store']['id']);
        if ($this->storeData['installed_addon_rad'] && ((isset($connSettings['quote_settings']['autoDetectedResidentialAddresses']) && $connSettings['quote_settings']['autoDetectedResidentialAddresses']))) {
            if ($this->radHitConsumed == 0) {
                $this->radHitConsumed = 1;
                $residential = $this->checkRadStatus($this->storeData['store']['id'], $destination);
                $this->residential = $residential;
            } else {
                $residential = $this->residential;
            }
            if ($liftGate != 'Y') {
                $liftGate = ($residential == 'Y' && isset($connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) && $connSettings['quote_settings']['autoDetectedResidentialAddressesLfg']) ? 'Y' : 'N';
            }
        } else {
            $alwaysResi = $this->checkIsALwaysQuoteResDel($connSettings);
        }


        //$this->resiCarrier['wweLtl'] = $residential;

        $residentialPickup = (isset($connSettings['quote_settings']['residentialPickup']) && $connSettings['quote_settings']['residentialPickup'] && $connSettings['quote_settings']['residentialPickup'] == true) ? 'Y' : 'N';


        $this->resiCarrier['upsLtl'] = $residential;
        $this->resiCarrier['alwaysResi']['upsLtl'] = $alwaysResi;
        $paymentType = isset($connSettings['quote_settings']['shipper_relationship']) && $connSettings['quote_settings']['shipper_relationship'] === 'third_party' ? 'ThirdParty' : 'shipper';
        $apiArray = [
            'accessLevel' => $connSettings['creds']['access_level'],
            'APIKey' => $connSettings['creds']['ups_api_access_key'],
            'AccountNumber' => $connSettings['creds']['account_number'],
            'UserName' => $connSettings['creds']['username'],
            'Password' => $connSettings['creds']['password'],
            'paymentCode' => '10',
            'paymentDescription' => 'PREPAID',
            'paymentType' => $paymentType,
            'handlingUnitWeight' => $connSettings['quote_settings']['weight_of_handling_unit'] ?? '',
            'maxWeightPerHandlingUnit' => $connSettings['quote_settings']['max_weight_per_handling_unit'] ?? '',
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
        if ($apiArray['paymentType'] === 'shipper') {
            unset($apiArray['payerAddress']);
        }
        return array_merge($apiArray, $this->getCutOffDetails($connSettings));
    }

    private function checkRadIsSuspend($storeId)
    {
        $currentPackageSub = DB::table('package_subscriptions as ps')
            ->leftjoin('package_sub_to_be_charge as pstbc', 'pstbc.subscription_id', '=', 'ps.id')
            ->leftjoin('packages as p', 'ps.package_id', '=', 'p.id')
            ->select('ps.id', 'ps.package_id as package_id', 'ps.expiry_time', 'ps.status', 'ps.created_at', 'ps.total_count as consumed_hits', 'p.htis as total_hits', 'pstbc.status as package_to_to_charge_status', 'pstbc.package_id as to_be_charge_package_id')
            ->where('store_id', $storeId)->where('p.addon_type', 'RAD')->latest()->first();
        if (!isset($currentPackageSub->status)) {
            return true;
        } else if ($currentPackageSub->status == 3) {
            return true;
        } else {
            return false;
        }
    }


    public function getStoreBoxes($storeId, $itemsArr, $origins, $cartInfo, $isMultishipment)
    {
        $items = $itemsAlone = [];
        foreach ($origins as $key => $origin) {
            $isNotLtl = !(isset($itemsArr[$key]['freightClass']) && $itemsArr[$key]['freightClass'] === 'ltl');
            $shipBinAlone = $itemsArr[$key]['shipBinAlone'] ?? false;
            $weightBasedItem = $itemsArr[$key]['exclude_packaging'] ?? false;
            if ($isNotLtl) {
                /*Added COndition after not requiring dimesnions*/
                if ($weightBasedItem) {
                    $itemsAlone[$origin['locationId']][] = [
                        "variant_id" => $key,
                        "id" => $key,
                        "wg" => $itemsArr[$key]['lineItemWeight'] ?? 0,
                        "h" => Helpers::floatValue($itemsArr[$key]['lineItemHeight'] ?? 0),
                        "d" => Helpers::floatValue($itemsArr[$key]['lineItemLength'] ?? 0),
                        "w" => Helpers::floatValue($itemsArr[$key]['lineItemWidth'] ?? 0),
                        "q" => $itemsArr[$key]['piecesOfLineItem'] ?? 0,
                        "vr" => 0,//vertical 0 or 1
                        "boxFee" => 0,
                        "weight_based" => 1
                    ];
                } elseif ($shipBinAlone) {
                    $itemsAlone[$origin['locationId']][] = [
                        "variant_id" => $key,
                        "id" => $key,
                        "wg" => $itemsArr[$key]['lineItemWeight'] ?? 0,
                        "h" => Helpers::floatValue($itemsArr[$key]['lineItemHeight'] ?? 0),
                        "d" => Helpers::floatValue($itemsArr[$key]['lineItemLength'] ?? 0),
                        "w" => Helpers::floatValue($itemsArr[$key]['lineItemWidth'] ?? 0),
                        "q" => $itemsArr[$key]['piecesOfLineItem'] ?? 0,
                        "vr" => $itemsArr[$key]['vertical_rotation'] ?? 0,//vertical 0 or 1
                        "boxFee" => $itemsArr[$key]['boxFee'] ?? 0
                    ];
                } else {
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

        if (!empty($itemsAlone)) {
            $this->oneRate = false;
        }

        $boxBins = $newOrigins = $newitemsArr = [];
        switch ($this->fedexType) {
            case 'normal':
                $boxes = DB::table('box_sizes')->where('store_id', $storeId)
                    ->where('is_available', 1)->where('box_type', 1)->get();
                break;
            case 'fedex':
                $boxes = DB::table('box_sizes')->where('store_id', $storeId)
                    ->where('is_available', 1)->where('box_type', 2)->get();
                break;
            case 'both':
                $boxes = DB::table('box_sizes')->where('store_id', $storeId)
                    ->where('is_available', 1)->get();
                break;
        }
        /*$boxes = DB::table('box_sizes')->where('store_id', $storeId)
            ->where('is_available', 1)->get();*/
        foreach ($boxes as $box) {
            $boxBins[$box->id] = array(
                'nickname' => $box->nickname,
                'name' => $box->box_name,
                'w' => $box->width,
                'h' => $box->height,
                'd' => $box->length,
                'id' => $box->id,
                'max_wg' => $box->max_weight,
                'box_weight' => $box->box_weight,
                /*Start- Added in case of Customer removes external dimesnions and bin request log issue
                NO use of it in3dbin Request
                Just adding in array For Request Hash*/
                'ext_width' => $box->ext_width ?? 0,
                'ext_length' => $box->ext_length ?? 0,
                'ext_height' => $box->ext_height ?? 0
                /*END*/
            );
        }
        $hits = count($items);
        if ((count($items) && count($boxBins)) || count($itemsAlone)) {
            $Bin3D = new Bin3D();
            $binResponse = $Bin3D->getBinResponse($storeId, $boxBins, $items, $itemsAlone, $hits, $cartInfo, $isMultishipment);
            if (count($binResponse)) {
                foreach ($itemsAlone as $key => $itemAlone) {
                    foreach ($itemAlone as $alone) {
                        if (count($items) && isset($items[$key])) {
                            array_push($items[$key], $alone);
                        } else {
                            $items[$key][] = $alone;
                        }
                    }
                }
                $binResponse = $this->addPackagingID($binResponse, $boxBins);
                $counting = 0;
                $counting = 0;

                foreach ($binResponse as $locationId => $bins) {
                    foreach ($bins->bins_packed as $key => $binPacked) {
                        $bin = $binPacked;
                        $counting++;
                        $origin = $bin->bin_data->variant_id;
                        // dd(12,$binResponse,$itemsArr[$origin]);

                        $newkey = $origin . $key;
                        $newOrigins[$newkey] = $origins[$origin];
                        $newitemsArr[$newkey] = $this->updatCommdityDetails($itemsArr[$origin], $bin, $boxBins, $itemsArr);
                    }
                }
            } else {
                $newOrigins = $this->origins;
                $newitemsArr = $this->itemsArr;
            }
        } else {

            $newOrigins = $this->origins;
            $newitemsArr = $this->itemsArr;
        }
        $resp['items'] = $newitemsArr;
        $resp['originAddress'] = $newOrigins;
        $resp['binResponse'] = $binResponse ?? [];
        $resp['boxBins'] = $boxBins;
        return $resp;

    }

    public static function floatValue($number = 0)
    {
        if ($number == 0) {
            return $number;
        }
        $number = rtrim($number, '0');                // 50,00 --> 50,
        $number = rtrim($number, '.'); // 50,   --> 50
        return $number;

    }

    public function updatCommdityDetails($item, $bin, $boxBins, $itemsArr)
    {
        $boxWeight = 0;
        $price = $item['lineItemPrice'] ?? 0;
        $hazmat = 'N';
        if (isset($bin->bin_data->id) && isset($boxBins[$bin->bin_data->id])) {
            $boxWeight = $boxBins[$bin->bin_data->id]['box_weight'];
            $price = 0;
            if (isset($bin->items)) {
                foreach ($bin->items as $itemData) {
                    if ($hazmat == 'N') {
                        $hazmat = $itemsArr[$itemData->id]['isHazmatLineItem'];
                    }
                    $price += $itemsArr[$itemData->id]['lineItemPrice'] ?? 0;
                }
            }
        }
        $item['lineItemLength'] = $bin->bin_data->d ?? 0;
        $item['lineItemWidth'] = $bin->bin_data->w ?? 0;
        $item['lineItemHeight'] = $bin->bin_data->h ?? 0;
        $item['lineItemPrice'] = $price;//$item['lineItemPrice']*$quantityPacked;
        $item['lineItemWeight'] = $bin->bin_data->weight + $boxWeight;
        $item['isHazmatLineItem'] = $hazmat;


        //$item['piecesOfLineItem'] = 1 ?? 0;
        $item['shipItemAlone'] = 1;
        if ((isset($item['shipBinAlone']) && $item['shipBinAlone'] == 0)) {
            $item['piecesOfLineItem'] = 1;
        }
        if (isset($bin->bin_data->type) && $bin->bin_data->type == 'item' && isset($bin->bin_data->id)) {
            $item['variant_id'] = $bin->bin_data->id ?? 0;
        }
        return $item;
    }

    public function addPackagingID($binResponse, $boxBins)
    {
        foreach ($binResponse as $locationId => $bins) {
            foreach ($bins->bins_packed as $key => $bin) {
                $items = $bin->items;
                $item = $items[0];
                $variant_id = $item->id;
                $binResponse[$locationId]->bins_packed[$key]->bin_data->variant_id = $variant_id;
                $boxId = $bin->bin_data->id ?? 0;
                $binResponse[$locationId]->bins_packed[$key]->bin_data->name = isset($boxBins[$boxId]['name']) ? strtoupper(str_replace(' ', '_', trim(explode('__', $boxBins[$boxId]['name'])[0]))) : '';
            }
        }
        return $binResponse;
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


    /**
     * @param $connSettings
     * @return array
     */
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
            'storeDateTime' => $this->getStoreDateTime(), //2020-10-22 14:00:00
            'shipmentWeekDays' => $shipmentWeekDays,
        ];
    }


    public function checkIsALwaysQuoteResDel($connSettings): bool
    {
        return isset($connSettings['quote_settings']['alwaysResidentialDelivery']) && $connSettings['quote_settings']['alwaysResidentialDelivery'];

    }

    public function getStoreDateTime()
    {
        return date("Y-m-d H:i:s");
    }
}
