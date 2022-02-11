<?php


namespace App\CustomClasses\Fedex\small;


use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;
use Illuminate\Support\Str;

class QuotesResults
{
    public function __construct()
    {
        $this->CompileQuotes = new CompileQuotes();
    }


    public function getServiceRate($data, $serviceDesc, $quoteSettings)
    {
        $amount = $data;
        if (isset($quoteSettings['rate_source']) && $quoteSettings['rate_source'] === 1) {
            $boxFee = $data['boxFees']['Amount'] ?? 0;
            $amount = $data['NegotiatedRates']['Amount'] > 0 ? $data['NegotiatedRates']['Amount'] + $boxFee : $amount;
        }
        $markupIndex = strtoupper(str_replace(' ', '_', $serviceDesc) . '_markup');
        $serviceType = isset($this->international) && $this->international ? 'international' : 'domestic';
        $markupValue = $this->allConfigServices['markup'][$serviceType][$markupIndex] ?? '';
        if (empty($markupValue) || !is_numeric(str_replace('%', '', $markupValue))) {
            return $amount;
        }
        if (strpbrk($markupValue, '%') !== FALSE) {
            $amount = $this->getvalueFromPercent($amount, str_replace('%', '', $markupValue));
        } else {
            $amount = $amount + $markupValue;
        }
        return number_format($amount, 2);

    }

    public function addHazmatAmountsInServices($amount, $serviceCode, $quoteSettings)
    {
        // Adding hazmat fee to Ground Service
        if ($serviceCode == "FEDEX_GROUND" || $serviceCode == "GROUND_HOME_DELIVERY" || $serviceCode == "FEDEX_GROUND_HOME_DELIVERY" || $serviceCode == "GROUND_HOME_DELIVERY_AIR_SERVICE") {
            if (isset($quoteSettings['ground_hazardous_material_fee']) && is_numeric($quoteSettings['ground_hazardous_material_fee']) && !empty($quoteSettings['ground_hazardous_material_fee'])) {
                $amount = $amount + $quoteSettings['ground_hazardous_material_fee'];
            }
            // Adding hazmat fee to Air Services
        } else {
            if (isset($quoteSettings['air_hazardous_material_fee']) && is_numeric($quoteSettings['air_hazardous_material_fee']) && !empty($quoteSettings['air_hazardous_material_fee'])) {
                $amount = $amount + $quoteSettings['air_hazardous_material_fee'];
            }
        }
        // $amount = $this->addHandlingMarkupOfHazmat($amount, $quoteSettings['handling_fee_markup']);
        return number_format($amount, 2);

    }

    public function addHandlingMarkupOfHazmat($amount, $markupValue)
    {
        $amount = (float)str_replace(',', '', $amount);
        if (strpbrk($markupValue, '%') !== FALSE) {
            $amount = $this->getvalueFromPercent($amount, str_replace('%', '', $markupValue));
        } else {
            $amount = $amount + $markupValue;
        }
        return $amount;
    }

    public function getvalueFromPercent($amount, $markupPercentage)
    {
        $markupValue = $markupPercentage / 100 * $amount;
        $amountWithMarkup = $amount + $markupValue;
        return $amountWithMarkup;
    }

