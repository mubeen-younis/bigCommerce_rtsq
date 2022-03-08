<?php

namespace App\CustomClasses\Unishippers\small;

use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;

class QuotesResults
{
    public function __construct()
    {
        $this->CompileQuotes = new CompileQuotes();
    }

    public function compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $access, $isMultiShipment)
    {
        $shipments = $this->formateQuoteBeforeCompile($shipments);
        $this->quoteSettings = [];
        $isHazmat = $smalLtlHazmat['smallHazmat'] ?? false;
        $this->quoteSettings = $connectionSettings['unishippers-small']['quote_settings'] ?? '';

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
            'isMultiShipment' => $isMultiShipment,
        ];
        $originQuotes = $multiShipmentQuotes = $multiShipmentQuote = [];
        $shipmentCount = 0;
        $count = 0;

        foreach ($shipments as $origin => $quote) {
            if (isset($quote['severity'])) {
                continue;
            }

            if ($count == 0) {
                //To be checked only once
                // $this->getAutoResidentialTitle('');
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
            }

            if (isset($quote['q'])) {
                foreach ($quote['q'] as $key => $data) {
                    if (isset($data['severity'])) {
                        continue;
                    }
                    // Check for service availability
                    $srvcType = $data['serviceType'];
                    if (!$this->isActiveService($srvcType)) {
                        continue;
                    }

                    //  Check for Unishippers ground transit days
                    $skipService = $this->checkGroundTransit($data, $srvcType);
                    if ($skipService) {
                        continue;
                    }

                    //  Checks for only quote ground service if hazardous
                    if ($this->onylQuoteGroundServices($isHazmat, $srvcType)) {
                        continue;
                    }

                    // Getting markup values form quote settings
                    $price = $this->getServiceRate($data);
                    // Adding markup values if available
                    $price = $this->addHandlingMarkupOfHazmat($price);

                    // Checking hazmat and adding hazmat amounts in services
                    if ($isHazmat) {
                        if ($isMultiShipment) {
                            if ($hazmatAllItems[$origin] == 'Y') {
                                $price = $this->addHazmatAmountsInServices($price, $srvcType);
                            }
                        } else {
                            $price = $this->addHazmatAmountsInServices($price, $srvcType);
                        }
                    }

                    // Get service title
                    $title = $this->getServiceTitle($data, $srvcType, $this->quoteSettings, $residential);
                    $price = (float) str_replace(',', '', $price);

                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['code'] = 'parcel_12uniship' . $srvcType . $access;
                    $originQuotes[
                        $shipmentCount]['shipment'][$key]['simple']['rate'] = $price;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['title'] = $title;

                    $multiShipmentQuotes[$origin][$key] = $originQuotes[$shipmentCount]['shipment'][$key]['simple'];
                }
            }

            $shipmentCount++;
        }

        // Check for multi-shipment, finding lowest price in each shipment and adding them for multi shipment
        if ($isMultiShipment) {
            $multishipmentCheckoutQuotes = [];
            $multiShipmentPrice = 0;

            foreach ($originQuotes as $shipmentKey => $shipment) {
                $netChargeArr = array_column($shipment['shipment'], 'simple');
                $minRateFromNetChargeArr = min(array_column($netChargeArr, 'rate'));

                $multiShipmentPrice += str_replace(',', '', $minRateFromNetChargeArr);
                $multishipmentCheckoutQuotes[0]['code'] = 'Multiuniship' . $access;
                $multishipmentCheckoutQuotes[0]['rate'] = number_format($multiShipmentPrice, 2);
                $multishipmentCheckoutQuotes[0]['title'] = $residential ? 'Shipping' . Constant::RESI_LABEL : 'Shipping';
            }

            foreach ($multiShipmentQuotes as $shipmentKey => $shipment) {
                $keys = array_column($shipment, 'rate');
                array_multisort($keys, SORT_ASC, $shipment);
                $multiShipmentQuote['simple'][$shipmentKey] = array_values($shipment)[0];
            }

            $resp = [
                'checkoutQuotes' => $multishipmentCheckoutQuotes,
                'multiShipmentQuotes' => $multiShipmentQuote,
            ];
            $returnResp['resp'] = $resp;

            return $returnResp;
        }

        // Handling single shipment
        if (!empty($originQuotes)) {
            $originQuotes = array_column(array_values($originQuotes), 'shipment');
            $originQuotes = reset($originQuotes);
            $originQuotes = array_column(array_values($originQuotes), 'simple');
            $resp = $originQuotes;

            // Checkking for instore pickup
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
            'isMultiShipment' => $isMultiShipment,
        ];

        return $resp;
    }

    private function formateQuoteBeforeCompile($shipments)
    {
        $servicesDesc = [];
        foreach ($shipments as $quote) {
            if (isset($quote['q'])) {
                $servicesDesc = $quote['q'];
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

    private function isActiveService($srvcType): bool
    {
        $serviceIndex = $this->getServiceIndexFromServiceType($srvcType) ?? null;
        if ($serviceIndex && isset($this->quoteSettings['carrier_services'][$serviceIndex]) && $this->quoteSettings['carrier_services'][$serviceIndex] == true) {
            return true;
        }

        return false;
    }

    public function checkGroundTransit($quote, $srvcType)
    {
        $islimited = false;

        if ($srvcType == "SG" || $srvcType == "SGR") {
            if (isset($this->quoteSettings['number_of_transit_days']) && $this->quoteSettings['number_of_transit_days'] != null && isset($this->quoteSettings['ground_metric']) && $this->quoteSettings['ground_metric'] != null) {
                // Check limited to carrier transit days
                if ($this->quoteSettings['ground_metric'] == 1) {
                    //  2 > 3
                    if (isset($quote['TransitTimeInDays']) && isset($this->quoteSettings['number_of_transit_days']) && $quote['TransitTimeInDays'] > $this->quoteSettings['number_of_transit_days']) {
                        $islimited = true;
                    }
                }
                // Check by calendar days
                else {
                    if (isset($quote['CalenderDaysInTransit']) && isset($this->quoteSettings['number_of_transit_days']) && $quote['CalenderDaysInTransit'] > $this->quoteSettings['number_of_transit_days']) {
                        $islimited = true;
                    }
                }
            }
        }

        return $islimited;
    }

    public function getServiceRate($data)
    {
        $amount = $data['totalNetCharge']['Amount'];
        $markupIndex = $this->getServiceIndexFromServiceType($data['serviceType']) . '_markup';
        $markupValue = $this->quoteSettings['carrier_services'][$markupIndex] ?? '';

        if (empty($markupValue) || !is_numeric(str_replace('%', '', $markupValue))) {
            return $amount;
        }
        if (strpbrk($markupValue, '%') !== false) {
            $amount = $this->getvalueFromPercent($amount, str_replace('%', '', $markupValue));
        } else {
            $amount = $amount + $markupValue;
        }

        return number_format($amount, 2);
    }

    private function onylQuoteGroundServices($isHazmat, $srvcType)
    {
        $grdServicesArr = ['SG', 'SGR'];
        $grdSrvcForHazMat = $this->quoteSettings['ground_service_for_hazardous_material'] ?? false;

        if ($isHazmat && isset($grdSrvcForHazMat) && $grdSrvcForHazMat) {
            if (!in_array($srvcType, $grdServicesArr)) {
                return true;
            }
        }

        return false;
    }

    private function getServiceIndexFromServiceType($srvcType)
    {
        $indexesArr = ['ND' => 'ups_next_day_air', 'ND4' => 'ups_next_day_air_saver', 'ND5' => 'ups_next_day_air_early_am', 'SC' => 'ups_2nd_day_air', 'SC25' => 'ups_2nd_day_air_am', 'SC3' => 'ups_3_day_select', 'SG' => 'ups_ground', 'SGR' => 'ups_ground_residential_delivery', 'SND' => 'ups_next_day_air_saturday', 'SND5' => 'ups_next_day_air_early_am_saturday', 'SSC' => 'ups_2nd_day_air_saturday', 'ZZ1' => 'ups_worldwide_express', 'ZZ2' => 'ups_worldwide_expedited', 'ZZ90' => 'ups_worldwide_saver', 'ZZ11' => 'ups_standard'];

        return $indexesArr[$srvcType] ?? '';
    }

    private function getServiceTitleFromServiceType($srvcType)
    {
        $titlesArr = ['ND' => 'UPS Next Day Air', 'ND4' => 'UPS Next Day Air Saver', 'ND5' => 'UPS Next Day Air Early A.M.', 'SC' => 'UPS 2nd Day Air', 'SC25' => 'UPS 2nd Day Air A.M.', 'SC3' => 'UPS 3 Day Select', 'SG' => 'UPS Ground', 'SGR' => 'UPS Ground (Residential Delivery)', 'SND' => 'Saturday - UPS Next Day Air', 'SND5' => 'Saturday - UPS Next Day Air Early A.M.', 'SSC' => 'Saturday - UPS 2nd Day Air', 'ZZ1' => 'Worldwide Express', 'ZZ2' => 'Worldwide Expedited', 'ZZ90' => 'Worldwide Saver', 'ZZ11' => 'Standard (Canada)'];

        return $titlesArr[$srvcType] ?? '';
    }

    public function addHazmatAmountsInServices($amount, $serviceCode)
    {
        $quoteSettings = $this->quoteSettings;
        // Adding hazmat fee to Ground Service
        if ($serviceCode == "SG" || $serviceCode == "SGR") {
            $grdHazMatFee = $quoteSettings['ground_hazardous_material_fee'] ?? null;
            if (isset($grdHazMatFee) && is_numeric($grdHazMatFee) && !empty($grdHazMatFee)) {
                $amount = $amount + $grdHazMatFee;
            }
        }
        // Adding hazmat fee to Air Services
        else {
            $airHazMatFee = $quoteSettings['air_hazardous_material_fee'] ?? null;
            if (isset($airHazMatFee) && is_numeric($airHazMatFee) && !empty($airHazMatFee)) {
                $amount = $amount + $airHazMatFee;
            }
        }
        // $amount = $this->addHandlingMarkupOfHazmat($amount, $quoteSettings['handling_fee_markup']);
        return number_format($amount, 2);

    }

    public function addHandlingMarkupOfHazmat($amount)
    {
        $amount = (float) str_replace(',', '', $amount);
        $markupValue = $this->quoteSettings['handling_fee_markup'] ?? 0;

        if (strpbrk($markupValue, '%') !== false) {
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

    public function getServiceTitle($data, $serviceCode, $quoteSettings, $isResi = false)
    {
        $title = $this->getServiceTitleFromServiceType($serviceCode);
        if ($isResi) {
            $title = $title . Constant::RESI_LABEL;
        }

        if (isset($data['totalTransitTimeInDays']) && $data['totalTransitTimeInDays'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 2) {
            $title = $title . ' (Estimated number of days until delivery is ' . $data['totalTransitTimeInDays'] . ')';
        } else if (isset($data['deliveryDate']) && $data['deliveryDate'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 3) {
            $title = $title . ' (Estimated delivery date is ' . date('m-d-Y', strtotime($data['deliveryDate'])) . ')';
        }

        return $title;
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
