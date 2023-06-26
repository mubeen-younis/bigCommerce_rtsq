<?php

namespace App\CustomClasses;

use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;
use App\CustomClasses\DBSC\GetRatesDbsc;
use App\Models\ShippingGroup;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use App\Models\RequestTempData;
use App\Models\Store;
use App\Models\BoxSize;
use Carbon\Carbon;
use App\CustomClasses\LtlSmallCompileQuotes;
use Illuminate\Support\Str;

class Shipping
{

    /**
     * @var WweLTLShipmentPackage
     */
    private $shipmentPkg;

    private $isHazmat = 'N';

    private $compileQuotes;
    private $isInsurance = 'N';
    private $isRequestMultishipment = false;
    private $shippingGroupResponse;
    private $showOnlyLocAndInstoreQuote;
    private $instoreQuotes;
    private $locDelQuotes;
    private $multiOrigins;
    private $dbscRates;
    private $dbscOrdWid;


    public function __construct()
    {
        $this->shipmentPkg = new WweLTLShipmentPackage();
        $this->compileQuotes = new CompileQuotes();
        $this->shippingGroupResponse = [];
        $this->showOnlyLocAndInstoreQuote = false;
        $this->instoreQuotes = false;
        $this->locDelQuotes = false;
        $this->multiOrigins = false;
        $this->dbscRates = [];
        $this->dbscOrdWid = [];

    }