    public function getServiceTitle($title, $data, $serviceCode, $quoteSettings, $isResi = false)
    {
        if ($title == "Fedex Smart Post") {
            $title = "Fedex SmartPost";
        }
        if ($isResi) {
            $title = $title . Constant::RESI_LABEL;
        }
        if (isset($data['totalTransitTimeInDays']) && $data['totalTransitTimeInDays'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 2) {
            $title = $title . ' (Estimated number of days until delivery is ' . $data['totalTransitTimeInDays'] . ')';
        } else if (isset($data['deliveryTimestamp']) && $data['deliveryTimestamp'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 3) {
            $title = $title . ' (Estimated delivery date is ' . date('m-d-Y', strtotime($data['deliveryTimestamp'])) . ')';
        }
        return $title;
    }

    public function checkGroundTransit($quote, $quoteSettings)
    {
        if ($quoteSettings['ground_metric'] == 1) {
            if (isset($quote['TransitTimeInDays']) && isset($quoteSettings['number_of_transit_days']) &&
                $quote['TransitTimeInDays'] > $quoteSettings['number_of_transit_days']) {
                //  4>3
                return true;
            }
            // Check by calendar days
        } else {
            if (isset($quote['CalenderDaysInTransit']) && isset($quoteSettings['number_of_transit_days']) &&
                $quote['CalenderDaysInTransit'] > $quoteSettings['number_of_transit_days']) {
                return true;
            }
        }
        return false;
    }

    function checkServiceIsEnabled($shipmentId, $serviceName, $allConfigServices)
    {
        $isDomestic = isset($this->allOrigins[$shipmentId]['senderCountryCode']) && isset($this->destination['country']) && strtoupper($this->allOrigins[$shipmentId]['senderCountryCode']) == strtoupper($this->destination['country']);
        $this->international = false;
        if ($isDomestic) {
            if (isset($allConfigServices['domestic']) && in_array($serviceName, $allConfigServices['domestic'])) {
                return true;
            }
        } else {
            $this->international = true;
            if (in_array($serviceName, $allConfigServices['international'])) {
                return true;
            }
        }
        return false;
    }

    function originIndexToShipment($allOrigins)
    {
        $origins = [];
        foreach ($allOrigins as $origin) {
            $origins[$origin['locationId']] = $origin;
        }
        return $origins;
    }


    public function compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $access, $isMultiShipment, $destination)
    {
        $this->allOrigins = $this->originIndexToShipment($allOrigins);
        $this->destination = $destination;
        $shipments = $this->formateQuoteBeforeCompile($shipments);
        $this->quoteSettings = $connectionSettings['fedex-small']['quote_settings'] ?? [];
        $isHazmat = $smalLtlHazmat['smallHazmat'] ?? false;
        $allConfigServices['services'] = $allConfigServices = [];


        if (isset($this->quoteSettings['carrier_services'])) {
            foreach ($this->quoteSettings['carrier_services'] as $key => $serviceName) {
                if ($serviceName) {
                    $isMarkup = strpos(strtolower($key), '_markup') !== false;
                    $isFedex = strpos(strtolower($key), 'fedex') !== false;
                    $isInternational = strpos(strtolower($key), 'international') !== false;
                    if ($isMarkup) {
                        if ($isFedex) {
                            $key1 = str_replace('FEDEX_', '', strtoupper($key));
                            $key2 = strtoupper($key);
                            $allConfigServices['markup']['domestic'][$key1] = $serviceName;
                            $allConfigServices['markup']['domestic'][$key2] = $serviceName;
                            if ($key2 == 'FEDEX_GROUND_MARKUP' || $key2 == 'FEDEX_GROUND_HOME_DELIVERY_MARKUP') {
                                $allConfigServices['markup']['international'][$key2] = $serviceName;
                            }
                        } else if ($isInternational) {
                            $key1 = str_replace('INTERNATIONAL_', '', strtoupper($key));
                            $key2 = strtoupper($key);
                            $allConfigServices['markup']['international'][$key1] = $serviceName;
                            $allConfigServices['markup']['international'][$key2] = $serviceName;
                        }
                    } else {
                        if ($isFedex) {
                            $key1 = str_replace('FEDEX_', '', strtoupper($key));
                            $key2 = strtoupper($key);
                            $allConfigServices['services']['domestic'][] = $key1;
                            $allConfigServices['services']['domestic'][] = $key2;
                            if ($key2 == 'FEDEX_GROUND' || $key2 == 'FEDEX_GROUND_HOME_DELIVERY') {
                                $allConfigServices['services']['international'][] = $key2;
                            }
                        } else if ($isInternational) {
                            $key1 = str_replace('INTERNATIONAL_', '', strtoupper($key));
                            $key2 = strtoupper($key);
                            $allConfigServices['services']['international'][] = $key1;
                            $allConfigServices['services']['international'][] = $key2;
                        } else {
                            $allConfigServices['services']['international'][] = strtoupper($key);
                            $allConfigServices['services']['domestic'][] = strtoupper($key);
                        }
                    }
                }
            }
        }
        $allConfigServices = $this->replaceIndexOfSomeOneRateService($allConfigServices);
        $this->quoteSettingsData();
        $this->allConfigServices = $allConfigServices;
        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = false;
        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }
        if (!$isMultiShipment) {
            $isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }
        $returnResp = [
            'isMultiShipment' => $isMultiShipment
        ];
        $this->isMultiShipment = $isMultiShipment;
        $shipmentCount = 0;
        $count = 0;
        foreach ($shipments as $origin => $quote) {
            if (isset($quote['severity'])) {
                continue;
            }
            if ($count == 0) { //To be checked only once
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
            }
            $lowestAmount = 0;
            if (isset($quote['q'])) {
                foreach ($quote['q'] as $key => $data) {
                    // Check if service type is checked to show
                    $serviceName = str_replace('_ONE_RATE', '', $data['serviceType']);
                    $serviceName = str_replace('_AIR_SERVICE', '', $serviceName);
                    // Added to check one rate service check
                    $tocheckServiceName = Str::contains($data['serviceType'], '_ONE_RATE') ? "ONE_RATE_" . $serviceName : $serviceName;
                    $checkService = $this->checkServiceIsEnabled($origin, $tocheckServiceName, $allConfigServices['services']);
                    if (!$checkService) {
                        continue;
                    }
                    //  CHeck FOr Ups ground transit days
                    if ($serviceName == "FEDEX_GROUND" || $serviceName == "GROUND_HOME_DELIVERY" || $serviceName == "FEDEX_GROUND_HOME_DELIVERY") {
                        if (isset($this->quoteSettings['number_of_transit_days']) && $this->quoteSettings['number_of_transit_days'] != null && isset($this->quoteSettings['ground_metric']) && $this->quoteSettings['ground_metric'] != null) {
                            $islimited = $this->checkGroundTransit($data, $this->quoteSettings);
                            if ($islimited) {
                                continue;
                            }
                        }
                    }
                    //  CHecks FOr Only quote ground service if hazardous
                    if ($isHazmat && isset($this->quoteSettings['ground_service_for_hazardous_material']) && $this->quoteSettings['ground_service_for_hazardous_material']) {
                        if (!($serviceName == "FEDEX_GROUND" || $serviceName == "GROUND_HOME_DELIVERY" || $serviceName == "FEDEX_GROUND_HOME_DELIVERY")) {
                            continue;
                        }
                    }

                    //$access = $this->getAccessorialCodeSmall();
                    // Adding Markup in services if enabled
                    if (isset($this->quoteSettings['negotiated_rates']) && $this->quoteSettings['negotiated_rates'] == 1) {
                        $data['totalNetCharge']['Amount'] = $data['NegotiatedRates']['Amount'] ?? $data['totalNetCharge']['Amount'];
                    }
                    $price = $this->getServiceRate($data['totalNetCharge']['Amount'], $serviceName, $this->quoteSettings);
                    $quoteSettings = $this->quoteSettings;

                    $price = $this->addHandlingMarkupOfHazmat($price, $quoteSettings['handling_fee_markup'] ?? 0);
                    // Checking hazmat and adding hazmat amounts in services
                    if ($isHazmat) {
                        if ($this->isMultiShipment) {
                            if ($hazmatAllItems[$origin] == 'Y') {
                                $price = $this->addHazmatAmountsInServices($price, $data['serviceType'], $this->quoteSettings);
                            }
                        } else {
                            $price = $this->addHazmatAmountsInServices($price, $data['serviceType'], $this->quoteSettings);
                        }
                    }
                    $data['serviceDesc'] = $this->checkAndAppendFedex($data['serviceDesc']);
                    $title = $this->getServiceTitle($data['serviceDesc'], $data, $data['serviceType'], $this->quoteSettings, $residential);
                    $price = (float)str_replace(',', '', $price);
                    /*
                    * Generate random code to limit rate_id to 50 chars
                     */
                    if ($serviceName == "FEDEX_GROUND" || $serviceName == "GROUND_HOME_DELIVERY" || $serviceName == "FEDEX_GROUND_HOME_DELIVERY") {
                        $access2 = $access . '+gd';
                    } elseif (strpos($data['serviceType'], '_AIR_SERVICE')) {
                        $access2 = $access . '+as';
                    } else if (strpos($data['serviceType'], '_ONE_RATE')) {
                        $access2 = $access . '+or';
                    } else {
                        $access2 = $access . '+gd';
                    }

                    $data['serviceType'] = $this->generateRandomString(5);
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['code'] = 'parcel_12fd' . $data['serviceType'] . $access2;


                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['rate'] = $price;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['title'] = $title;
                    if (isset($multiShipmentQuotes['simple'][$origin])) {
                        if ($multiShipmentQuotes['simple'][$origin]['rate'] > $originQuotes[$shipmentCount]['shipment'][$key]['simple']['rate']) {
                            $multiShipmentQuotes['simple'][$origin] = $originQuotes[$shipmentCount]['shipment'][$key]['simple'];
                        }
                    } else {
                        $multiShipmentQuotes['simple'][$origin] = $originQuotes[$shipmentCount]['shipment'][$key]['simple'];
                    }
                }
            }
            $shipmentCount++;
        }
        // $multiShipmentQuotes
        // Check for mukti shipment finding lowest price in each shipment and adding them for multi shipment
        if ($this->isMultiShipment) {
            $originQuotesMulti = [];
            $multiShipPrice = 0;
            if (isset($originQuotes)) {
                foreach ($originQuotes as $shipmentKey => $shipment) {
                    $netChargeArray = array_column($shipment['shipment'], 'simple');
                    $minValueFromNetChargeArr = min(array_column($netChargeArray, 'rate'));
                    $multiShipPrice += str_replace(',', '', $minValueFromNetChargeArr);
                    $originQuotesMulti[0]['code'] = 'Multifedexsmall' . $access2;
                    $originQuotesMulti[0]['rate'] = number_format($multiShipPrice, 2);
                    $originQuotesMulti[0]['title'] = $residential ? 'Shipping ' . Constant::RESI_LABEL : 'Shipping';
                }
            }
            $resp = [
                'checkoutQuotes' => $originQuotesMulti ?? [],
                'multiShipmentQuotes' => $multiShipmentQuotes ?? [],
            ];
            $returnResp['resp'] = $resp;
            return $returnResp;
        }
        // Doing For SIngle Shipment
        //dd($inStoreLdData);

        if (!empty($originQuotes)) {
            $originQuotes = array_column(array_values($originQuotes), 'shipment');
            $originQuotes = reset($originQuotes);
            $originQuotes = array_column(array_values($originQuotes), 'simple');
            // Checkking for instore pickup
            $resp = $originQuotes;
            if (!$this->isMultiShipment && isset($inStoreLdData) && $inStoreLdData) {
                $allQuotes = $this->CompileQuotes->inStoreLocalDeliveryQuotes($originQuotes, $inStoreLdData, $allOrigins);
                $resp = $allQuotes;
            }
            $returnResp = [
                'resp' => $resp ?? [],
                'isMultiShipment' => $isMultiShipment
            ];
            return $returnResp;
        }
        /**
         * get quotes if supress is enables
         * refferce issue: https://eniture.atlassian.net/browse/QA-5458
         */
        if (!$this->isMultiShipment && isset($inStoreLdData) && $inStoreLdData) {
            $allQuotes = $this->CompileQuotes->inStoreLocalDeliveryQuotes($quote, $inStoreLdData, $allOrigins);
            $resp = $allQuotes;
            $returnResp['resp'] = $resp;
            return $returnResp;
        }

        $resp = [
            'resp' => $return ?? [],
            'isMultiShipment' => $isMultiShipment
        ];
        return $resp;
    }

