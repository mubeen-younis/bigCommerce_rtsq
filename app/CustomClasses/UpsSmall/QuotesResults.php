<?php


namespace App\CustomClasses\UpsSmall;


use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;
use App\CustomClasses\Functions;

class QuotesResults
{
    public function __construct()
    {
        $this->CompileQuotes = new CompileQuotes();
    }


    public function getServiceRate($data, $serviceDesc, $quoteSettings)
    {
        $amount = $data['totalNetCharge']['Amount'];

        //dd($quoteSettings['rate_source']);
        if (isset($quoteSettings['rate_source']) && $quoteSettings['rate_source'] === 1) {
            $boxFee = $data['boxFees']['Amount'] ?? 0;
            $amount = $data['NegotiatedRates']['Amount'] > 0 ? $data['NegotiatedRates']['Amount'] + $boxFee : $amount;
        }
        $markupIndex = strtolower(str_replace(' ', '_', $serviceDesc) . '_markup');
        $markupIndex = strtolower(str_replace('.', '', $markupIndex));
        $markupValue = $quoteSettings['carrier_services'][$markupIndex] ?? '';
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
        if ($serviceCode == "03" || $serviceCode = 'SR_03' || $serviceCode == "03S" || $serviceCode == 'SR_03S') {
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

    public function getServiceTitle($title, $data, $serviceCode, $quoteSettings, $isResi = false, $showRadNotation = false)
    {
        if ($isResi && $showRadNotation) {
            $title = $title . Constant::RESI_LABEL;
        }
        if (isset($data['totalTransitTimeInDays']) && $data['totalTransitTimeInDays'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 2) {
            $title = $title . ' (Intransit days: ' . $data['totalTransitTimeInDays'] . ')';
        } else if (isset($data['deliveryTimestamp']) && $data['deliveryTimestamp'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 3) {
            $title = $title . ' (Expected delivery by ' . date('m-d-Y', strtotime($data['deliveryTimestamp'])) . ')';
        }
        return $title;
    }

    public function checkGroundTransit($quote, $quoteSettings)
    {
        // Check limited to carrier transit days
        if ($quoteSettings['ground_metric'] == 1) {
            //  2>3
            if (isset($quote['transitTimeInDays']) && isset($quoteSettings['number_of_transit_days']) && $quote['transitTimeInDays'] > $quoteSettings['number_of_transit_days']) {
                return true;
            }
            // Check by calendar days
        } else {
            if (isset($quote['calenderDaysInTransit']) && isset($quoteSettings['number_of_transit_days']) && $quote['calenderDaysInTransit'] > $quoteSettings['number_of_transit_days']) {
                return true;
            }
        }
        return false;
    }


    public function compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $access, $isMultiShipment, $items, $storeId = '')
    {
        $shipments = $this->formateQuoteBeforeCompile($shipments);
        $this->quoteSettings = [];
        $isHazmat = $smalLtlHazmat['smallHazmat'] ?? false;
        $this->quoteSettings = $connectionSettings['ups-small']['quote_settings'] ?? '';
        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if(isset($ship['tnt']['faultstring'])){
                continue;
            }
            if (!isset($ship['q']) || (isset($ship['q']) && empty($ship['q']))) {
                continue;
            }
            if (!isset($ship['severity'] /*&& isset()*/)) {
                $numberOfShipments++;
            }
        }
        if (!$isMultiShipment) {
            $isMultiShipment = is_countable($shipments) && count($shipments) > 1;
        }
        $returnResp = [
            'isMultiShipment' => $isMultiShipment
        ];
        $originQuotes = $multiShipmentQuotes = $multiShipmentQuote = [];
        $shipmentCount = 0;
        $count = 0;
        $access2 = $access;

        $rad_settings = Functions::getRADsettings($storeId) ?? [];
        $showRadNotation = isset($rad_settings['suppress_rad_notation']) && $rad_settings['suppress_rad_notation'];
        
        unset($shipments['air'],$shipments['ground']);
        foreach ($shipments as $origin => $quote) {

            if ((isset($quote['severity']) || (isset($quote['q']) && empty($quote['q'])))) {
                return $this->CompileQuotes->getInsPicAndLocDelQuotes($quote, $allOrigins);                
            }
            if ($count == 0) { //To be checked only once
                // $this->getAutoResidentialTitle('');
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
            }
            $lowestAmount = 0;
            
            $quote['ups_services']['SR_02']  = 'Simple Rate Ups 2nd Day Air';
            $quote['ups_services']['SR_03']  = 'Simple Rate UPS Ground';
            $quote['ups_services']['SR_12']  = 'Simple Rate Ups 3 Day Select';
            $quote['ups_services']['SR_13']  = 'Simple Rate Ups Next Day Air Saver';
            $quote['ups_services']['SR_02S'] = 'UPS Simple Rate 2nd Day Air Saturday';
            $quote['ups_services']['SR_03S'] = 'UPS Simple Rate Ground Saturday';
            $quote['ups_services']['SR_12S'] = 'UPS Simple Rate 3 Day Select Saturday';
            $quote['ups_services']['SR_13S'] = 'UPS Simple Rate Next Day Air Saver Saturday';

            if (isset($quote['q'])) {
                foreach ($quote['q'] as $key => $data) {
                    // Check if service type is checked to show
                    if (isset($data['severity'])) {
                        continue;
                    }
                    if(isset($quote['ups_services'][$key])){
                        $serviceName = $quote['ups_services'][$key];
                        $service = str_replace(' ', '_', strtolower($serviceName));
                        $service = str_replace('.', '', strtolower($service));
                        $isServiceEnabled = $this->quoteSettings['carrier_services'][$service] ?? (strpos($key, 'SR_') && strpos($key, 'S')) || strpos($key, 'S') !== false ? true : false;
                        if(!$isServiceEnabled){
                            continue;
                        }
                    }
                    //  CHeck FOr Ups ground transit days
                    if ($data['serviceType'] == "03" || $data['serviceType'] == "SR_03" || $data['serviceType'] == "03S" || $data['serviceType'] == "SR_03S") {
                        if (isset($this->quoteSettings['number_of_transit_days']) && $this->quoteSettings['number_of_transit_days'] != null && isset($this->quoteSettings['ground_metric']) && $this->quoteSettings['ground_metric'] != null) {
                            $islimited = $this->checkGroundTransit($data, $this->quoteSettings);
                            if ($islimited) {
                                continue;
                            }
                        }
                    }
                    //  CHecks FOr Only quote ground service if hazardous
                    if ($isHazmat && isset($this->quoteSettings['ground_service_for_hazardous_material']) && $this->quoteSettings['ground_service_for_hazardous_material']) {
                        if ($data['serviceType'] != "03" && $data['serviceType'] != "SR_03" && $data['serviceType'] != "03S" && $data['serviceType'] != "SR_03S") {
                            continue;
                        }
                    }

                    $description = $data['serviceDesc'] ?? '';
                    if (!empty($description) && strpos($description, ' Saturday')) {
                        $description = str_replace(' Saturday', '', $description);
                    }
                    // Adding Product and Origin Markup in services if added
                    $productOriginMarkupFee = Functions::calProductOriginMarkupFee($data['totalNetCharge']['Amount'], $origin, $items, $allOrigins);
                    $data['totalNetCharge']['Amount'] = $data['totalNetCharge']['Amount'] + $productOriginMarkupFee;
                    if (isset($this->quoteSettings['rate_source']) && $this->quoteSettings['rate_source'] === 1) {
                        $data['NegotiatedRates']['Amount'] = (float) $data['NegotiatedRates']['Amount'] + $productOriginMarkupFee;
                    }

                    // Adding Markup in services if enabled
                    $price = $this->getServiceRate($data, $description, $this->quoteSettings);
                    $quoteSettings = $this->quoteSettings;
                    $price = $this->addHandlingMarkupOfHazmat($price, $quoteSettings['handling_fee_markup'] ?? 0);
                    // Checking hazmat and adding hazmat amounts in services
                    if ($isHazmat) {
                        if ($isMultiShipment) {
                            if ($hazmatAllItems[$origin] == 'Y') {
                                $price = $this->addHazmatAmountsInServices($price, $data['serviceType'], $this->quoteSettings);
                            }
                        } else {
                            $price = $this->addHazmatAmountsInServices($price, $data['serviceType'], $this->quoteSettings);
                        }
                    }

                    if ($data['serviceType'] == '03' && strpos($access2, '+gd') === false && strpos($data['serviceType'], 'SR_') === false) {
                        $access2 = $access2 . '+gd'; 
                    } else if ((strpos($data['serviceType'], 'SR_') !== false) && strpos($access2, '+sr') === false) {
                        $access2 = $access2 . '+sr'; 
                    } 

                    $title = $this->getServiceTitle($data['serviceDesc'], $data, $data['serviceType'], $this->quoteSettings, $residential, $showRadNotation);
                    $price = (float)str_replace(',', '', $price);
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['code'] = 'parcel_12ups' . $data['serviceType'] . $access2;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['rate'] = $price;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['title'] = $title;

                    $multiShipmentQuotes[$origin][$key] = $originQuotes[$shipmentCount]['shipment'][$key]['simple'];

                }
            }
            $shipmentCount++;
        }
        //dd($multiShipmentQuotes);
        //dd($originQuotes);

        //$multiShipmentQuotes = $this->sortByOrder($multiShipmentQuotes, 'rate');
        //print_r($originQuotes);  exit;
        // Check for mukti shipment finding lowest price in each shipment and adding them for multi shipment

        if ($isMultiShipment) {
            $originQuotesMulti = [];
            $multiShipPrice = 0;
            foreach ($originQuotes as $shipmentKey => $shipment) {
                $netChargeArray = array_column($shipment['shipment'], 'simple');
                $minValueFromNetChargeArr = min(array_column($netChargeArray, 'rate'));

                $multiShipPrice += str_replace(',', '', $minValueFromNetChargeArr);
                $originQuotesMulti[0]['code'] = 'Multiups' . $access2;
                $originQuotesMulti[0]['rate'] = number_format($multiShipPrice, 2);
                $originQuotesMulti[0]['title'] = $residential && $showRadNotation ? Functions::$smallMultiTitle . ' ' . Constant::RESI_LABEL : Functions::$smallMultiTitle;
            }
            foreach ($multiShipmentQuotes as $shipmentKey => $shipment) {
                $keys = array_column($shipment, 'rate');
                array_multisort($keys, SORT_ASC, $shipment);
                $multiShipmentQuote['simple'][$shipmentKey] = array_values($shipment)[0];
            }
            $resp = [
                'checkoutQuotes' => $originQuotesMulti,
                'multiShipmentQuotes' => $multiShipmentQuote,
            ];
            //print_r($resp); exit;
            $returnResp['resp'] = $resp;
            return $returnResp;
        }
        // Doing For SIngle Shipment
        if (!empty($originQuotes)) {
            $originQuotes = array_column(array_values($originQuotes), 'shipment');
            $originQuotes = reset($originQuotes);
            $originQuotes = array_column(array_values($originQuotes), 'simple');
            // Checkking for instore pickup
            $resp = $originQuotes;
            if (!$isMultiShipment && isset($inStoreLdData) && $inStoreLdData) {
                $allQuotes = $this->CompileQuotes->inStoreLocalDeliveryQuotes($originQuotes, $inStoreLdData, $allOrigins);
                $resp = $allQuotes;
            }
            $returnResp['resp'] = $resp;
            return $returnResp;
        }
        /**
         * get quotes if supress is enables
         * refferce issue: https://eniture.atlassian.net/browse/QA-5458
         */
        if (!$isMultiShipment && isset($inStoreLdData) && $inStoreLdData) {
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


    private function formateQuoteBeforeCompile($shipments)
    {
        $servicesDesc = [];
        $shipments = $this->appendSaturdayDelieryServices($shipments);

        foreach ($shipments as $quote) {
            if (isset($quote['ups_services'])) {
                $quote['ups_services']['SR_02'] = 'UPS Simple Rate 2nd Day Air';
                $quote['ups_services']['SR_03'] = 'UPS Simple Rate Ground';
                $quote['ups_services']['SR_12'] = 'UPS Simple Rate 3 Day Select';
                $quote['ups_services']['SR_13'] = 'UPS Simple Rate Next Day Air Saver';
                $quote['ups_services']['SR_02S'] = 'UPS Simple Rate 2nd Day Air Saturday';
                $quote['ups_services']['SR_03S'] = 'UPS Simple Rate Ground Saturday';
                $quote['ups_services']['SR_12S'] = 'UPS Simple Rate 3 Day Select Saturday';
                $quote['ups_services']['SR_13S'] = 'UPS Simple Rate Next Day Air Saver Saturday';

                $servicesDesc = $quote['ups_services'];
                break;
            }
        }
  
        foreach ($shipments as $shipment => $quotes) {
            $temp = [];
            if (!isset($quotes['q']) || !isset($quotes['q']) && isset($quotes['tnt'])) {
                $shipments = [];
                continue;
            }

            if(isset($shipments['ground'])){
                $shipments[$shipment]['binPackagingData']['response'] = $shipments['ground']['binPackagingData']['response'][$shipment] ?? [];
            }
            foreach ($quotes['q'] as $key => $quote) {
                if (!isset($quote['severity']) && isset($servicesDesc[$key])) {

                    if (!in_array($quote['totalNetCharge']['Amount'], $temp)) {
                        $temp[] = $quote['totalNetCharge']['Amount'] ?? 0;
                        $servicesDescKey = $servicesDesc[$key] ?? '';
                        $shipments[$shipment]['q'][$key]['serviceDesc'] = $servicesDescKey;
                        $shipments[$shipment]['q'][$key]['CalenderDaysInTransit'] = $shipments[$shipment]['q'][$key]['GuaranteedDaysToDelivery'];
                        if ($shipments[$shipment]['q'][$key]['CalenderDaysInTransit'] === '') {
                            if (isset($quotes['tnt']['TransitResponse']['ServiceSummary'])) {

                                $shipments[$shipment]['q'][$key]['CalenderDaysInTransit'] = $this->calenderDays($servicesDescKey, $quotes['tnt']['TransitResponse']['ServiceSummary']);
                            }
                        }
                    } else {
                        unset($shipments[$shipment]['q'][$key]);
                    }
                } else {
                    unset($shipments[$shipment]['q'][$key]);
                }
            }
        }

        return $shipments;
    }

    public function calenderDays($fDesc, $tnts)
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

    public function isSaturdayDeliveryEnabled($connSettings)
    {
        if (blank($connSettings)) {
            return false;    
        }   
        
        $isEnabled = false;

        if (isset($connSettings['quote_settings']['saturday_delivery']) && $connSettings['quote_settings']['saturday_delivery']) {
            $isEnabled = true;
        }

        return $isEnabled;
    }

    public function appendSaturdayDelieryServices($shipments)
    {
        if (blank($shipments)) {
            return [];
        }

        foreach ($shipments as $key => $quote) {
            if (isset($quote['ups_services'])) {
                $servicesDesc = $quote['ups_services'];

                if (isset($quote['q']) && !empty($quote['q'])) {
                    $updatedServicesDesc = $this->setAndGetSaturdayDeliveryServices($quote['q'], $servicesDesc);
                    $shipments[$key]['ups_services'] = $updatedServicesDesc;
                }
            }
        }

        return $shipments;
    }

    public function setAndGetSaturdayDeliveryServices($quotes, $servicesDesc)
    {
        foreach ($quotes as $key => $q) {
            $saturdayServiceCode = $key . 'S';
            $normalServiceCode = isset($servicesDesc[$key]) ? $servicesDesc[$key] : '';

            if (isset($quotes[$saturdayServiceCode]) && strpos($key, 'S') === false) {
                $servicesDesc[$saturdayServiceCode] = $this->getSaturdayDelieryServiceTitle($saturdayServiceCode, $normalServiceCode) . ' Saturday';
            }
        }

        return $servicesDesc;
    }

    public function getSaturdayDelieryServiceTitle($serviceCode, $title)
    {
        $serviceTitle = '';

        switch($serviceCode) {
            case '12S':
            case '03S':
            case '59S':
            case '01S':
            case '02S':
            case '13S':
            case '14S':
            case '11S':
            case '07S':
            case '54S':
            case '08S':
            case '65S':
            case '92S':
            case '93S':
            case '94S':
            case '95S':
            case 'GFPS':
            case 'SR_02S':
            case 'SR_03S':
            case 'SR_12S':
            case 'SR_13S':
                $serviceTitle = $title;
                break;
            default:
                $serviceTitle = '';
                break;
        }

        return $serviceTitle;
    }

}