    /**
     * @param $request
     * @param $storeData
     * @param $connectionSettings
     * @param $quoteSettings
     * @return array | bool
     */
    public function collectRates($request, $storeData, $connectionSettings, $cartInfo, $isDbscInstalled = false)
    {
        $quoteSettings = $multiShipmentQuotes = [];
        $generateReqData = new GenerateRequestData();
        //   init is a function to call it explixitlitly rather constructor

        $generateReqData->_init($quoteSettings, $connectionSettings, $storeData);
        $origins = $request['lineItemData']['origin'];
        // Check if any of the item in the cart has selected quote as instore or local delivery
        $this->showOnlyLocAndInstoreQuote = $this->showOnlyLocAndInstoreQuote($request['lineItemData']['items']);
        // Set SUppress Rates to true if to show only instore and local
        if ($this->showOnlyLocAndInstoreQuote) {
            $origins = $this->enableSuppressRatesInOrigins($origins);
            if (blank($origins)) {
                return [];
            }
        }

        /*Added for DBSC Carrier
        Will calculate DBSC rates
        And also Order widget Details*/
        $store_id = $storeData['store']['id'];
        $destination = $request['lineItemData']['destination'];
        $items = $request['lineItemData']['items'];

        try {
            if ($isDbscInstalled) {
                $getDbscDetails = (new GetRatesDbsc($store_id, $destination, $items, [], [], false, [], []))->getDbscRates($request, $storeData);
                $this->dbscRates = $getDbscDetails['rates'] ?? [];
                $this->dbscOrdWid = $getDbscDetails['ord_wid'] ?? [];
            }
        } catch (\Exception $exception) {
            Functions::log('DBSC rates exception ', $exception);
        }

        // Items that is not associated with Shipping Group and need to get rates from Ws
        $itemsWithoutShippingGroup = collect($request['lineItemData']['items'])->where('shipping_group', null)->all();

        // Items that is associated with Shipping Group
        $itemsWithShippingGroup = collect($request['lineItemData']['items'])->where('shipping_group', '!=', null)->all();


        $originsWithoutShippingGroup = $this->getOriginsAccShipGroup($itemsWithoutShippingGroup, $origins);

        // Items that is associated with Shipping Group
        $originsWithShippingGroup = $this->getOriginsAccShipGroup($itemsWithShippingGroup, $origins);

        if (!blank($itemsWithShippingGroup)) {
            $this->setShippingGroupsResponse($itemsWithShippingGroup);
        }
        if (blank($itemsWithoutShippingGroup)) {
            $finalResp = $this->formattedShippingGroupResponse();
            $this->orderWidgetSave($request, [], [], $finalResp['finalQuotes'], $finalResp['formattedResp'], $cartInfo, [], []);
            return $finalResp['formattedResp'];
        }

        $request['lineItemData']['items'] = $itemsWithoutShippingGroup;
        $request['lineItemData']['origin'] = $originsWithoutShippingGroup;
        $package = $request['lineItemData'];
        // Disabling instore pickup if there is multi shipment case
        $originAddress = $this->checkInstorePickup($package['origin']);

        // Generating carrier creds and origin array
        $destination = $request['lineItemData']['destination'] ?? [];
        $resp = $generateReqData->generateEnitureArray($originAddress, $destination, $package['items']);


        if (empty($resp)) {
            return [];
        }
        $residential = $resp['residential'];
        $carriersArray = $resp['carriersArr'];
        $carriersErrorSettings = $resp['errorManagment'];

        $this->multiOrigins = $this->checkIsMultiShipment($carriersArray['carriers']);
        /*Check for MUlti shipment and product marked as instore or local delivery*/
        if ($this->multiOrigins && $this->showOnlyLocAndInstoreQuote) {
            return [];
        }

        // Checking if any productis hazardous
        $hazmatAllItems = $this->isHazmatMaterial($package);

        $this->isInsurance($package);
        foreach ($carriersArray['carriers'] as $key => $carriers) {
            if ($this->isHazmat == 'Y' && $key == 'wweLTL') {
                $carriersArray['carriers'][$key]['api']['lineItemHazmatInfo'] = [
                    [
                        'isHazmatLineItem' => 'Y',
                        'lineItemHazmatUNNumberHeader' => 'UN #',
                        'lineItemHazmatUNNumber' => '1139',
                        'lineItemHazmatClass' => '1.1',
                        'lineItemHazmatEmContactPhone' => '4043308699',
                        'lineItemHazmatPackagingGroup' => 'I',
                    ],
                ];
            }
            if ($this->isInsurance === 'Y' && $key == 'wweLTL') {
                if ($this->isSmall($key)) {
                    $carriersArray['carriers'][$key]['api']['includeDeclaredValue'] = 1;
                } else {
                    if ($key == 'wweLTL') {
                        $carriersArray['carriers'][$key]['api']['insureShipment'] = 1;
                    }
                }
            }
        }

        // Genearting final request Array
        $requestArr = $generateReqData->generateRequestArray($request, $carriersArray, $package['items'], $cartInfo, $carriersErrorSettings);
        // Added customization for eniture packaging disabled stores
        $requestArr = (new Customizations())->eniturePackagingCustomization($requestArr, $storeData['store']['hash']);

        if (empty($requestArr)) {
            return [];
        }

        $SuppressParcelRates = isset($requestArr['SuppressParcelRates']) ? $requestArr['SuppressParcelRates'] : false;
        unset($requestArr['SuppressParcelRates']);
        $url = Constant::QUOTES_URL;
        $smalLtlHazmat = $this->checkIndividualHazmat($requestArr['requestArr']);
        //Sending request to WS to get Quotes
        $quotes = $this->sendCurlRequest($url, $requestArr['requestArr']);

        $ltlSmallCompileQuotes = new LtlSmallCompileQuotes();
        /*
         * $this->isRequestMultishipment => Check if one product ltl and other small with different origin
         */
        $this->isRequestMultishipment = $ltlSmallCompileQuotes->checkIsRequestMiltiShipment($requestArr['requestArr'], $quotes);
        /* Catering Usps carrier packaging response */
        $uspsCarrierArr = $requestArr['requestArr']['carriers']['usps'] ?? [];
        if (isset($uspsCarrierArr) && !empty($uspsCarrierArr)) {
            $apiArray = $uspsCarrierArr['api'] ?? [];
            $uspsBoxBins = $apiArray['boxBins'] ?? [];

            if (isset($apiArray['binResponseArr']) && !empty($apiArray['binResponseArr'])) {
                $quotes = $this->addBinResponseToQuotes($apiArray['binResponseArr'], $quotes, true);
            }
        }

        $boxbins = $requestArr['boxBins'] ?? [];
        if (isset($uspsBoxBins) && !empty($uspsBoxBins)) {
            $boxbins = array_merge($boxbins, $uspsBoxBins);
        }

        // TODO: Need to check when packaging is happening
        if (isset($requestArr['binReponse']) && !empty($requestArr['binReponse'])) {
            $quotes = $this->addBinResponseToQuotes($requestArr['binReponse'], $quotes, false);
        }

        $palletBins = $requestArr['palletBins'] ?? [];
        if (isset($requestArr['palletResponse']) && !empty($requestArr['palletResponse'])) {
            $quotes = (new PalletPackaging())->addPalletResponseToQuotes($requestArr['palletResponse'], $quotes);
        }

        $freeRNL = false;
        if (isset($requestArr['requestArr']['carriers']['rnl']['freeShipment']) && $requestArr['requestArr']['carriers']['rnl']['freeShipment']) {
            unset($requestArr['requestArr']['carriers']['rnl']['freeShipment']);
            $freeRNL = true;
        }
        Log::info('after addBinResponseToQuotes ' . json_encode($quotes));

        $quotesFromWs = $quotes ?? [];
        $finalQuotes = $this->compileQuotes->newGetQuotesResults($quotes, $connectionSettings, $package['origin'], $this->isHazmat, $smalLtlHazmat, $hazmatAllItems, $residential, $freeRNL, $destination, $package['items'], $SuppressParcelRates);
        if (!empty($finalQuotes['multiShipmentQuotes'])) {
            $multiShipmentQuotes = $finalQuotes['multiShipmentQuotes'];
            $finalQuotes = $finalQuotes['checkoutQuotes'];
        }

        $_finalQuotes = $finalTitlesTemp = $finalCodesTemp = [];
        $finalTitles = array_column($finalQuotes, 'title');
        $finalCodes = array_column($finalQuotes, 'code');

        foreach ($finalTitles as $key => $finalTitle) {
            $finalTitlesTemp[$key] = explode(' ', $finalTitle)[0];
        }
        foreach ($finalCodes as $key => $finalCode) {
            $finalCodesTemp[$key] = explode('+', $finalCode)[0];
        }

        /*TODO :Need to Add LTL Carriers here as well*/
        $isFreightTitleExist = array_search(Functions::$ltlMultiTitle, $finalTitlesTemp);
        $isShippingTitleExist = array_search(Functions::$smallMultiTitle, $finalTitlesTemp);
        $isAVGCodeExist = gettype(array_search('AVG', $finalCodesTemp)) == 'integer';
        $isUpsLtlCodeExist = gettype(array_search('upsltl', $finalCodesTemp)) == 'integer';
        $isFedexLtlCodeExist = gettype(array_search('fedexltl', $finalCodesTemp)) == 'integer';
        $isxpoLtlCodeExist = gettype(array_search('xpoltl', $finalCodesTemp)) == 'integer';
        $isYrcLtlCodeExist = gettype(array_search('yrcltl', $finalCodesTemp)) == 'integer';
        $isFreightQuoteLtlCodeExist = gettype(array_search('fqltl', $finalCodesTemp)) == 'integer';
        $isEstesLtlCodeExist = gettype(array_search('estesltl', $finalCodesTemp)) == 'integer';
        $isDayRossLtlCodeExist = gettype(array_search('dayrossltl', $finalCodesTemp)) == 'integer';
        $isOdflLtlCodeExist = gettype(array_search('odflltl', $finalCodesTemp)) == 'integer';
        $isSaiaLtlCodeExist = gettype(array_search('saialtl', $finalCodesTemp)) == 'integer';
        $isAbfLtlCodeExist = gettype(array_search('abfltl', $finalCodesTemp)) == 'integer';
        $isSouthEasternLtlCodeExist = gettype(array_search('southeastltl', $finalCodesTemp)) == 'integer';
        $isTqlLtlCodeExist = gettype(array_search('tqlltl', $finalCodesTemp)) == 'integer';
        $isEchoLtlCodeExist = gettype(array_search('echoltl', $finalCodesTemp)) == 'integer';
        $isDayLightLtlCodeExist = gettype(array_search('daylightltl', $finalCodesTemp)) == 'integer';
        $isFreightQuoteChrLtlCodeExist = gettype(array_search('fqchrltl', $finalCodesTemp)) == 'integer';
        $freightCode = '';
        $finalCost = 0;

        if (!empty($_finalQuotes)) {
            $_finalQuotes[$key]['code'] = $freightCode;
            $_finalQuotes[$key]['title'] = Functions::$ltlMultiTitle;
            $_finalQuotes[$key]['rate'] = $finalCost;
            $_finalQuotes = array_values($_finalQuotes);
            $finalQuotes = $_finalQuotes;
        } else {
            $isShippingOrFreight = gettype($isFreightTitleExist) == 'integer' || gettype($isShippingTitleExist) == 'integer';
            //TODO : Need to Add LTL Carriers Here as well
            if (!$isShippingOrFreight && ($isAVGCodeExist || $isUpsLtlCodeExist || $isFedexLtlCodeExist || $isxpoLtlCodeExist || $isYrcLtlCodeExist || $isFreightQuoteLtlCodeExist || $isEstesLtlCodeExist || $isDayRossLtlCodeExist || $isOdflLtlCodeExist || $isSaiaLtlCodeExist || $isAbfLtlCodeExist || $isSouthEasternLtlCodeExist || $isTqlLtlCodeExist || $isEchoLtlCodeExist || $isDayLightLtlCodeExist || $isFreightQuoteChrLtlCodeExist)) {
                $isShippingOrFreight = false;
            }

            if ($this->isRequestMultishipment && !$isShippingOrFreight) {
                $finalQuotesMulti = $this->makeMultishipmentSmallLtl($finalQuotes, $connectionSettings, $residential, $quotesFromWs, $requestArr['requestArr']);
                $finalQuotes = $finalQuotesMulti['checkoutQuotes'] ?? [];
                $multiShipmentQuotes = $finalQuotesMulti['multiShipmentQuotes'] ?? [];
            }
            /*Removed Code of removing parcel and ltl*/
        }
        /*Adding shipping group rates response in quotes
         */
        if (!blank($this->shippingGroupResponse)) {
            $items = data_get($request, 'lineItemData.items');
            $items = $items + $itemsWithShippingGroup;
            $request['lineItemData']['items'] = $items;
            $finalQuotes = $this->addShipGroupRatesInQuotes($finalQuotes);
        }

        $finalQuotes = $this->addRateId($finalQuotes);
        $resp = $this->generateQuoteFormatResponse($finalQuotes);
        $this->orderWidgetSave($request, $requestArr, $quotes, $finalQuotes, $resp, $cartInfo, $boxbins, $multiShipmentQuotes);
        return $resp;
    }