    public function replaceIndexOfSomeOneRateService($allConfigServices)
    {
        if (isset($allConfigServices['services']['domestic'])) {

            foreach ($allConfigServices['services']['domestic'] as $key => $value) {
                if ($value == "ONE_RATE_2_DAY") {
                    $allConfigServices['services']['domestic'][] = "ONE_RATE_FEDEX_2_DAY";
                }
                if ($value == "ONE_RATE_2_DAY_AM") {
                    $allConfigServices['services']['domestic'][] = "ONE_RATE_FEDEX_2_DAY_AM";
                }
                if ($value == "ONE_RATE_EXPRESS_SAVER") {
                    $allConfigServices['services']['domestic'][] = "ONE_RATE_FEDEX_EXPRESS_SAVER";
                }
            }
        }
        return $allConfigServices;
    }

    function generateRandomString($length = 25)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    public function checkAndAppendFedex($serviceName)
    {
        if (!Str::contains($serviceName, 'Fedex')) {
            $serviceName = 'Fedex ' . $serviceName;
        }
        if (Str::contains($serviceName, 'Am')) {
            $serviceName = Str::replace('Am', 'AM', $serviceName);
        }
        return $serviceName;
    }


    public
    function formateQuoteBeforeCompile($shipments)
    {
        //print_r($shipments); exit;
        foreach ($shipments as $shipment => $serviceTypes) {
            $inStoreLocal = [];
            foreach ($serviceTypes as $serviceName => $quotes) {
                if (isset($quotes['InstorPickupLocalDelivery'])) {
                    $inStoreLocal = $quotes['InstorPickupLocalDelivery'];
                }
                $append = '';
                $isOneRate = false;
                if ($serviceName == 'fedexOneRate') {
                    $append = '_ONE_RATE';
                    $isOneRate = true;
                }
                $isAir = false;
                if ($serviceName == 'fedexAirServices') {
                    $isAir = true;
                }
                if (isset($quotes['q'])) {
                    foreach ($quotes['q'] as $key => $quote) {
                        if ($isAir) {
                            if (!$this->isGroundService($key)) {
                                $key = $key . $append;
                                $quote['serviceType'] = $quote['serviceType'] . $append;
                                $shipments[$shipment]['q'][$key] = $quote;
                                $shipments[$shipment]['q'][$key]['serviceDesc'] = ucwords(strtolower(str_replace('_', ' ', $quote['serviceType'])));
                                $shipments[$shipment]['q'][$key]['serviceType'] = $quote['serviceType'] . '_AIR_SERVICE';
                            }
                        } else {
                            $key = $key . $append;
                            $quote['serviceType'] = $quote['serviceType'] . $append;
                            $shipments[$shipment]['q'][$key] = $quote;
                            $shipments[$shipment]['q'][$key]['serviceDesc'] = ucwords(strtolower(str_replace('_', ' ', $quote['serviceType'])));
                        }
                        if (!empty($inStoreLocal)) {
                            $shipments[$shipment]['InstorPickupLocalDelivery'] = $inStoreLocal;
                        }
                    }
                    unset($shipments[$shipment][$serviceName]);
                }
            }
        }
        return $shipments;
    }

