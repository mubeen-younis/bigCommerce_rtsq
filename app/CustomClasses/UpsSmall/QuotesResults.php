<?php


namespace App\CustomClasses\UpsSmall;


use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;

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
        if ($serviceCode == "03") {
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


    public function compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $access, $isMultiShipment)
    {

        $shipments = $this->formateQuoteBeforeCompile($shipments);
        //print_r($shipments); exit;
        $this->quoteSettings = [];
        $isHazmat = $smalLtlHazmat['smallHazmat'] ?? false;
        $this->quoteSettings = $connectionSettings['ups-small']['quote_settings'] ?? '';

        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if (!isset($ship['q'])) {
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

            if (isset($quote['severity'])) {
                continue;
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
                    //  CHeck FOr Ups ground transit days
                    if ($data['serviceType'] == "03") {
                        if (isset($this->quoteSettings['number_of_transit_days']) && $this->quoteSettings['number_of_transit_days'] != null && isset($this->quoteSettings['ground_metric']) && $this->quoteSettings['ground_metric'] != null) {
                            $islimited = $this->checkGroundTransit($data, $this->quoteSettings);
                            if ($islimited) {
                                continue;
                            }
                        }
                    }
                    //  CHecks FOr Only quote ground service if hazardous
                    if ($isHazmat && isset($this->quoteSettings['ground_service_for_hazardous_material']) && $this->quoteSettings['ground_service_for_hazardous_material']) {
                        if ($data['serviceType'] != "03") {
                            continue;
                        }
                    }

                    // Adding Markup in services if enabled
                    $price = $this->getServiceRate($data, $data['serviceDesc'], $this->quoteSettings);
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

                    $title = $this->getServiceTitle($data['serviceDesc'], $data, $data['serviceType'], $this->quoteSettings, $residential);
                    $price = (float)str_replace(',', '', $price);
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['code'] = 'parcel_12ups' . $data['serviceType'] . $access;
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
                $originQuotesMulti[0]['code'] = 'Multiups' . $access;
                $originQuotesMulti[0]['rate'] = number_format($multiShipPrice, 2);
                $originQuotesMulti[0]['title'] = $residential ? 'Shipping' . Constant::RESI_LABEL : 'Shipping';
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
        foreach ($shipments as $quote) {
            if (isset($quote['ups_services'])) {
                $servicesDesc = $quote['ups_services'];
                break;
            }
        }
        foreach ($shipments as $shipment => $quotes) {
            $temp = [];
            if (!isset($quotes['q'])) {
                continue;
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

}
