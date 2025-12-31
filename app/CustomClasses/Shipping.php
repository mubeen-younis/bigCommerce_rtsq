<?php

namespace App\CustomClasses;

use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;
use App\CustomClasses\DBSC\GetRatesDbsc;
use App\Models\ShippingGroup;
use App\Models\ShippingRule;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use App\Models\RequestTempData;
use App\Models\Store;
use App\Models\BoxSize;
use Carbon\Carbon;
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
    private $flatRateShippingResponse;
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
        $this->flatRateShippingResponse = [];
        $this->showOnlyLocAndInstoreQuote = false;
        $this->instoreQuotes = false;
        $this->locDelQuotes = false;
        $this->multiOrigins = false;
        $this->dbscRates = [];
        $this->dbscOrdWid = [];
        $this->SuppressParcelRates = [];
    }


    /**
     * @param $request
     * @param $storeData
     * @param $connectionSettings
     * @param $quoteSettings
     * @return array | bool
     */
    public function collectRates($request, $storeData, $connectionSettings, $cartInfo, $isDbscInstalled = false, $addressStatus, $initialLineItemData)
    {
        $quoteSettings = $multiShipmentQuotes = [];
        $destination = $request['lineItemData']['destination'];
        $generateReqData = new GenerateRequestData();
        //   init is a function to call it explixitlitly rather constructor

        $generateReqData->_init($quoteSettings, $connectionSettings, $storeData, $destination);
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
        // $destination = $request['lineItemData']['destination'];
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
        // Items that is not associated with Flat rate Shipping Rule and no need to get rates from Ws
        $itemsWithFreeShipping = collect($request['lineItemData']['items'])->where('isFreeShipping', true)->all();
        // Items that is not associated with Shipping Group and need to get rates from Ws
        $itemsWithoutFreeShipping = collect($request['lineItemData']['items'])->where('isFreeShipping', false)->all();

        $originsWithoutFreeShipping = $this->getOriginsAccShipGroup($itemsWithoutFreeShipping, $origins);
        // Items that is associated with Free Shipping
        $originsWithFreeShipping = $this->getOriginsAccShipGroup($itemsWithFreeShipping, $origins);

        if (!blank($itemsWithFreeShipping)) {
            $this->setFlatRateShippingRuleResponse($itemsWithFreeShipping, $originsWithFreeShipping);
            $finalQuotes = [];
            // Check for multishipment flat items
            if (empty($originsWithoutFreeShipping) && empty($itemsWithoutFreeShipping) && count($this->flatRateShippingResponse) > 1) {
                $rate = 0;
                foreach ($this->flatRateShippingResponse as $flatRate) {

                    $rate = $rate + $flatRate['rate'];
                    $finalResp = [
                        'code' => $flatRate['code'],
                        'rate' => $rate,
                        'title' => 'Shipping'
                    ];
                }
                $finalQuotes[] = $finalResp;
            }

            if (blank($itemsWithoutFreeShipping)) {
                $finalResp = $this->formattedFlatRateRuleResponse($finalQuotes);
                $request = $initialLineItemData;
                $this->orderWidgetSave($request, [], [], $finalResp['finalQuotes'], $finalResp['formattedResp'], $cartInfo, [], []);
                return $finalResp['formattedResp'];
            }
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
        $resp = $generateReqData->generateEnitureArray($originAddress, $destination, $package['items'], $request, $storeData, $addressStatus);

        if (empty($resp)) {
            Log::info('Return 5 ' . json_encode($resp));
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
            if ($this->isInsurance === 'Y') {
                if ($this->isSmall($key)) {
                    $carriersArray['carriers'][$key]['api']['includeDeclaredValue'] = 1;
                } else {
                    if ($key == 'wweLTL') {
                        $carriersArray['carriers'][$key]['api']['insureShipment'] = 1;
                    } else if ($key == 'saia') {
                        $carriersArray['carriers'][$key]['api']['includeDeclaredValue'] = 1;
                    }
                }
            }
        }

        // Genearting final request Array
        $requestArr = $generateReqData->generateRequestArray($request, $carriersArray, $package['items'], $cartInfo, $carriersErrorSettings);
        $totalHazmatBoxes = isset($requestArr['requestArr']['hazmatBoxes']) ? $requestArr['requestArr']['hazmatBoxes'] : [];
        unset($requestArr['requestArr']['hazmatBoxes']);
        // Added customization for eniture packaging disabled stores

        $requestArr = (new Customizations())->eniturePackagingCustomization($requestArr, $storeData['store']['hash']);

        // adding packing id if sbs or pallet packaging is occure
        $requestArr = Functions::addPackagingId($requestArr, $package, $store_id);
        $packagingId = isset($requestArr['packaging_id']) ? $requestArr['packaging_id'] : '';
        unset($requestArr['packaging_id']);
        $requestArr = $requestArr['requestArr'] ?? [];

        if (empty($requestArr)) {
            return [];
        }
        $this->SuppressParcelRates = isset($requestArr['SuppressParcelRates']) ? $requestArr['SuppressParcelRates'] : [];
        unset($requestArr['SuppressParcelRates']);
        $url = Constant::QUOTES_URL;
        $smalLtlHazmat = $this->checkIndividualHazmat($requestArr['requestArr']);
        //Sending request to WS to get Quotes
        $quotes = $this->sendCurlRequest($url, $requestArr['requestArr']);
        // dd("232", $requestArr, $quotes);
        Log::info('reqreq>>>>>>>>>>>>>>>>>>>> Request on line 235' . json_encode([
            $requestArr
        ]));

        Log::info('resres>>>>>>>>>>>>>>>>>>>> Response on line 239' . json_encode([
            $quotes
        ]));
        /* Catering Usps carrier packaging response */
        $uspsCarrierArr = $requestArr['requestArr']['carriers']['usps'] ?? [];
        if (isset($uspsCarrierArr) && !empty($uspsCarrierArr)) {
            $apiArray = $uspsCarrierArr['api'] ?? [];
            $uspsBoxBins = $apiArray['boxBins'] ?? [];

            if (isset($apiArray['binResponse']) && !empty($apiArray['binResponse'])) {
                $quotes = $this->addBinResponseToQuotes($apiArray['binResponse'], $quotes, true);
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
        $quotesFromWs = $quotes ?? [];
        $finalQuotes = $this->compileQuotes->newGetQuotesResults($quotes, $connectionSettings, $package['origin'], $this->isHazmat, $smalLtlHazmat, $hazmatAllItems, $residential, $freeRNL, $destination, $package['items'], $this->SuppressParcelRates, $store_id, $totalHazmatBoxes, $request, $storeData, $addressStatus);
        // Get shipping rules for the store
        $shippingRules = ShippingRule::getStoreShippingRules($store_id);
        foreach ($shippingRules as $rule) {
            if ($rule['rule_type'] == 11 && $rule['available'] == 1) {

                // Checking Carriers for Cheapest Rate Rule
                $carriersInReq = $requestArr['requestArr']['carriers'];
                // Total carriers count
                $totalCarriers = count($carriersInReq) ?? 0;

                // Arrays to store LTL and Small carriers
                $ltlCarriers = [];
                $smallCarriers = [];

                // Loop through carriers and categorize
                foreach ($carriersInReq as $carrierName => $carrierData) {
                    if (isset($carrierData['quotestType'])) {
                        if ($carrierData['quotestType'] === 'ltl') {
                            $ltlCarriers[$carrierName] = $carrierData;
                            $totalLtlCarrier = count($ltlCarriers);
                        } elseif ($carrierData['quotestType'] === 'small') {
                            $smallCarriers[$carrierName] = $carrierData;
                            $totalSmallCarrier = count($smallCarriers);
                        }
                    }
                }

                $finalQuotes = $this->applyCheapestShippingRule($finalQuotes, $rule, $totalCarriers, $totalLtlCarrier, $totalSmallCarrier, $carriersInReq);
            }
        }
        Log::info('fffqqqq>>>>>>>>>>>>>>>>>>>> finalQuotes on line 271' . json_encode([
            $finalQuotes
        ]));
        if (!empty($finalQuotes['multiShipmentQuotes'])) {
            $multiShipmentQuotes = $finalQuotes['multiShipmentQuotes'];
            $finalQuotes = $finalQuotes['checkoutQuotes'];
        }

        /*Adding shipping group rates response in quotes
         */
        if (!blank($this->shippingGroupResponse)) {
            $items = data_get($request, 'lineItemData.items');
            $items = $items + $itemsWithShippingGroup;
            $request['lineItemData']['items'] = $items;
            $finalQuotes = $this->addShipGroupRatesInQuotes($finalQuotes);
        }
        if (!blank($this->flatRateShippingResponse)) {
            $flatRate = $this->addFlatRatesResponseInQuotes($finalQuotes, $multiShipmentQuotes, $originsWithoutFreeShipping);
            $finalQuotes = $flatRate['finalQuotes'];
            $multiShipmentQuotes = $flatRate['multiShipmentQuotes'];
        }
        $finalQuotes = $this->addRateId($finalQuotes);

        // Initialize applied_rule tracking for each quote
        foreach ($finalQuotes as &$quote) {
            if (!isset($quote['applied_rule'])) {
                $quote['applied_rule'] = null;
            }
        }
        unset($quote);

        Log::info('////////////// finalQuotes on line 290' . json_encode([
            $finalQuotes
        ]));

        // Apply cheapest carrier rule if rule type 11 is active
        Log::info('====== BEFORE applyCheapestCarrierRule ======');
        Log::info('Total quotes: ' . count($finalQuotes));
        foreach ($finalQuotes as $quote) {
            Log::info('Quote: ' . ($quote['code'] ?? 'NO_CODE') . ' | Rate: ' . ($quote['rate'] ?? 'NO_RATE') . ' | Title: ' . ($quote['title'] ?? 'NO_TITLE'));
        }
        Log::info('==========================================');

        $finalQuotes = $this->applyCheapestCarrierRule($finalQuotes, $shippingRules);

        Log::info('====== AFTER applyCheapestCarrierRule ======');
        Log::info('Total quotes: ' . count($finalQuotes));
        foreach ($finalQuotes as $quote) {
            Log::info('Quote: ' . ($quote['code'] ?? 'NO_CODE') . ' | Rate: ' . ($quote['rate'] ?? 'NO_RATE') . ' | Title: ' . ($quote['title'] ?? 'NO_TITLE'));
        }
        Log::info('==========================================');

        $resp = $this->generateQuoteFormatResponse($finalQuotes);

        Log::info('resppppp>>>>>>>>>>>>>>>>>>>> resp on line 298' . json_encode([
            $finalQuotes
        ]));
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

    protected function setFlatRateShippingRuleResponse($flatRateitems, $origins)
    {
        $this->flatRateShippingResponse = ShippingRule::setFlatRates($flatRateitems, $origins);
    }

    protected function formattedFlatRateRuleResponse($finalQuotes): array
    {
        if (count($this->flatRateShippingResponse) > 1) {
            $finalQuotes = $this->addRateId($finalQuotes);
        } else {
            $finalQuotes = $this->addRateId($this->flatRateShippingResponse);
        }
        $resp = $this->generateQuoteFormatResponse($finalQuotes);
        return ['finalQuotes' => $finalQuotes, 'formattedResp' => $resp];
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

    protected function addFlatRatesResponseInQuotes($finalQuotes, $multiShipmentQuotes, $originsWithoutFreeShipping): array
    {
        if ($this->multiOrigins && empty($multiShipmentQuotes)) {

            $filteredParcel = collect($finalQuotes)->filter(function ($quote) {
                return str_contains($quote['code'], 'parcel_12');
            });

            if (count($filteredParcel)) {
                unset($finalQuotes);
                $cheapest = collect($filteredParcel)->sortBy('rate')->first();
                foreach ($originsWithoutFreeShipping as $origin) {
                    if (isset($this->flatRateShippingResponse[$origin['locationId']]) && $this->flatRateShippingResponse[$origin['locationId']]['code'] == 'flatRateRule') {
                        $cheapest['rate'] += $this->flatRateShippingResponse[$origin['locationId']]['rate'];
                    }
                    $this->flatRateShippingResponse[$origin['locationId']] = $cheapest;
                    break;
                }

                $flatRate['simple'] = $this->flatRateShippingResponse;
                $multiShipmentQuotes[] = $flatRate;
                $rate = 0;

                foreach ($this->flatRateShippingResponse as $quote) {
                    $rate = $rate + $quote['rate'];
                    $sName = explode(' (Delivery', $cheapest['title'])[0] ?? '';
                    $sName = explode(' (Intransit', $cheapest['title'])[0] ?? '';
                    $method = explode('w/', $sName)[1] ?? '';
                    $finalResp = [
                        'code' => 'Multi+',
                        'rate' => $rate,
                        'title' => !empty($method) ? Functions::$smallMultiTitle . ' w/' . $method : Functions::$smallMultiTitle
                    ];
                }

                $finalQuotes[] = $finalResp;
            } else {
                $resp = [];
                foreach ($originsWithoutFreeShipping as $origin) {
                    foreach ($finalQuotes as $quote) {
                        $flatRate = [];
                        $code = 'Multi+';

                        if (isset($this->flatRateShippingResponse[$origin['locationId']]) && $this->flatRateShippingResponse[$origin['locationId']]['code'] == 'flatRateRule') {
                            $quote['rate'] += $this->flatRateShippingResponse[$origin['locationId']]['rate'];
                        }

                        if (strpos($quote['code'], '+LG+ID+NBD') !== false) {
                            $flatRate['lginsidenotifydelivery'] = $this->flatRateShippingResponse;
                            $flatRate['lginsidenotifydelivery'][$origin['locationId']] = $quote;
                            $code = 'Multi+LG+ID+NBD';
                        } else if (strpos($quote['code'], '+LG+ID+LAD') !== false) {
                            $flatRate['lglaccessinsidedelivery'] = $this->flatRateShippingResponse;
                            $flatRate['lglaccessinsidedelivery'][$origin['locationId']] = $quote;
                            $code = 'Multi+LG+ID+LAD';
                        } else if (strpos($quote['code'], '+LG+NBD') !== false) {
                            $flatRate['lgnotifydelivery'] = $this->flatRateShippingResponse;
                            $flatRate['lgnotifydelivery'][$origin['locationId']] = $quote;
                            $code = 'Multi+LG+NBD';
                        } else if (strpos($quote['code'], '+ID+NBD') !== false) {
                            $flatRate['insidenotifydelivery'] = $this->flatRateShippingResponse;
                            $flatRate['insidenotifydelivery'][$origin['locationId']] = $quote;
                            $code = 'Multi+ID+NBD';
                        } else if (strpos($quote['code'], '+LG+LAD') !== false) {
                            $flatRate['limitedaccessLG'] = $this->flatRateShippingResponse;
                            $flatRate['limitedaccessLG'][$origin['locationId']] = $quote;
                            $code = 'Multi+LG+LAD';
                        } else if (strpos($quote['code'], '+ID+LAD') !== false) {
                            $flatRate['laccessinsidedelivery'] = $this->flatRateShippingResponse;
                            $flatRate['laccessinsidedelivery'][$origin['locationId']] = $quote;
                            $code = 'Multi+ID+LAD';
                        } else if (strpos($quote['code'], '+LG+ID') !== false) {
                            $flatRate['insideLiftGateDelivery'] = $this->flatRateShippingResponse;
                            $flatRate['insideLiftGateDelivery'][$origin['locationId']] = $quote;
                            $code = 'Multi+LG+ID';
                        } else if (strpos($quote['code'], '+NBD') !== false) {
                            $flatRate['notifydelivery'] = $this->flatRateShippingResponse;
                            $flatRate['notifydelivery'][$origin['locationId']] = $quote;
                            $code = 'Multi+NBD';
                        } else if (strpos($quote['code'], '+LG') !== false) {
                            $flatRate['liftgate'] = $this->flatRateShippingResponse;
                            $flatRate['liftgate'][$origin['locationId']] = $quote;
                            $code = 'Multi+LG';
                        } else if (strpos($quote['code'], '+LAD') !== false) {
                            $flatRate['limitedaccess'] = $this->flatRateShippingResponse;
                            $flatRate['limitedaccess'][$origin['locationId']] = $quote;
                            $code = 'Multi+LAD';
                        } else if (strpos($quote['code'], '+ID') !== false) {
                            $flatRate['insideDelivery'] = $this->flatRateShippingResponse;
                            $flatRate['insideDelivery'][$origin['locationId']] = $quote;
                            $code = 'Multi+ID';
                        } else {
                            $flatRate['simple'] = $this->flatRateShippingResponse;
                            $flatRate['simple'][$origin['locationId']] = $quote;
                            $code = 'Multi+';
                        }

                        $multiShipmentQuotes[] = $flatRate;
                        $rate = 0;

                        foreach ($flatRate as $rates) {
                            foreach ($rates as $flatQuote) {
                                $rate = $rate + $flatQuote['rate'];
                                $sName = explode(' (Delivery', $quote['title'])[0] ?? '';
                                $sName = explode(' (Intransit', $quote['title'])[0] ?? '';
                                $method = explode('w/', $sName)[1] ?? '';
                                $finalResp = [
                                    'code' => $code,
                                    'rate' => $rate,
                                    'title' => !empty($method) ? Functions::$ltlMultiTitle . ' w/' . $method : Functions::$ltlMultiTitle
                                ];
                            }
                        }
                        $resp[] = $finalResp;
                    }
                    $finalQuotes = $resp;
                    break;
                }
            }
        } elseif ($this->multiOrigins && !empty($multiShipmentQuotes)) {
            $index = 0;

            while (isset($multiShipmentQuotes[$index])) {
                foreach ($multiShipmentQuotes[$index] as $key => $quotes) {
                    foreach ($quotes as $origin => $quote) {
                        if (isset($this->flatRateShippingResponse[$origin]['rate'])) {
                            $multiShipmentQuotes[$index][$key][$origin]['rate'] += $this->flatRateShippingResponse[$origin]['rate'];
                        }
                    }
                }
                $index++;
            }

            foreach ($finalQuotes as $key => $quote) {
                foreach ($this->flatRateShippingResponse as $flatRate) {
                    $finalQuotes[$key]['rate'] = $quote['rate'] + $flatRate['rate'];
                }
            }
        } else {

            foreach ($finalQuotes as $key => $quote) {
                foreach ($this->flatRateShippingResponse as $flatRate) {
                    $finalQuotes[$key]['rate'] = $quote['rate'] + $flatRate['rate'];
                }
            }
        }

        return ['finalQuotes' => $finalQuotes, 'multiShipmentQuotes' => $multiShipmentQuotes];
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

    public function isSmallCarrier($carrierName)
    {
        $smallCarriers = [
            'wweSmall',
            'wweSmallN',
            'upsSmall',
            'fedexSmall',
            'unishippersSmall',
            'usps',
            'purolator',
            'shipEngine'
        ];
        return in_array($carrierName, $smallCarriers);
    }

    public function isLtlCarrier($carrierName)
    {
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

    private function addBoxFeeToQuotes($quotes, $boxFee, $fedexBoxesFee = [])
    {
        $parcelCarName = ['wweSmall', 'upsSmall', 'fedexSmall', 'unishippersSmall', 'usps', 'shipEngine', 'wweSmallN', 'purolator'];
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
                                } elseif (isset($qs['totalOfferPrice']['value'])) {
                                    if (isset($boxFee[$locId])) {
                                        $quotes[$carName][$locId]['q'][$key]['totalOfferPrice']['value'] = $qs['totalOfferPrice']['value'] + $boxFee[$locId];
                                        $quotes[$carName][$locId]['q'][$key]['boxFees']['Amount'] = $boxFee[$locId];
                                    }
                                } elseif (isset($qs['shipping_amount']['amount'])) {
                                    if (isset($boxFee[$locId])) {
                                        $quotes[$carName][$locId]['q'][$key]['shipping_amount']['amount'] = $qs['shipping_amount']['amount'] + $boxFee[$locId];
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
            $bins = (object)$bins;
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
            $requestTempData = new RequestTempData();
            $requestTempData->request = json_encode($requestArr);
            $requestTempData->lineitems = json_encode($lineItems);
            $requestTempData->quotes = json_encode($quotes);
            $requestTempData->response = json_encode($resp);
            $requestTempData->multiShipmentresponse = json_encode($multiShipmentQuotes, JSON_FORCE_OBJECT);
            $requestTempData->store_id = $cartInfo['store_id'];
            $requestTempData->rate_id = $finalQuote['rate_id'];
            $requestTempData->cart_id = $cartInfo['cartId'];
            $requestTempData->is_draft_order = $cartInfo['is_draft_order'] ?? false;
            $requestTempData->box_bins = json_encode($boxbins);
            $requestTempData->shipping_group_resp = !blank($this->shippingGroupResponse) ? json_encode($this->shippingGroupResponse) : null;
            $requestTempData->flat_rate_resp = !blank($this->flatRateShippingResponse) ? json_encode($this->flatRateShippingResponse) : null;
            $requestTempData->dbsc_resp = !blank($this->dbscOrdWid) ? json_encode($this->dbscOrdWid) : null;
            $requestTempData->save();
        }
    }

    public function addRateId(
        $finalQuotes
    ) {
        $time = time();
        foreach ($finalQuotes as $key => $finalQuote) {
            $finalQuotes[$key]['rate_id'] = isset($finalQuote['code']) ? $finalQuote['code'] . 'idx+' . $key . $time : $time;
        }
        return $finalQuotes;
    }

    public function checkInstorePickup(
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
    public function isHazmatMaterial(
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

    private function checkIndividualHazmat(
        $request
    ) {
        // TODO: Need to Add small and Ltl Carriers Here as well

        $smallOrigins = $marketItemSmall = $request['carriers']['wweSmall']['originAddress'] ?? $request['carriers']['upsSmall']['originAddress'] ?? $request['carriers']['fedexSmall']['originAddress']
            ?? $request['carriers']['unishippersSmall']['originAddress'] ?? $request['carriers']['wweSmallN']['originAddress']
            ?? $request['carriers']['usps']['originAddress'] ?? $request['carriers']['purolator']['originAddress'] ?? $request['carriers']['shipEngine']['originAddress'] ?? [];
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
    public function isInsurance(
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
    public function getAllowedMethods()
    {
        return [$this->_code => $this->getConfigData('name')];
    }

    /**
     * @param $quotes
     * @return array
     */
    public function setCarrierRates(
        $quotes
    ) {
        return $quotes = $quotes ?? [];
    }

    public function generateQuoteFormatResponse(
        $quotes
    ) {
        $onlyDbscEnabled = false;
        if (empty(array_filter($quotes)) && isset($this->dbscRates) && !empty($this->dbscRates)) {
            $onlyDbscEnabled = true;
            $quotes = $this->addDbscRates($quotes);
        }

        $quotes = array_values($quotes);
        $current = str_replace(' ', 'T', Carbon::now()) . "-0000";
        if (!empty(array_filter($quotes))) {
            $resp['quote_id'] = (string)rand(1, 9); // need to change
            $resp['messages'] = []; // need to change

            if (!$onlyDbscEnabled) {
                $quotes = $this->freeShippingTitle($quotes);
                $quotes = $this->formatCheapestFinalQuotes($quotes);
                $quotes = $this->addDbscRates($quotes);
            }

            $resp['carrier_quotes'][0] = ['carrier_info' => ['code' => 'eniture_quotes', 'display_name' => $this->limitTitle($quotes[0])]];

            foreach ($quotes as $key => $quote) {
                $quoteData = [
                    'code' => $quote['code'],
                    'rate_id' => $quote['rate_id'],
                    'display_name' => $this->limitTitle($quote),
                    'cost' => ['currency' => 'USD', 'amount' => str_replace(',', '', $quote['rate'])],
                    'dispatch_date' => "$current",
                ];

                // Add applied_rule if it exists
                if (!empty($quote['applied_rule'])) {
                    $quoteData['applied_rule'] = $quote['applied_rule'];
                }

                $resp['carrier_quotes'][0]['quotes'][$key] = $quoteData;
            }
        } else {
            $resp = [];
        }

        Log::info('Last response for quotes ' . json_encode($resp));
        if (request()->filled('qa_testing') && request('qa_testing') === 'yes') {
            $wsQuotes = $GLOBALS['ws_quotes'] ?? [];
            $resp['ws_response'] = json_decode(json_encode($wsQuotes), true);
        }
        return $resp;
    }

    public function freeShippingTitle($finalQuotes)
    {
        foreach ($finalQuotes as $key => $quote) {
            if (isset($quote['rate']) && ($quote['rate'] <= 0)) {
                $finalQuotes[$key]['rate'] = 0;
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

                $sameTitle = $this->getSameTitleQuotes($data['title'] ?? '', $finalCheapestQuotes);
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

    public function limitTitle(
        $quote
    ) {
        $res = $quote['title'];
        if (strpos($res, Functions::$ltlPrefix) !== false) {
            $res = str_replace(Functions::$ltlPrefix, '', $res);
        }
        if (strpos($res, Functions::$smallPrefix) !== false) {
            $res = str_replace(Functions::$smallPrefix, '', $res);
        }

        if (strlen($quote['title']) >= 100) {
            $res = explode("w/", $quote['title']);
            if ($quote['code'] === 'INSP') {
                return $res[0];
            }
            if (strpos(strtolower($quote['code']), '+hat')) {
                $res = explode(" |", $quote['title']);
                return str_replace($res[0], Functions::$simpleLTLTitle, $quote['title']);
            }
            $string = str_replace('residential', 'resi', $res[1]);
            $res = Functions::$simpleLTLTitle . ' w/' . $string;
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
    public function sendCurlRequest(
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

    public function isSmall(
        $carrier
    ) {
        $smallCarriers = ['wweSmall', 'upsSmall', 'fedexSmall', 'unishippersSmall', 'shipEngine'];
        return in_array($carrier, $smallCarriers);
    }

    /**
     * Apply cheapest carrier rule (rule type 11)
     * Filters quotes to show only the cheapest carrier when rule type 11 is active
     *
     * @param array $finalQuotes
     * @param array $shippingRules
     * @return array
     */
    private function applyCheapestCarrierRule($finalQuotes, $shippingRules)
    {
        if (empty($finalQuotes) || empty($shippingRules)) {
            return $finalQuotes;
        }

        // Check if shipping rule type 11 (cheapest carrier rule) is active and available
        $cheapestCarrierRuleActive = false;
        $applyToProviders = 'ltl_and_parcel'; // Default value
        $activeRule = null; // Store the active rule for tracking
        foreach ($shippingRules as $rule) {
            if (isset($rule['rule_type']) && $rule['rule_type'] == 11 && isset($rule['available']) && $rule['available'] == 1) {
                $cheapestCarrierRuleActive = true;
                $applyToProviders = $rule['apply_to_providers'] ?? 'ltl_and_parcel';
                $activeRule = $rule; // Store rule details
                break;
            }
        }

        if (!$cheapestCarrierRuleActive) {
            Log::info('Cheapest carrier rule (type 11) is not active');
            return $finalQuotes;
        }

        Log::info('Cheapest carrier rule (type 11) is active with provider type: ' . $applyToProviders);
        Log::info('Total quotes before applying rule: ' . count($finalQuotes));

        // Special handling for "LTL and parcel providers" - show cheapest from BOTH types
        if ($applyToProviders === 'ltl_and_parcel') {
            Log::info('Applying ltl_and_parcel logic - will return cheapest LTL carrier AND cheapest Parcel carrier');
            return $this->getCheapestLtlAndParcel($finalQuotes, $activeRule);
        }

        // Filter quotes based on provider type selection and find cheapest carrier
        Log::info('Applying provider type filter: ' . $applyToProviders);
        $filteredQuotes = $this->filterQuotesByProviderType($finalQuotes, $applyToProviders, $activeRule);

        if (empty($filteredQuotes)) {
            Log::info('No quotes found matching the provider type filter: ' . $applyToProviders);
            return $finalQuotes;
        }

        Log::info('Final filtered quotes count after applying cheapest carrier rule: ' . count($filteredQuotes));
        return $filteredQuotes;
    }

    /**
     * Filter quotes by provider type (LTL, Parcel, or both)
     *
     * @param array $quotes
     * @param string $providerType Options: 'ltl', 'parcel', 'cheapest_ltl_or_parcel'
     * @param array $activeRule The active shipping rule for tracking
     * @return array
     */
    private function filterQuotesByProviderType($quotes, $providerType, $activeRule = null)
    {
        if ($providerType === 'cheapest_ltl_or_parcel') {
            // Find cheapest from LTL and cheapest from Parcel, then compare and return ALL quotes from the cheaper type
            return $this->getCheapestLtlOrParcel($quotes, $activeRule);
        }

        // Filter by specific provider type (ltl or parcel) and find cheapest carrier
        $filteredQuotes = [];
        foreach ($quotes as $quote) {
            if (!isset($quote['code'])) {
                continue;
            }

            $isSmall = $this->isQuoteFromSmallCarrier($quote['code']);
            $isLtl = $this->isQuoteFromLtlCarrier($quote['code']);

            if ($providerType === 'parcel' && $isSmall) {
                $filteredQuotes[] = $quote;
            } elseif ($providerType === 'ltl' && $isLtl) {
                $filteredQuotes[] = $quote;
            }
        }

        Log::info("Filtered quotes by provider type '{$providerType}': " . count($filteredQuotes) . ' quotes');

        // Now find the cheapest carrier from the filtered quotes
        if (empty($filteredQuotes)) {
            return $filteredQuotes;
        }

        // Group filtered quotes by carrier
        $carrierGroups = [];
        foreach ($filteredQuotes as $quote) {
            if (!isset($quote['rate'])) {
                continue;
            }

            $carrierCode = $this->extractCarrierFromCode($quote['code']);
            if (!isset($carrierGroups[$carrierCode])) {
                $carrierGroups[$carrierCode] = [];
            }
            $carrierGroups[$carrierCode][] = $quote;
        }

        // If only one carrier, return all its quotes
        if (count($carrierGroups) <= 1) {
            foreach ($filteredQuotes as &$quote) {
                $quote['applied_rule'] = $activeRule['rule_name'] ?? 'Cheapest Carrier Rule (Type 11)';
            }
            unset($quote);
            return $filteredQuotes;
        }

        // Find the cheapest carrier
        $cheapestCarrierCode = null;
        $cheapestRate = PHP_FLOAT_MAX;

        foreach ($carrierGroups as $carrierCode => $carrierQuotes) {
            $minRate = PHP_FLOAT_MAX;
            foreach ($carrierQuotes as $quote) {
                $rate = floatval($quote['rate']);
                if ($rate < $minRate) {
                    $minRate = $rate;
                }
            }

            if ($minRate < $cheapestRate) {
                $cheapestRate = $minRate;
                $cheapestCarrierCode = $carrierCode;
            }
        }

        Log::info("Cheapest {$providerType} carrier: {$cheapestCarrierCode} with minimum rate: {$cheapestRate}");

        // Return only quotes from the cheapest carrier
        if ($cheapestCarrierCode !== null && isset($carrierGroups[$cheapestCarrierCode])) {
            $result = $carrierGroups[$cheapestCarrierCode];

            // Add rule info to each quote
            foreach ($result as &$quote) {
                $quote['applied_rule'] = $activeRule['rule_name'] ?? 'Cheapest Carrier Rule (Type 11)';
            }
            unset($quote);

            return $result;
        }

        return $filteredQuotes;
    }

    /**
     * Get cheapest LTL carrier AND cheapest Parcel carrier (both)
     * Returns quotes from the cheapest LTL carrier + quotes from the cheapest Parcel carrier
     *
     * @param array $quotes
     * @param array $activeRule The active shipping rule for tracking
     * @return array
     */
    private function getCheapestLtlAndParcel($quotes, $activeRule = null)
    {
        Log::info('===== getCheapestLtlAndParcel START =====');
        Log::info('Total input quotes: ' . count($quotes));

        $ltlQuotes = [];
        $parcelQuotes = [];

        // Separate quotes by type
        foreach ($quotes as $quote) {
            if (!isset($quote['code']) || !isset($quote['rate'])) {
                Log::info('Skipping quote - missing code or rate');
                continue;
            }

            $isSmall = $this->isQuoteFromSmallCarrier($quote['code']);
            $isLtl = $this->isQuoteFromLtlCarrier($quote['code']);

            Log::info('Quote: ' . $quote['code'] . ' | Rate: ' . $quote['rate'] . ' | isSmall: ' . ($isSmall ? 'YES' : 'NO') . ' | isLTL: ' . ($isLtl ? 'YES' : 'NO'));

            if ($isSmall) {
                $parcelQuotes[] = $quote;
            } elseif ($isLtl) {
                $ltlQuotes[] = $quote;
            }
        }

        Log::info('Separated quotes - LTL: ' . count($ltlQuotes) . ' | Parcel: ' . count($parcelQuotes));

        // Group LTL quotes by carrier and find cheapest LTL carrier
        $ltlCarrierGroups = [];
        foreach ($ltlQuotes as $quote) {
            $carrierCode = $this->extractCarrierFromCode($quote['code']);
            if (!isset($ltlCarrierGroups[$carrierCode])) {
                $ltlCarrierGroups[$carrierCode] = [];
            }
            $ltlCarrierGroups[$carrierCode][] = $quote;
        }

        $cheapestLtlCarrier = null;
        $cheapestLtlRate = PHP_FLOAT_MAX;
        foreach ($ltlCarrierGroups as $carrierCode => $carrierQuotes) {
            $minRate = PHP_FLOAT_MAX;
            foreach ($carrierQuotes as $quote) {
                $rate = floatval($quote['rate']);
                if ($rate < $minRate) {
                    $minRate = $rate;
                }
            }
            if ($minRate < $cheapestLtlRate) {
                $cheapestLtlRate = $minRate;
                $cheapestLtlCarrier = $carrierCode;
            }
        }

        // Group Parcel quotes by carrier and find cheapest Parcel carrier
        $parcelCarrierGroups = [];
        foreach ($parcelQuotes as $quote) {
            $carrierCode = $this->extractCarrierFromCode($quote['code']);
            if (!isset($parcelCarrierGroups[$carrierCode])) {
                $parcelCarrierGroups[$carrierCode] = [];
            }
            $parcelCarrierGroups[$carrierCode][] = $quote;
        }

        $cheapestParcelCarrier = null;
        $cheapestParcelRate = PHP_FLOAT_MAX;
        foreach ($parcelCarrierGroups as $carrierCode => $carrierQuotes) {
            $minRate = PHP_FLOAT_MAX;
            foreach ($carrierQuotes as $quote) {
                $rate = floatval($quote['rate']);
                if ($rate < $minRate) {
                    $minRate = $rate;
                }
            }
            if ($minRate < $cheapestParcelRate) {
                $cheapestParcelRate = $minRate;
                $cheapestParcelCarrier = $carrierCode;
            }
        }

        // Combine quotes from both cheapest carriers
        $combinedQuotes = [];

        Log::info('LTL Carrier Groups found: ' . count($ltlCarrierGroups) . ' | Parcel Carrier Groups found: ' . count($parcelCarrierGroups));

        if ($cheapestLtlCarrier !== null && isset($ltlCarrierGroups[$cheapestLtlCarrier])) {
            $ltlQuotesCount = count($ltlCarrierGroups[$cheapestLtlCarrier]);
            $combinedQuotes = array_merge($combinedQuotes, $ltlCarrierGroups[$cheapestLtlCarrier]);
            Log::info("✓ Adding Cheapest LTL carrier: {$cheapestLtlCarrier} with minimum rate: {$cheapestLtlRate} ({$ltlQuotesCount} quotes)");
        } else {
            Log::info("✗ No LTL carrier found to add");
        }

        if ($cheapestParcelCarrier !== null && isset($parcelCarrierGroups[$cheapestParcelCarrier])) {
            $parcelQuotesCount = count($parcelCarrierGroups[$cheapestParcelCarrier]);
            $combinedQuotes = array_merge($combinedQuotes, $parcelCarrierGroups[$cheapestParcelCarrier]);
            Log::info("✓ Adding Cheapest Parcel carrier: {$cheapestParcelCarrier} with minimum rate: {$cheapestParcelRate} ({$parcelQuotesCount} quotes)");
        } else {
            Log::info("✗ No Parcel carrier found to add");
        }

        if (!empty($combinedQuotes)) {
            Log::info("LTL and Parcel: Returning " . count($combinedQuotes) . " quotes from both cheapest carriers");

            // Add rule info to each quote
            foreach ($combinedQuotes as &$quote) {
                $quote['applied_rule'] = $activeRule['rule_name'] ?? 'Cheapest Carrier Rule (Type 11)';
            }
            unset($quote);

            Log::info('===== getCheapestLtlAndParcel END - SUCCESS =====');
            return $combinedQuotes;
        }

        // If no LTL or Parcel quotes found, return all quotes
        Log::info('===== getCheapestLtlAndParcel END - FALLBACK (returning all quotes) =====');
        return $quotes;
    }

    /**
     * Get cheapest LTL or Parcel quotes (whichever TYPE is cheaper overall)
     * Compares cheapest LTL rate vs cheapest Parcel rate
     * Returns ALL quotes from whichever TYPE has the cheaper minimum rate
     *
     * @param array $quotes
     * @param array $activeRule The active shipping rule for tracking
     * @return array
     */
    private function getCheapestLtlOrParcel($quotes, $activeRule = null)
    {
        $ltlQuotes = [];
        $parcelQuotes = [];

        // Separate quotes by type
        foreach ($quotes as $quote) {
            if (!isset($quote['code']) || !isset($quote['rate'])) {
                continue;
            }

            if ($this->isQuoteFromSmallCarrier($quote['code'])) {
                $parcelQuotes[] = $quote;
            } elseif ($this->isQuoteFromLtlCarrier($quote['code'])) {
                $ltlQuotes[] = $quote;
            }
        }

        // Find cheapest rate from each type
        $cheapestLtl = PHP_FLOAT_MAX;
        $cheapestParcel = PHP_FLOAT_MAX;

        foreach ($ltlQuotes as $quote) {
            $rate = floatval($quote['rate']);
            if ($rate < $cheapestLtl) {
                $cheapestLtl = $rate;
            }
        }

        foreach ($parcelQuotes as $quote) {
            $rate = floatval($quote['rate']);
            if ($rate < $cheapestParcel) {
                $cheapestParcel = $rate;
            }
        }

        // Return ALL quotes from the cheaper type
        if ($cheapestLtl < $cheapestParcel) {
            Log::info("Cheapest LTL rate ({$cheapestLtl}) is cheaper than Parcel ({$cheapestParcel}), returning ALL LTL quotes");

            // Add rule info to each quote
            foreach ($ltlQuotes as &$quote) {
                $quote['applied_rule'] = $activeRule['rule_name'] ?? 'Cheapest Carrier Rule (Type 11)';
            }
            unset($quote);

            return $ltlQuotes;
        } else if ($cheapestParcel < PHP_FLOAT_MAX) {
            Log::info("Cheapest Parcel rate ({$cheapestParcel}) is cheaper than or equal to LTL ({$cheapestLtl}), returning ALL Parcel quotes");

            // Add rule info to each quote
            foreach ($parcelQuotes as &$quote) {
                $quote['applied_rule'] = $activeRule['rule_name'] ?? 'Cheapest Carrier Rule (Type 11)';
            }
            unset($quote);

            return $parcelQuotes;
        }

        // If neither has valid quotes, return all
        return $quotes;
    }

    /**
     * Check if quote is from a small/parcel carrier based on code
     *
     * @param string $code
     * @return bool
     */
    private function isQuoteFromSmallCarrier($code)
    {
        // Check if code contains parcel pattern
        if (strpos($code, 'parcel_') !== false) {
            return true;
        }

        // Check against small carrier codes
        $smallCarrierPatterns = ['wweSmall', 'upsSmall', 'fedexSmall', 'unishippersSmall', 'usps', 'purolator', 'shipEngine'];
        foreach ($smallCarrierPatterns as $pattern) {
            if (strpos($code, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if quote is from an LTL carrier based on code
     *
     * @param string $code
     * @return bool
     */
    private function isQuoteFromLtlCarrier($code)
    {
        // Check if code contains ltl pattern
        if (strpos($code, 'ltl_') !== false || strpos($code, 'LTL') !== false) {
            return true;
        }

        // Check against LTL carrier codes
        $ltlCarrierPatterns = ['wweLTL', 'upsLTL', 'fedexLTL', 'globalTranz', 'xpoLTL', 'rnlLTL', 'yrcLTL', 'odfl', 'abf', 'saia', 'estes'];
        foreach ($ltlCarrierPatterns as $pattern) {
            if (stripos($code, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract carrier identifier from quote code
     * Examples: 'parcel_12fdPexYl+R+gd' -> 'fd' (Fedex), 'parcel_12ups01+R' -> 'ups'
     *
     * @param string $code
     * @return string
     */
    private function extractCarrierFromCode($code)
    {
        // Handle special codes
        if ($code === 'shippingRule' || $code === 'flatRateRule') {
            return $code;
        }

        // Handle Multi+ codes (multi-shipment)
        if (strpos($code, 'Multi+') !== false) {
            return 'multi_shipment';
        }

        // For parcel codes like 'parcel_12fdPexYl+R+gd' or 'parcel_12ups01+R'
        // Extract the carrier identifier after 'parcel_12'
        if (preg_match('/parcel_\d+([a-z]+)/i', $code, $matches)) {
            // Returns 'fd' for Fedex, 'ups' for UPS, etc.
            return strtolower($matches[1]);
        }

        // For LTL codes or other patterns
        if (preg_match('/^([a-zA-Z]+)_/', $code, $matches)) {
            return strtolower($matches[1]);
        }

        // Fallback: use the whole code as carrier identifier
        return $code;
    }

    public function applyCheapestShippingRule($finalQuotes, $rule, $totalCarriers, $totalLtlCarrier, $totalSmallCarrier, $carriersInReq)
    {
        // -------------------------
        $cheapestCarrierQuotes = $finalQuotes;
        $cheapestLTLCarrierQuotes = [];
        $cheapestSmallCarrierQuotes = [];
        $ltlGroups = [];
        $smallGroups = [];
        // 1. Group by carrier prefix (before "ltl")
        if (!($rule['cheapest_rate_for_carriers'] == 2)) {
            foreach ($finalQuotes as $quote) {
                if (preg_match('/^(.*?ltl)/', $quote['code'], $match)) {
                    $carrierKey = $match[1];
                    $ltlGroups[$carrierKey][] = $quote;
                }
            }
            // 2. Find cheapest rate per carrier
            $carrierMinRates = [];
            foreach ($ltlGroups as $carrier => $quotes) {
                $minRate = max(array_column($quotes, 'rate'));
                $carrierMinRates[$carrier] = $minRate;
            }
            // 3. Find carrier with overall cheapest rate
            if (!empty($ltlGroups)) {
                $cheapestCarrier = array_keys($carrierMinRates, min($carrierMinRates))[0] ?? [];
                // 4. Return only that carrier's quotes
                $cheapestLTLCarrierQuotes = $ltlGroups[$cheapestCarrier];
            }

            if (($rule['cheapest_rate_for_carriers'] == 1 && $totalLtlCarrier > 1)) {
                $cheapestCarrierQuotes = $cheapestLTLCarrierQuotes;
            }
        }

        // Small Carriers
        if (!($rule['cheapest_rate_for_carriers'] == 1)) {
            foreach ($finalQuotes as $quote) {

                // preg_match('/^\S+/', $quote['title'], $match);
                // $carrierKey = $match[0];
                if (preg_match('/^(parcel_12..)/', $quote['code'], $match)) {
                    $carrierKey = $match[1];
                    $smallGroups[$carrierKey][] = $quote;
                }
            }
            // 2. Find cheapest rate per carrier
            $carrierMinRates = [];
            foreach ($smallGroups as $carrier => $quotes) {
                $minRate = max(array_column($quotes, 'rate'));
                $carrierMinRates[$carrier] = $minRate;
            }
            // 3. Find carrier with overall cheapest rate
            $cheapestCarrier = array_keys($carrierMinRates, min($carrierMinRates))[0];

            // 4. Return only that carrier's quotes
            $cheapestSmallCarrierQuotes = $smallGroups[$cheapestCarrier];

            if (($rule['cheapest_rate_for_carriers'] == 2 && $totalSmallCarrier > 1)) {
                $cheapestCarrierQuotes = $cheapestSmallCarrierQuotes;
            }
        }
        // LTL and Small Carrier Cheapest Rate
        if (($rule['cheapest_rate_for_carriers'] == 3 && $totalSmallCarrier >= 1 && $totalLtlCarrier >= 1)) {
            $cheapestCarrierQuotes =  array_merge($cheapestLTLCarrierQuotes, $cheapestSmallCarrierQuotes);
        }

        // LTL or Small Carrier Cheapest Rate
        if (($rule['cheapest_rate_for_carriers'] == 4 && $totalSmallCarrier >= 1 && $totalLtlCarrier >= 1)) {
            // Highest rate from small carriers
            if (!empty($cheapestLTLCarrierQuotes) && !empty($cheapestSmallCarrierQuotes)) {

                $highestSmall = max(array_column($cheapestSmallCarrierQuotes, 'rate'));

                // Highest rate from LTL carriers
                $highestLTL = max(array_column($cheapestLTLCarrierQuotes, 'rate'));

                if ($highestSmall < $highestLTL) {
                    $cheapestCarrierQuotes = $cheapestSmallCarrierQuotes;
                }

                if ($highestSmall > $highestLTL) {
                    $cheapestCarrierQuotes = $cheapestLTLCarrierQuotes;
                }
            }
        }
        return $cheapestCarrierQuotes;
        // ------------------------
    }
}