    public function checkIsMultiShipment($carriers)
    {
        foreach ($carriers as $carrier) {
            $output = Functions::checkMultiUnique($carrier['originAddress']);
            if (count($output) > 1) {
                return true;
            }
        }
        return false;
    }

    public function showOnlyLocAndInstoreQuote($items): bool
    {
        foreach ($items as $item) {
            if (isset($item['quote_as_local']) && $item['quote_as_local']) {
                return true;
            }
        }
        return false;
    }


    public function enableSuppressRatesInOrigins($origins)
    {
        //  Need to set some status for WS to suppress quotes and ignore destination origin
        $found = false;
        foreach ($origins as $key => $origin) {
            if (isset($origin['InstorPickupLocalDelivery']['localDelivery']['postalCodeMatch'])) {
                $origins[$key]['InstorPickupLocalDelivery']['suppress'] = 1;
                $found = true;
            }

            if (isset($origin['InstorPickupLocalDelivery']['inStorePickup']['postalCodeMatch'])) {
                $origins[$key]['InstorPickupLocalDelivery']['suppress'] = 1;
                $found = true;
            }

        }
        if (!$found) {
            return [];
        }
        return $origins;
    }

    public function getOriginsAccShipGroup($items, $origins)
    {
        $formOrigins = [];
        foreach ($origins as $originKey => $origin) {
            foreach ($items as $itemKey => $item) {
                if ($originKey == $itemKey) {
                    $formOrigins[$itemKey] = $origin;
                }
            }
        }
        return $formOrigins;
    }


    protected function setShippingGroupsResponse($shippingGroupItems)
    {
        $this->shippingGroupResponse = ShippingGroup::setShippingGroup($shippingGroupItems);
    }


    /**
     * @return array
     */
    protected function formattedShippingGroupResponse(): array
    {
        $finalQuotes = $this->addRateId($this->shippingGroupResponse);
        $resp = $this->generateQuoteFormatResponse($finalQuotes);
        return ['finalQuotes' => $finalQuotes, 'formattedResp' => $resp];
    }


