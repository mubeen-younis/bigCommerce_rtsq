<?php


namespace App\CustomClasses\PurolatorSmall;


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

        $serviceDesc = preg_replace("([A-Z])", " $0", $serviceDesc);
        $trim = ltrim($serviceDesc);
        $markupIndex = strtolower(str_replace(':', ' ', $trim) . '_markup');
        $markupIndex = strtolower(str_replace(' ', '_', $markupIndex));
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
        $serviceDesc = preg_replace("([A-Z])", " $0", $serviceCode);
        $trim = ltrim($serviceDesc);
        if (strpos($trim, 'Ground') !== false) {
            if (isset($quoteSettings['ground_hazardous_material_fee']) && is_numeric($quoteSettings['ground_hazardous_material_fee']) && !empty($quoteSettings['ground_hazardous_material_fee'])) {
                $amount = $amount + $quoteSettings['ground_hazardous_material_fee'];
            }
            // Adding hazmat fee to Air Services
        } else {
            if (isset($quoteSettings['air_hazardous_material_fee']) && is_numeric($quoteSettings['air_hazardous_material_fee']) && !empty($quoteSettings['air_hazardous_material_fee'])) {
                $amount = $amount + $quoteSettings['air_hazardous_material_fee'];
            }
        }
        $amount = $this->addHandlingMarkupOfHazmat($amount, $quoteSettings['handling_fee_markup']);

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

    public function getServiceTitle($title, $data, $serviceCode, $quoteSettings, $isResi = false, $storeId = '')
    {
        $rad_settings = Functions::getRADsettings($storeId) ?? [];
        $showRadNotation = isset($rad_settings['suppress_rad_notation']) && $rad_settings['suppress_rad_notation'];
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
            if (isset($quote['TransitTimeInDays']) && isset($quoteSettings['number_of_transit_days']) && $quote['TransitTimeInDays'] > $quoteSettings['number_of_transit_days']) {
                return true;
            }
            // Check by calendar days
        } else {
            if (isset($quote['CalenderDaysInTransit']) && isset($quoteSettings['number_of_transit_days']) && $quote['CalenderDaysInTransit'] > $quoteSettings['number_of_transit_days']) {
                return true;
            }
        }
        return false;
    }


    public function compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $access, $isMultiShipment, $items, $storeId = '')
    {
        $shipments = $this->formateQuoteBeforeCompile($shipments,$connectionSettings);
        $this->quoteSettings = [];
        $isHazmat = $smalLtlHazmat['smallHazmat'] ?? false;
        $this->quoteSettings = $connectionSettings['purolator-small']['quote_settings'] ?? '';

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
            $isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }
        $returnResp = [
            'isMultiShipment' => $isMultiShipment
        ];
        $originQuotes = $multiShipmentQuotes = $multiShipmentQuote = [];
        $shipmentCount = 0;
        $count = 0;
        foreach ($shipments as $origin => $quote) {

            if ((isset($quote['severity']) || !isset($quote['q']) || (isset($quote['q']) && empty($quote['q'])))) {
                $res['resp']  = $this->CompileQuotes->getInsPicAndLocDelQuotes($quote, $allOrigins);
                return $res;
            }
            if ($count == 0) { //To be checked only once
                // $this->getAutoResidentialTitle('');
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
            }
            $lowestAmount = 0;

            if (isset($quote['q'])) {
                //print_r($quote['q']); exit;
                foreach ($quote['q'] as $key => $data) {
                    // Check if service type is checked to show
                    if (isset($data['severity'])) {
                        continue;
                    }

                    $srvcType = $data['serviceType'];
                     //  Check for Purolator ground transit days
                    $skipService = $this->checkGroundTransit($data, $this->quoteSettings);
                    if ($skipService) {
                        continue;
                    }
                    //  Checks for only quote ground service if hazardous
                    if ($this->onylQuoteGroundServices($isHazmat, $srvcType)) {
                        continue;
                    }

                    // Adding Markup in services if enabled
                    $price = $this->getServiceRate($data, $data['serviceType'], $this->quoteSettings);
                    $quoteSettings = $this->quoteSettings;
                    $productOriginMarkupFee = Functions::calProductOriginMarkupFee($data['totalNetCharge']['Amount'], $origin, $items, $allOrigins);
                    $price = $price + $productOriginMarkupFee;

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

                    $title = $this->getServiceTitle($data['serviceType'], $data, $data['serviceType'], $this->quoteSettings, $residential, $storeId);
                    $price = (float)str_replace(',', '', $price);
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['code'] = 'parcel_12' . $data['serviceType'] . $access;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['rate'] = $price;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['title'] = $title;

                    $multiShipmentQuotes[$origin][$key] = $originQuotes[$shipmentCount]['shipment'][$key]['simple'];

                }
            }
            $shipmentCount++;
        }

        if ($isMultiShipment) {
            $originQuotesMulti = [];
            $multiShipPrice = 0;
            foreach ($originQuotes as $shipmentKey => $shipment) {
                $netChargeArray = array_column($shipment['shipment'], 'simple');
                $minValueFromNetChargeArr = min(array_column($netChargeArray, 'rate'));

                $multiShipPrice += str_replace(',', '', $minValueFromNetChargeArr);
                $originQuotesMulti[0]['code'] = 'Multipurolator' . $access;
                $originQuotesMulti[0]['rate'] = number_format($multiShipPrice, 2);
                $originQuotesMulti[0]['title'] = $residential ? Functions::$smallMultiTitle . ' ' . Constant::RESI_LABEL : Functions::$smallMultiTitle;
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

    private function onylQuoteGroundServices($isHazmat, $srvcType)
    {
        $grdServicesArr = ['PurolatorGround9AM', 'PurolatorGround10:30AM', 'PurolatorGround'];
        $grdSrvcForHazMat = $this->quoteSettings['ground_service_for_hazardous_material'] ?? false;

        if ($isHazmat && isset($grdSrvcForHazMat) && $grdSrvcForHazMat) {
            if (!in_array($srvcType, $grdServicesArr)) {
                return true;
            }
        }

        return false;
    }

    private function formateQuoteBeforeCompile($shipments,$connectionSettings)
    {
        $checkedshipment = [];
        $carrier_services = $connectionSettings['purolator-small']['quote_settings']['carrier_services'];

        foreach ($shipments as $shipkey => $quote) {

            if (isset($quote['severity'])) {
                continue;
            }
            if(isset($quote['q'])){
                foreach ($quote['q'] as $key => $value) {
                    foreach($carrier_services as $service => $checked){
                        $serviceLetter =str_replace('_',' ',$service);
                        $capitalServiceLetter = ucwords($serviceLetter);
                        $serviceType =str_replace('  ',':',$capitalServiceLetter);
                        $serviceType =str_replace(' ','',$serviceType);
                        if($serviceType == $value['serviceType'] && $service == $checked){
                            $checkedshipment[$shipkey]['q'][] = $value;
                        }
                    }
                }
            }
            if(isset($quote['InstorPickupLocalDelivery']) && !empty(($quote['InstorPickupLocalDelivery']))){
                $checkedshipment[$shipkey]['InstorPickupLocalDelivery'] = $quote['InstorPickupLocalDelivery'];
            }
        }

        $servicesDesc = [];
        $shipments = $checkedshipment;

        foreach ($shipments as $shipment => $quotes) {
            $temp = [];
            if (!isset($quotes['q'])) {
                continue;
            }
            $servicesDesc = $quotes['q'];

            foreach ($quotes['q'] as $key => $quote) {
                if (!isset($quote['severity']) && isset($servicesDesc[$key])) {
                    if (!in_array($quote['totalNetCharge']['Amount'], $temp)) {
                        $temp[] = $quote['totalNetCharge']['Amount'] ?? 0;
                        $servicesDescKey = $servicesDesc[$key] ?? '';
                        $shipments[$shipment]['q'][$key]['CalenderDaysInTransit'] = $shipments[$shipment]['q'][$key]['TransitTimeInDays'];
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

}
