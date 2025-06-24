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
        $resp = $generateReqData->generateEnitureArray($originAddress, $destination, $package['items']);


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
        $finalQuotes = $this->compileQuotes->newGetQuotesResults($quotes, $connectionSettings, $package['origin'], $this->isHazmat, $smalLtlHazmat, $hazmatAllItems, $residential, $freeRNL, $destination, $package['items'], $this->SuppressParcelRates, $store_id, $totalHazmatBoxes);
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
}