    /**
     * @param $finalQuotes
     * @return array
     */
    protected function addShipGroupRatesInQuotes($finalQuotes): array
    {
        foreach ($finalQuotes as $key => $quote) {
            $finalQuotes[$key]['rate'] = $quote['rate'] + $this->shippingGroupResponse[0]['rate'];
        }
        return $finalQuotes;
    }

    private function removeParcelIfLtl($finalQuotes)
    {
        $finalQuotes = $finalQuotes['checkoutQuotes'] ?? $finalQuotes;
        $hasLtl = false;
        $hasParcel = false;
        foreach ($finalQuotes as $quote) {
            $notCustomAdded = isset($quote['code']) && strpos($quote['code'], 'own_arrangement') === false && strpos($quote['code'], 'INSP') === false && strpos($quote['code'], 'LOCDEL') === false;
            if ($notCustomAdded) {
                if (strpos($quote['code'], 'parcel_12') === 0) {
                    $hasParcel = true;
                } else {
                    $hasLtl = true;
                }
            }
        }
        if ($hasLtl && $hasParcel) {
            foreach ($finalQuotes as $key => $quote) {
                if (strpos($quote['code'], 'parcel') === 0) {
                    unset($finalQuotes[$key]);
                }
            }
        }
        return $finalQuotes;
    }

    private function makeMultishipmentSmallLtl($quotes, $connectionSettings, $residential, $quotesFromWs, $requestArr)
    {
        $ltlSmallCompileQuotes = new LtlSmallCompileQuotes();
        /*
         * Need to add entry if every carrier here as well
         * there is some caompatibility code of multi shipment here
         *
         * */
        $resp = $ltlSmallCompileQuotes->compileQuotes($quotes, $connectionSettings, $residential, $quotesFromWs, $requestArr);
        return $resp;
    }

    private function addBinResponseToQuotes($binReponse, $quotes, $uspsRes = false)
    {
        try {
            $boxFee = [];
            $fedexBoxesFee = [];
            $uspsBoxesFee = [];
            foreach ($quotes as $carrierName => $quote) {
                if ($this->isSmallCarrier($carrierName)) {
                    if (($carrierName == 'fedexSmall') && !$uspsRes) {
                        foreach ($binReponse as $serviceType => $response) {
                            foreach ($response as $locationId => $bin) {
                                $quotes[$carrierName][$locationId]['binPackagingData']['response'][$serviceType] = $bin;
                                $fee = $this->getCumulativeBoxFee($bin);
                                $boxFee[$locationId] = $fee;

                                if ($carrierName == 'fedexSmall') {
                                    $fedexBoxesFee[$locationId][$serviceType] = $fee;
                                }
                            }
                        }
                    } else if ($carrierName == 'usps' && $uspsRes) {
                        foreach ($binReponse as $locationId => $boxTypes) {
                            if (isset($boxTypes) && !empty($boxTypes)) {
                                foreach ($boxTypes as $type => $value) {
                                    $quotes[$carrierName][$locationId]['binPackagingData']['response'][strtolower($type)] = $value;
                                    if ($type == 'UMEB' || $type == 'UPMB') {
                                        $value = $this->getBinsByBoxType($type, $boxTypes);
                                    }
                                    $fee = $this->getCumulativeBoxFee($value, true);
                                    $boxFee[$locationId] = $fee;
                                    $uspsBoxesFee[$locationId][$type] = $fee;
                                }
                            }
                        }
                    } else if ($carrierName !== 'fedexSmall' && $carrierName !== 'usps' && ($uspsRes === null || !$uspsRes)) {
                        if (isset($binReponse['ground']) && !empty($binReponse['ground']) && isset($binReponse['simpleRate']) && !empty($binReponse['simpleRate'])) {
                            foreach ($binReponse as $serviceType => $response) {
                                foreach ($response as $locationId => $bin) {
                                    $quotes[$carrierName][$locationId]['binPackagingData']['response'][$serviceType] = $bin;
                                    $fee = $this->getCumulativeBoxFee($bin);
                                    $boxFee[$locationId] = $fee;
                                }
                            }
                        } else {
                            foreach ($binReponse as $locationId => $bin) {
                                $quotes[$carrierName][$locationId]['binPackagingData']['response'] = $bin;
                                $boxFee[$locationId] = $this->getCumulativeBoxFee($bin);
                            }
                        }
                    }
                }
            }
            if (!empty($boxFee)) {
                $quotes = $this->addBoxFeeToQuotes($quotes, $boxFee, $fedexBoxesFee);
            }
            return $quotes;
        } catch (\Exception $exception) {
            return $quotes;
        }



    }

    private function getBinsByBoxType($type, $boxes)
    {
        if (isset($boxes[$type]) && !empty($boxes[$type]) && isset($boxes[$type]['bins_packed']) && !empty($boxes[$type]['bins_packed'])) {
            return $boxes[$type];
        } else if (isset($boxes['customBoxes']) && !empty($boxes['customBoxes']) && isset($boxes['customBoxes']['bins_packed']) && !empty($boxes['customBoxes']['bins_packed'])) {
            return $boxes['customBoxes'];
        }

        return [];
    }

    public
        function isSmallCarrier(
        $carrierName
    ) {
        $smallCarriers = [
            'wweSmall',
            'upsSmall',
            'fedexSmall',
            'unishippersSmall',
            'usps',
            'purolator',
            'shipEngine'
        ];
        return in_array($carrierName, $smallCarriers);
    }

