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

    public function getServiceRate($data)
    {
        $amount = $data['totalNetCharge']['Amount'];
        // $markupIndex = strtolower(str_replace(' ', '_', $serviceDesc['serviceDesc']) . '_markup');
        $markupIndex = $this->getServiceIndexOrTitleFromServiceCode($data['serviceType'], false) . '_markup';
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

    private function getServiceIndexOrTitleFromServiceCode($srvcType, $title = false)
    {
        switch ($srvcType) {
            case 'ND':
                return $title ? 'UPS Next Day Air' : 'ups_next_day_air';
            case 'ND4':
                return $title ? 'UPS Next Day Air Saver' : 'ups_next_day_air_saver';
            case 'ND5':
                return $title ? 'UPS Next Day Air Early A.M.' : 'ups_next_day_air_early_am';
            case 'SC':
                return $title ? 'UPS 2nd Day Air' : 'ups_2nd_day_air';
            case 'SC25':
                return $title ? 'UPS 2nd Day Air A.M.' : 'ups_2nd_day_air_am';
            case 'SC3':
                return $title ? 'UPS 3 Day Select' : 'ups_3_day_select';
            case 'SG':
                return $title ? 'UPS Ground' : 'ups_ground';
            case 'SGR':
                return $title ? 'UPS Ground (Residential Delivery)' : 'ups_ground_residential_delivery';
            case 'SND':
                return $title ? 'Saturday - UPS Next Day Air' : 'ups_next_day_air_saturday';
            case 'SND5':
                return $title ? 'Saturday - UPS Next Day Air Early A.M.' : 'ups_next_day_air_early_am_saturday';
            case 'SSC':
                return $title ? 'Saturday - UPS 2nd Day Air' : 'ups_2nd_day_air_saturday';
            case 'ZZ1':
                return $title ? 'Worldwide Express' : 'ups_worldwide_express';
            case 'ZZ2':
                return $title ? 'Worldwide Expedited' : 'ups_worldwide_expedited';
            case 'ZZ90':
                return $title ? 'Worldwide Saver' : 'ups_worldwide_saver';
            case 'ZZ11':
                return $title ? 'Standard (Canada)' : 'ups_standard';
            default:
                break;
        }
    }

    public function addHazmatAmountsInServices($amount, $serviceCode, $quoteSettings)
    {
        // Adding hazmat fee to Ground Service
        if ($serviceCode == "SG" || $serviceCode == "SGR") {
            if (isset($quoteSettings['ground_hazardous_material_fee']) && is_numeric($quoteSettings['ground_hazardous_material_fee']) && !empty($quoteSettings['ground_hazardous_material_fee'])) {
                $amount = $amount + $quoteSettings['ground_hazardous_material_fee'];
            }
        }
        // Adding hazmat fee to Air Services
        else {
            if (isset($quoteSettings['air_hazardous_material_fee']) && is_numeric($quoteSettings['air_hazardous_material_fee']) && !empty($quoteSettings['air_hazardous_material_fee'])) {
                $amount = $amount + $quoteSettings['air_hazardous_material_fee'];
            }
        }
        // $amount = $this->addHandlingMarkupOfHazmat($amount, $quoteSettings['handling_fee_markup']);
        return number_format($amount, 2);

    }

    public function addHandlingMarkupOfHazmat($amount, $markupValue)
    {
        $amount = (float) str_replace(',', '', $amount);
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
        $title = $this->getServiceIndexOrTitleFromServiceCode($serviceCode, true);
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

        // // Check limited to carrier transit days
        // if ($quoteSettings['ground_metric'] == 1) {
        //     //  2 > 3
        //     if (isset($quote['TransitTimeInDays']) && isset($quoteSettings['number_of_transit_days']) && $quote['TransitTimeInDays'] > $quoteSettings['number_of_transit_days']) {
        //         return true;
        //     }
        // }
        // // Check by calendar days
        // else {
        //     if (isset($quote['CalenderDaysInTransit']) && isset($quoteSettings['number_of_transit_days']) && $quote['CalenderDaysInTransit'] > $quoteSettings['number_of_transit_days']) {
        //         return true;
        //     }
        // }
        return $islimited;
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

            if ($count == 0) { //To be checked only once
                // $this->getAutoResidentialTitle('');
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
            }

            if (isset($quote['q'])) {
                foreach ($quote['q'] as $key => $data) {
                    if (isset($data['severity'])) {
                        continue;
                    }

                    $srvcType = $data['serviceType'];
                    if (!$this->isActiveService($srvcType)) {
                        continue;
                    }

                    //  Check for Unishippers ground transit days
                    $skipService = $this->checkGroundTransit($data, $srvcType);
                    if ($skipService) {
                        continue;
                    }

                    // if ($srvcType == "SG" || $srvcType == "SGR") {
                    //     if (isset($this->quoteSettings['number_of_transit_days']) && $this->quoteSettings['number_of_transit_days'] != null && isset($this->quoteSettings['ground_metric']) && $this->quoteSettings['ground_metric'] != null) {
                    //         $islimited = $this->checkGroundTransit($data, $this->quoteSettings);
                    //         if ($islimited) {
                    //             continue;
                    //         }
                    //     }
                    // }

                    $isHazmat = true;
                    //  Checks for only quote ground service if hazardous
                    if ($isHazmat && isset($this->quoteSettings['ground_service_for_hazardous_material']) && $this->quoteSettings['ground_service_for_hazardous_material']) {
                        dd(232, $srvcType);
                        if ($srvcType != 'SG' || $srvcType != 'SGR') {
                            continue;
                        }
                    }

                    // Adding Markup in services if enabled
                    $price = $this->getServiceRate($data, $data['serviceDesc']);
                    $quoteSettings = $this->quoteSettings;
                    $price = $this->addHandlingMarkupOfHazmat($price, $quoteSettings['handling_fee_markup'] ?? 0);

                    // Checking hazmat and adding hazmat amounts in services
                    if ($isHazmat) {
                        if ($isMultiShipment) {
                            if ($hazmatAllItems[$origin] == 'Y') {
                                $price = $this->addHazmatAmountsInServices($price, $srvcType, $this->quoteSettings);
                            }
                        } else {
                            $price = $this->addHazmatAmountsInServices($price, $srvcType, $this->quoteSettings);
                        }
                    }

                    $title = $this->getServiceTitle($data, $srvcType, $this->quoteSettings, $residential);
                    $price = (float) str_replace(',', '', $price);

                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['code'] = 'parcel_12unishippers' . $srvcType . $access;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['rate'] = $price;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['title'] = $title;

                    $multiShipmentQuotes[$origin][$key] = $originQuotes[$shipmentCount]['shipment'][$key]['simple'];
                }
            }
            $shipmentCount++;
        }

        //$multiShipmentQuotes = $this->sortByOrder($multiShipmentQuotes, 'rate');
        //print_r($originQuotes);  exit;

        // Check for multi-shipment, finding lowest price in each shipment and adding them for multi shipment
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

        // Handling single shipment
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
            'isMultiShipment' => $isMultiShipment,
        ];

        return $resp;
    }

    private function isActiveService($srvcType): bool
    {
        $serviceIndex = $this->getServiceIndexOrTitleFromServiceCode($srvcType, false) ?? null;
        if ($serviceIndex && isset($this->quoteSettings['carrier_services'][$serviceIndex]) && $this->quoteSettings['carrier_services'][$serviceIndex] == true) {
            return true;
        }

        return false;
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