    public
    function calenderDays($fDesc, $tnts)
    {
        $resp = '';
        foreach ($tnts as $key => $tnt) {
            $desc = $tnt['Service']['Description'] ?? '';
            if ($desc === $fDesc) {
                $resp = $tnt['EstimatedArrival']['BusinessDaysInTransit'];
            }
        }
        return $resp;
    }

    public
    function quoteSettingsData()
    {
        $fields = [
            'labelAs' => 'labelAs',
            'options' => 'options',
            'ratingMethod' => 'ratingMethod',
            'dlrvyEstimates' => 'dlrvyEstimates',
            'ownArangement' => 'ownArangement',
            'ownArangementText' => 'ownArangementText',
            'residentialDlvry' => 'residentialDlvry',
            'liftGate' => 'liftGate',
            'OfferLiftgateAsAnOption' => 'OfferLiftgateAsAnOption',
            'RADforLiftgate' => 'RADforLiftgate',
            'hndlngFee' => 'hndlngFee',
            'symbolicHndlngFee' => 'symbolicHndlngFee',
        ];
        foreach ($fields as $key => $field) {
            $this->$key = $this->configSettings[$field] ?? '';
        }
        $this->resiLabel = Constant::RESI_LABEL;
        $this->lgLabel = Constant::LIFT_LABEL;
        $this->resiLgLabel = Constant::RESI_LIFT_LABEL;
    }

    public
    function getCompiledQuotes($services, $arraySorting, $lgQuotes, $isMulitshipment)
    {
        if (empty($arraySorting) || empty($services)) {
            return [];
        }
        asort($arraySorting['simple']);
        $options = $isMulitshipment ? 1 : 2;
        $sliced = array_slice($arraySorting['simple'], 0, $options, true);
        $resp = array_intersect_key($services, $sliced);
        return $resp;
    }

    function isGroundService($service)
    {
        $groundServices = ['FEDEX_GROUND', 'HOME_DELIVERY', 'DATE_CERTAIN_HOME_DELIVERY', 'EVENING_HOME_DELIVERY', 'APPOINTMENT_HOME_DELIVERY', 'SMART_POST'];
        return in_array($service, $groundServices);
    }

}