    public
        function isLtlCarrier(
        $carrierName
    ) {
        $ltlCarriers = [
            'wweLTL',
            'upsLTL',
            'fedexLTL',
            'globalTranz',
            'xpoLTL',
            'rnlLTL',
            'yrcLTL'
        ];
        return in_array($carrierName, $ltlCarriers);
    }

    private function addBoxFeeToQuotes(
        array $quotes, array $boxFee,
        $fedexBoxesFee = []
    ): array {
        $parcelCarName = ['wweSmall', 'upsSmall', 'fedexSmall', 'unishippersSmall', 'usps'];
        if (isset($quotes) && !empty($quotes)) {
            foreach ($quotes as $carName => $quot) {
                if (in_array($carName, $parcelCarName)) {
                    foreach ($quot as $locId => $q) {
                        // Added Condition for fedex small for adding box fees
                        if ($carName == "fedexSmall") {
                            if (isset($q['fedexServices']['q'])) {
                                if (isset($q['fedexServices']['q']['severity']) && $q['fedexServices']['q']['severity'] == "ERROR") {
                                    continue;
                                }

                                foreach ($q['fedexServices']['q'] as $key => $qs) {
                                    $fee = $this->getBoxFeeAccordingToService($qs['serviceType'], $fedexBoxesFee, $boxFee, $locId);
                                    if (isset($qs['totalNetCharge']['Amount'])) {
                                        if ($fee != 0) {
                                            $quotes[$carName][$locId]['fedexServices']['q'][$key]['totalNetCharge']['Amount'] = $qs['totalNetCharge']['Amount'] + $fee;
                                            $quotes[$carName][$locId]['fedexServices']['q'][$key]['boxFees']['Amount'] = $fee;
                                        }
                                    }
                                    if (isset($qs['NegotiatedRates']['Amount'])) {
                                        if ($fee != 0) {
                                            $quotes[$carName][$locId]['fedexServices']['q'][$key]['NegotiatedRates']['Amount'] = $qs['NegotiatedRates']['Amount'] + $fee;
                                            $quotes[$carName][$locId]['fedexServices']['q'][$key]['boxFees']['Amount'] = $fee;
                                        }
                                    }
                                }
                            }

                            if (isset($q['fedexAirServices']['q'])) {

                                if (isset($q['fedexAirServices']['q']['severity']) && $q['fedexAirServices']['q']['severity'] == "ERROR") {
                                    continue;
                                }

                                foreach ($q['fedexAirServices']['q'] as $key => $qs) {
                                    $fee = $this->getBoxFeeAccordingToService($qs['serviceType'], $fedexBoxesFee, $boxFee, $locId);
                                    if (isset($qs['totalNetCharge']['Amount'])) {
                                        if ($fee != 0) {
                                            $quotes[$carName][$locId]['fedexAirServices']['q'][$key]['totalNetCharge']['Amount'] = $qs['totalNetCharge']['Amount'] + $fee;
                                            $quotes[$carName][$locId]['fedexAirServices']['q'][$key]['boxFees']['Amount'] = $fee;
                                        }
                                    }
                                    if (isset($qs['NegotiatedRates']['Amount'])) {
                                        if ($fee != 0) {
                                            $quotes[$carName][$locId]['fedexAirServices']['q'][$key]['NegotiatedRates']['Amount'] = $qs['NegotiatedRates']['Amount'] + $fee;
                                            $quotes[$carName][$locId]['fedexServices']['q'][$key]['boxFees']['Amount'] = $fee;
                                        }
                                    }
                                }
                            }


                            if (isset($q['fedexOneRate']['q'])) {
                                if (isset($q['fedexOneRate']['q']['severity']) && $q['fedexOneRate']['q']['severity'] == "ERROR") {
                                    continue;
                                }

                                foreach ($q['fedexOneRate']['q'] as $key => $qs) {
                                    $fee = $this->getBoxFeeAccordingToService($qs['serviceType'], $fedexBoxesFee, $boxFee, $locId, true);
                                    if (isset($qs['totalNetCharge']['Amount'])) {
                                        if ($fee != 0) {
                                            $quotes[$carName][$locId]['fedexOneRate']['q'][$key]['totalNetCharge']['Amount'] = $qs['totalNetCharge']['Amount'] + $fee;
                                            $quotes[$carName][$locId]['fedexOneRate']['q'][$key]['boxFees']['Amount'] = $fee;
                                        }
                                    }
                                    if (isset($qs['NegotiatedRates']['Amount'])) {
                                        if ($fee != 0) {
                                            $quotes[$carName][$locId]['fedexOneRate']['q'][$key]['NegotiatedRates']['Amount'] = $qs['NegotiatedRates']['Amount'] + $fee;
                                            $quotes[$carName][$locId]['fedexOneRate']['q'][$key]['boxFees']['Amount'] = $fee;
                                        }
                                    }
                                }
                            }

                            /*
                             * Adds Box fee in SMart POst QUotes
                             * */

                            if (isset($q['smartPost']['q']['SMART_POST']['serviceType'])) {
                                $fee = $this->getBoxFeeAccordingToService('smart_post', $fedexBoxesFee, $boxFee, $locId);
                                if ($fee != 0) {
                                    if (isset($quotes[$carName][$locId]['smartPost']['q']['SMART_POST']['NegotiatedRates']['Amount'])) {
                                        $quotes[$carName][$locId]['smartPost']['q']['SMART_POST']['NegotiatedRates']['Amount'] = $quotes[$carName][$locId]['smartPost']['q']['SMART_POST']['NegotiatedRates']['Amount'] + $fee;
                                        // $quotes[$carName][$locId]['smartPost']['q']['SMART_POST']['boxFees']['Amount'] = $fee;
                                    }
                                    if (isset($quotes[$carName][$locId]['smartPost']['q']['SMART_POST']['totalNetCharge']['Amount'])) {
                                        $quotes[$carName][$locId]['smartPost']['q']['SMART_POST']['totalNetCharge']['Amount'] = $quotes[$carName][$locId]['smartPost']['q']['SMART_POST']['totalNetCharge']['Amount'] + $fee;
                                        //$quotes[$carName][$locId]['smartPost']['q']['SMART_POST']['boxFees']['Amount'] = $fee;
                                    }
                                }
                            }
                        }


                        if (isset($q['q'])) {
                            foreach ($q['q'] as $key => $qs) {
                                if (isset($qs['totalNetCharge']['Amount'])) {
                                    if (isset($boxFee[$locId])) {
                                        $quotes[$carName][$locId]['q'][$key]['totalNetCharge']['Amount'] = $qs['totalNetCharge']['Amount'] + $boxFee[$locId];
                                        $quotes[$carName][$locId]['q'][$key]['boxFees']['Amount'] = $boxFee[$locId];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return $quotes;
    }

    public function getBoxFeeAccordingToService(
        $serviceType,
        $fedexBoxFee,
        $boxFee,
        $locId,
        $oneRate = false
    ) {
        $commonBoxFee = $boxFee[$locId] ?? 0;
        if ($oneRate) {
            $fee = $fedexBoxFee[$locId]['oneRate'] ?? $commonBoxFee;
        } elseif ($serviceType == "FEDEX_GROUND" || $serviceType == "GROUND_HOME_DELIVERY" || "smart_post") {
            $fee = $fedexBoxFee[$locId]['ground'] ?? $commonBoxFee;
        } else {
            $fee = $fedexBoxFee[$locId]['air'] ?? $commonBoxFee;
        }
        return $fee;
    }

    private function getCumulativeBoxFee($bins, $usps = false): float
    {
        if ($usps) {
            $bins = (object) $bins;
        }
        $boxFee = 0;
        if (!empty($bins->bins_packed)) {
            foreach ($bins->bins_packed as $pack) {
                if (isset($pack->bin_data->type) && $pack->bin_data->type === 'item') {
                    $boxFee += $pack->bin_data->boxFee;
                } else {
                    // $boxId = $pack->bin_data->id;
                    $boxFee += optional($pack)->bin_data->boxfee ?? 0;
                }
            }
        }

        return $boxFee;
    }

    private function BoxFeeByID(
        int $boxId
    ) {
        if (BoxSize::where('id', $boxId)->exists()) {
            return BoxSize::find($boxId)->pluck('box_fee')->first();
        }
        return 0;
    }

    public
        function orderWidgetSave(
        $lineItems,
        $requestArr,
        $quotes,
        $finalQuotes,
        $resp,
        $cartInfo,
        $boxbins,
        $multiShipmentQuotes = null
    ) {
        if (!blank($this->dbscRates)) {
            $finalQuotes = array_merge($finalQuotes, $this->dbscRates);
        }

        foreach ($finalQuotes as $finalQuote) {
            $RequestTempData = new RequestTempData();
            $RequestTempData->request = json_encode($requestArr);
            $RequestTempData->lineitems = json_encode($lineItems);
            $RequestTempData->quotes = json_encode($quotes);
            $RequestTempData->response = json_encode($resp);
            $RequestTempData->multiShipmentresponse = json_encode($multiShipmentQuotes, JSON_FORCE_OBJECT);
            $RequestTempData->store_id = $cartInfo['store_id'];
            $RequestTempData->rate_id = $finalQuote['rate_id'];
            $RequestTempData->cart_id = $cartInfo['cartId'];
            $RequestTempData->box_bins = json_encode($boxbins);
            $RequestTempData->shipping_group_resp = !blank($this->shippingGroupResponse) ? json_encode($this->shippingGroupResponse) : null;
            $RequestTempData->dbsc_resp = !blank($this->dbscOrdWid) ? json_encode($this->dbscOrdWid) : null;
            $RequestTempData->save();
        }
    }

    public
        function addRateId(
        $finalQuotes
    ) {
        $time = time();
        foreach ($finalQuotes as $key => $finalQuote) {
            $finalQuotes[$key]['rate_id'] = isset($finalQuote['code']) ? $finalQuote['code'] . 'idx+' . $key . $time : $time;
        }
        return $finalQuotes;
    }

    public
        function checkInstorePickup(
        $origin
    ) {
        if (count($origin) > 1) {
            $whIDs = [];
            foreach ($origin as $wh) {
                if (isset($wh['locationId'])) {
                    $whIDs[] = $wh['locationId'];
                }
            }
            if (count(array_unique($whIDs)) > 1) {
                foreach ($origin as $id => $wh) {
                    if (isset($wh['InstorPickupLocalDelivery'])) {
                        $origin[$id]['InstorPickupLocalDelivery'] = [];
                    }
                }
            }
        }
        return $origin;
    }

    /**
     * to enable hazmat property for Api
     */
    public
        function isHazmatMaterial(
        $items
    ) {
        $hazmatAllItems = [];
        foreach ($items['items'] as $key => $item) {
            if (isset($item['isHazmatLineItem']) && $item['isHazmatLineItem'] == 'Y') {
                $this->isHazmat = 'Y';

                $hazmatAllItems[$items['origin'][$key]['locationId']] = 'Y';
            } else {
                $hazmatAllItems[$items['origin'][$key]['locationId']] = 'N';
            }
        }
        return $hazmatAllItems;
    }

    private
        function checkIndividualHazmat(
        $request
    ) {
        // TODO: Need to Add small and Ltl Carriers Here as well

        $smallOrigins = $marketItemSmall = $request['carriers']['wweSmall']['originAddress'] ?? $request['carriers']['upsSmall']['originAddress'] ?? $request['carriers']['fedexSmall']['originAddress'] ?? $request['carriers']['unishippersSmall']['originAddress'] ?? $request['carriers']['usps']['originAddress'] ?? $request['carriers']['purolator']['originAddress'] ?? [];
        $ltlOrigins = $request['carriers']['wweLTL']['originAddress'] ?? $request['carriers']['upsLTL']['originAddress'] ?? $request['carriers']['yrcLTL']['originAddress'] ?? $request['carriers']['odfl4me']['originAddress'] ?? $request['carriers']['abf']['originAddress'] ?? $request['carriers']['southeastern']['originAddress'] ?? $request['carriers']['tql']['originAddress'] ?? $request['carriers']['echoLogistics']['originAddress'] ?? $request['carriers']['daylight']['originAddress'] ?? $request['carriers']['chr']['originAddress'] ?? [];
        $items = $request['commdityDetails'] ?? [];
        $smallHazmat = $ltlHazmat = false;
        if (!empty($smallOrigins)) {
            foreach ($smallOrigins as $key => $origin) {
                if (isset($items[$key]['isHazmatLineItem']) && $items[$key]['isHazmatLineItem'] == 'Y') {
                    $smallHazmat = true;
                    $marketItemSmall[] = $items[$key]['product_id'] . $items[$key]['variant_id'];
                }
            }
            foreach ($ltlOrigins as $key => $origin) {
                $id = $items[$key]['product_id'] . $items[$key]['variant_id'];
                if (isset($items[$key]['isHazmatLineItem']) && $items[$key]['isHazmatLineItem'] == 'Y' && !in_array($id, $marketItemSmall)) {
                    $ltlHazmat = true;
                    break;
                }
            }
        }
        $resp = [
            'smallHazmat' => $smallHazmat,
            'ltlHazmat' => $ltlHazmat
        ];
        return $resp;
    }

    /**
     * to enable insurance property for Api
     */
    public
        function isInsurance(
        $items
    ) {
        foreach ($items['items'] as $key => $item) {
            if (isset($item['product_insurance_active']) && $item['product_insurance_active'] === 1) {
                $this->isInsurance = 'Y';
            }
        }
    }

    /**
     * @return array
     */
    public
        function getAllowedMethods(
    ) {
        return [$this->_code => $this->getConfigData('name')];
    }

    /**
     * @param $quotes
     * @return array
     */
    public
        function setCarrierRates(
        $quotes
    ) {
        return $quotes = $quotes ?? [];
    }

    public
        function generateQuoteFormatResponse(
        $quotes
    ) {
        $onlyDbscEnabled = false;
        if (empty(array_filter($quotes)) && isset($this->dbscRates) && !empty($this->dbscRates)) {
            $onlyDbscEnabled = true;
            $quotes = $this->addDbscRates($quotes);
        }

        $quotes = array_values($quotes);
        $current = str_replace(' ', 'T', Carbon::now()) . "-00:00";
        if (!empty(array_filter($quotes))) {
            $resp['quote_id'] = (string) rand(1, 9); // need to change
            $resp['messages'] = []; // need to change

            if (!$onlyDbscEnabled) {
                $quotes = $this->freeShippingTitle($quotes);
                $quotes = $this->formatCheapestFinalQuotes($quotes);
                $quotes = $this->addDbscRates($quotes);
            }

            $resp['carrier_quotes'][0] = ['carrier_info' => ['code' => 'eniture_quotes', 'display_name' => $this->limitTitle($quotes[0])]];

            foreach ($quotes as $key => $quote) {
                $resp['carrier_quotes'][0]['quotes'][$key] = [
                    'code' => $quote['code'],
                    'rate_id' => $quote['rate_id'],
                    'display_name' => $this->limitTitle($quote),
                    'cost' => ['currency' => 'USD', 'amount' => str_replace(',', '', $quote['rate'])],
                    'dispatch_date' => "$current",
                ];
            }
        } else {
            $resp = [];
        }

        Log::info('Last response for quotes ' . json_encode($resp));
        return $resp;
    }

    public function freeShippingTitle($finalQuotes)
    {
        foreach ($finalQuotes as $key => $quote) {
            if (isset($quote['code']) && ($quote['code'] == 'INSP' || $quote['code'] == 'LOCDEL')) {
                continue;
            }
            if ((empty($quote['rate']) || $quote['rate'] == '0.00') && isset($quote['code']) && $quote['code'] !== 'own_arrangement') {
                $finalQuotes[$key]['title'] = Functions::$freeShipping;
            }
        }

        return $finalQuotes;
    }

    private function formatCheapestFinalQuotes($quotes): array
    {
        $finalCheapestQuotes = $quotes ?? [];
        if (empty($finalCheapestQuotes)) {
            return $finalCheapestQuotes;
        }

        $freightTitle = Functions::$ltlMultiTitle;
        $shippingTitle = Functions::$smallMultiTitle;
        $freeShippingTitle = Functions::$freeShipping;

        // Filter single shipment same titles quotes array
        if (!$this->multiOrigins) {
            return $this->filterSameTitleCheapestQuotes($finalCheapestQuotes);
        }

        //Filter multi shipment same titles quotes array
        $freightQuotesArr = collect($finalCheapestQuotes)->filter(function ($quote) use ($freightTitle) {
            return strpos($quote['title'], $freightTitle) !== false || strpos($quote['title'], 'Freight') !== false || strpos($quote['code'], 'own_arrangement') !== false;
        })->toArray() ?? [];
        $shippingQuotesArr = collect($finalCheapestQuotes)->filter(function ($quote) use ($shippingTitle) {
            return strpos($quote['title'], $shippingTitle) !== false;
        })->toArray() ?? [];
        $freeShippingQuotesArr = collect($finalCheapestQuotes)->filter(function ($quote) use ($freeShippingTitle) {
            return strpos($quote['title'], $freeShippingTitle) !== false;
        })->toArray() ?? [];

        if (!empty($freeShippingQuotesArr)) {
            $freeShippingCheapest = $this->filterSameTitleCheapestQuotes($freeShippingQuotesArr) ?? [];
        }
        if (!empty($freightQuotesArr)) {
            $freightCheapest = $this->filterSameTitleCheapestQuotes($freightQuotesArr) ?? [];
            if (!empty($freeShippingCheapest)) {
                $freightCheapest = array_merge($freightCheapest, $freeShippingCheapest);
            }
        }
        if (!empty($shippingQuotesArr)) {
            $shippingCheapest = $this->filterSameTitleCheapestQuotes($shippingQuotesArr) ?? [];
            if (!empty($freeShippingCheapest)) {
                $shippingCheapest = array_merge($shippingCheapest, $freeShippingCheapest);
            }
        }

        if (empty($freightCheapest) && empty($shippingCheapest) && empty($freeShippingCheapest)) {
            return $finalCheapestQuotes;
        } else if (empty($freightCheapest) && !empty($shippingCheapest)) {
            return $shippingCheapest;
        } else if (!empty($freightCheapest) && empty($shippingCheapest)) {
            return $freightCheapest;
        } else if (!empty($freeShippingCheapest) && empty($freightCheapest) && empty($shippingCheapest)) {
            return $freeShippingCheapest;
        }

        if (!empty($freightCheapest) && !empty($shippingCheapest)) {
            $finalCheapestQuotes = $bothChpeastQuotesArr = [];
            $finalCheapestQuotes = array_merge($freightCheapest, $shippingCheapest);
        }

        return $finalCheapestQuotes;
    }

    private function filterSameTitleCheapestQuotes($finalCheapestQuotes)
    {
        $index = [];
        if (!empty($finalCheapestQuotes)) {
            foreach ($finalCheapestQuotes as $key => $data) {

                $sameTitle = $this->getSameTitleQuotes($data['title'], $finalCheapestQuotes);
                if (!empty($sameTitle) && count($sameTitle) > 1) {

                    foreach ($sameTitle as $key) {
                        $keyToDelete = array_search($key, $finalCheapestQuotes);
                        unset($finalCheapestQuotes[$keyToDelete]);
                    }

                    $cheapest[] = $this->getCheapestQuotesArr($sameTitle) ?? [];
                    $index = array_merge($finalCheapestQuotes, $cheapest);
                }
            }
            if (!empty($index)) {
                return $index;
            }
            return $finalCheapestQuotes;
        }
    }

    private function getSameTitleQuotes($title, $finalCheapestQuotes)
    {
        $SingleQuotesArr = collect($finalCheapestQuotes)->filter(function ($quote) use ($title) {
            $sameTitle = strcmp($quote['title'], $title) == 0;

            return $sameTitle;
        })->toArray() ?? [];
        return $SingleQuotesArr;
    }

    private function getCheapestQuotesArr($quotes): array
    {
        $cheapestQuote = [];
        $quotes = $quotes ?? [];
        if (isset($quotes) && !empty($quotes)) {
            $minRate = min(array_column($quotes, 'rate'));
            foreach ($quotes as $q) {
                if ($q['rate'] == $minRate) {
                    $cheapestQuote = $q;
                    break;
                }
            }
        }

        return $cheapestQuote;
    }

    private function addDbscRates($quotes)
    {
        if (!isset($this->dbscRates) || empty($this->dbscRates)) {
            return $quotes;
        }

        $updatedRates = array_merge($quotes, $this->dbscRates);

        return $updatedRates;
    }

    public
        function limitTitle(
        $quote
    ) {
        $res = $quote['title'];
        if (strpos($res, Functions::$ltlPrefix) !== false) {
            $res = str_replace(Functions::$ltlPrefix, '', $res);
        }
        if (strpos($res, Functions::$smallPrefix) !== false) {
            $res = str_replace(Functions::$smallPrefix, '', $res);
        }

        if (strlen($quote['title']) > 100) {
            $res = explode("w/", $quote['title']);
            $res = Functions::$simpleLTLTitle . ' w/' . $res[1];
        } else if ($quote['title'] == "") {
            $res = $quote['code'];
        }
        return $res;
    }

    /**
     * This function send request and return response
     * $isAssocArray Parameter When TRUE, then returned objects will
     * be converted into associative arrays, otherwise its an object
     * @param $url
     * @param $postData
     * @return object|array
     */
    public
        function sendCurlRequest(
        $url,
        $postData
    ) {
        Log::info('$postData ' . json_encode($postData));
        $fieldString = http_build_query($postData);
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1000);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fieldString);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $output = curl_exec($ch);
            curl_close($ch);
            Log::info('$output ' . $output);
            return json_decode($output, true);
        } catch (\Throwable $e) {
            $result = [];
        }
        return $result;
    }

    public
        function isSmall(
        $carrier
    ) {
        $smallCarriers = ['wweSmall', 'upsSmall', 'fedexSmall', 'unishippersSmall'];
        return in_array($carrier, $smallCarriers);
    }
}