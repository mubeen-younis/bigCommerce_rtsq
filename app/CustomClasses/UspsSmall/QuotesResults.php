<?php

namespace App\CustomClasses\UspsSmall;

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
        $isHazmat = $smalLtlHazmat['smallHazmat'] ?? false;
        $this->quoteSettings = $connectionSettings['usps-small']['quote_settings'] ?? '';

        $numberOfShipments = 0;
        foreach ($shipments as $key => $ship) {
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
                return $this->CompileQuotes->getInsPicAndLocDelQuotes($quote, $allOrigins);;
            }

            if ($count == 0) {
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

                    //  Check for Usps ground transit days
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

                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['code'] = 'parcel_12usps' . $access;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['rate'] = $price;
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
                $multishipmentCheckoutQuotes[0]['code'] = 'Multiusps' . $access;
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
        }

        // Compiling instore pickup and local delivery quotes
        if (!$isMultiShipment && isset($inStoreLdData) && $inStoreLdData) {
            $allQuotes = $this->CompileQuotes->inStoreLocalDeliveryQuotes($originQuotes, $inStoreLdData, $allOrigins);
            $resp = $allQuotes;
        }

        $returnResp['resp'] = isset($resp) && !empty($resp) ? $resp : [];
        return $returnResp;
    }

    private function formateQuoteBeforeCompile($shipments): array
    {
        foreach ($shipments as $shipment => $quotes) {
            if (!isset($quotes['q'])) {
                continue;
            }

            foreach ($quotes['q'] as $quote => $value) {
                $shipments[$shipment]['q'][$quote]['serviceType'] = $value['serviceId'];
                $netCharges = $value['totalNetCharge'];
                unset($shipments[$shipment]['q'][$quote]['totalNetCharge']);
                $shipments[$shipment]['q'][$quote]['totalNetCharge']['Amount'] = $netCharges;
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

    private function getServiceIndexFromServiceType($srvcType)
    {
        $indexesArr = ['First Class Mail' => 'usps_first_class_mail', 'Priority Mail' => 'usps_priority_mail', 'Priority Mail Express' => 'usps_priority_mail_express', 'Priority Mail Flat Rate' => 'usps_priority_mail_flat_rate', 'Retail Ground' => 'usps_retail_ground', 'Priority Mail International' => 'usps_priority_mail_international', 'Priority Mail International Express' => 'usps_priority_mail_international_express', 'Priority Mail International Flat Rate Box' => 'usps_priority_mail_international_flat_rate_box', 'First-Class Package International Service' => 'usps_first_class_package_international_service'];

        return $indexesArr[$srvcType] ?? '';
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

    public function addHazmatAmountsInServices($amount, $serviceCode)
    {
        $quoteSettings = $this->quoteSettings;
        // Adding hazmat fee to Ground Service
        if ($serviceCode == 'Retail Ground') {
            $grdHazMatFee = $quoteSettings['ground_hazardous_material_fee'] ?? null;
            if (isset($grdHazMatFee) && is_numeric($grdHazMatFee) && !empty($grdHazMatFee)) {
                $amount = $amount + $grdHazMatFee;
            }
        } // Adding hazmat fee to Air Services
        else {
            $airHazMatFee = $quoteSettings['air_hazardous_material_fee'] ?? null;
            if (isset($airHazMatFee) && is_numeric($airHazMatFee) && !empty($airHazMatFee)) {
                $amount = $amount + $airHazMatFee;
            }
        }

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

    public function getServiceTitle($data, $title, $quoteSettings, $isResi = false): string
    {
        $title = $isResi ? $title . Constant::RESI_LABEL : $title;

        if (isset($data['totalTransitTimeInDays']) && $data['totalTransitTimeInDays'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 2) {
            $title = $title . ' (Estimated number of days until delivery is ' . $data['totalTransitTimeInDays'] . ')';
        } else if (isset($data['transitDate']) && $data['transitDate'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 3) {
            $title = $title . ' (Estimated delivery date is ' . date('m-d-Y', strtotime($data['transitDate'])) . ')';
        }

        return $title;
    }

    public function checkGroundTransit($quote, $srvcType): bool
    {
        $islimited = false;
        $transitDays = $quote['transitDays'] ?? '';
        if (isset($transitDays) && !empty($transitDays)) {
            $transitDays = explode('-', $transitDays)[0];
        }

        if ($srvcType == "Retail Ground") {
            if (isset($this->quoteSettings['number_of_transit_days']) && $this->quoteSettings['number_of_transit_days'] != null && isset($this->quoteSettings['ground_metric']) && $this->quoteSettings['ground_metric'] != null) {
                // Check limited to carrier transit days
                if ($this->quoteSettings['ground_metric'] == 1) {
                    if (isset($transitDays) && $transitDays > $this->quoteSettings['number_of_transit_days']) {
                        $islimited = true;
                    }
                }
                // Check by calendar days
                else {
                    if (isset($quote['CalenderDaysInTransit']) && $quote['CalenderDaysInTransit'] > $this->quoteSettings['number_of_transit_days']) {
                        $islimited = true;
                    }
                }
            }
        }

        return $islimited;
    }

    private function onylQuoteGroundServices($isHazmat, $srvcType): bool
    {
        $grdSrvcForHazMat = $this->quoteSettings['ground_service_for_hazardous_material'] ?? false;
        $grdSrvc = 'Retail Ground';
        if ($isHazmat && isset($grdSrvcForHazMat) && $grdSrvcForHazMat && $srvcType !== $grdSrvc) {
            return true;
        }

        return false;
    }

    public function getUspsActiveServices($carrierServices): array
    {
        $domesticServices = [
            'usps_first_class_mail' => 'First Class Mail',
            'usps_priority_mail_express' => 'Priority Mail Express',
            'usps_priority_mail' => 'Priority Mail',
            'usps_priority_mail_flat_rate' => 'Priority Mail Flat Rate',
            'usps_retail_ground' => 'Retail Ground',
        ];
        $internationalServices = [
            'usps_priority_mail_international_express' => 'Priority Mail International Express',
            'usps_priority_mail_international' => 'Priority Mail International',
            'usps_priority_mail_international_flat_rate_box' => 'Priority Mail International Flat Rate Box',
            'usps_first_class_package_international_service' => 'First-Class Package International Service',
        ];
        $activeServices = [
            'domestic' => [],
            'international' => [],
        ];

        foreach ($domesticServices as $service => $value) {
            if (isset($carrierServices[$service]) && $carrierServices[$service]) {
                $activeServices['domestic'][] = $value;
            }
        }

        foreach ($internationalServices as $service => $value) {
            if (isset($carrierServices[$service]) && $carrierServices[$service]) {
                $activeServices['international'][] = $value;
            }
        }

        return $activeServices;
    }
}
