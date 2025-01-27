<?php

namespace App\CustomClasses\Unishippers\small;

use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;
use App\CustomClasses\Functions;
use App\Http\Controllers\ShippingRuleController;

class QuotesResults
{
    private $isSurchargeRates = false;
    public function __construct($suppressParcelRates = [])
    {
        $this->CompileQuotes = new CompileQuotes();
        $this->SuppressParcelRates = $suppressParcelRates;
    }

    public function compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $alwaysResi, $isSbsEnable, $isMultiShipment, $items, $storeId = '', $carrierName = '', $totalHazmatBoxes)
    {
        $shippingRule = new ShippingRuleController();
        $shipments = $this->formateQuoteBeforeCompile($shipments);
        $this->quoteSettings = [];
        $isHazmat = $smalLtlHazmat['smallHazmat'] ?? false;
        $this->quoteSettings = $connectionSettings['unishippers-small']['quote_settings'] ?? '';
        $this->isSbsEnable = $isSbsEnable;
        $this->items = $items;
        $access = $this->CompileQuotes->getAccessorialCodeSmall($residential || $alwaysResi);

        $numberOfShipments = 0;
        foreach ($shipments as $key => $ship) {
            if (!isset($ship['severity']) && !in_array($key, ['ground', 'air', 'simpleRate'])) {
                $numberOfShipments++;
            }
        }

        if (!$isMultiShipment) {
            $isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }

        $rad_settings = Functions::getRADsettings($storeId) ?? [];
        $isRadNotation = isset($rad_settings['suppress_rad_notation']) && $rad_settings['suppress_rad_notation'];

        $returnResp = [
            'isMultiShipment' => $isMultiShipment,
        ];
        $originQuotes = $multiShipmentQuotes = $multiShipmentQuote = [];
        $shipmentCount = 0;
        $count = 0;
        foreach ($shipments as $origin => $quote) {
            
            if(in_array($origin, $this->SuppressParcelRates)){
                continue;
            }

            if ((isset($quote['severity']) || (isset($quote['q']) && empty($quote['q'])) || (!isset($quote['q']) && !empty($quote['InstorPickupLocalDelivery'])))) {
                $instoreResp[$origin] = $this->CompileQuotes->getInsPicAndLocDelQuotes($quote, $allOrigins) ?? [];                
                return $instoreResp;
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
                    // Adding Product and Origin Markup in services if added
                    $productOriginMarkupFee = Functions::calProductOriginMarkupFee($data['totalNetCharge']['Amount'], $origin, $items, $allOrigins);
                    $data['totalNetCharge']['Amount'] = $data['totalNetCharge']['Amount'] + $productOriginMarkupFee;
                    
                    // Adding markup values if available
                    $data['totalNetCharge']['Amount'] = $this->addHandlingMarkupOfHazmat($data['totalNetCharge']['Amount']);

                    // Apply override rates shipping rule
                    $overrideRates = $shippingRule->overrideRates($storeId, $items, $connectionSettings, $data, $carrierName, $origin, $allOrigins);
                    $data = isset($overrideRates['data']) ? $overrideRates['data'] : $data; 
                    // Apply Surcharge rates shipping rule
                    $surchargeRates = $shippingRule->surchargeRates($storeId, $items, $connectionSettings, $data, $carrierName, $origin, $allOrigins);
                    $isSurchargeRates = isset($surchargeRates['isSurchargeRates']) && $surchargeRates['isSurchargeRates'];
                    $data = isset($surchargeRates['data']) ? $surchargeRates['data'] : $data;
                    $price = $data['totalNetCharge']['Amount'];
                    
                    $access2 = $access;
                    $showRadNotation = $isRadNotation;
                    // Checking hazmat and adding hazmat amounts in services
                    if ($isHazmat) {
                        $hazmatBoxes = isset($totalHazmatBoxes['totalHazmatBoxes'][$origin]) ? $totalHazmatBoxes['totalHazmatBoxes'][$origin]['normal'] : 1;
                        if ($isMultiShipment) {
                            if ($hazmatAllItems[$origin] == 'Y') {
                                $price = $this->addHazmatAmountsInServices($price, $srvcType, $hazmatBoxes);
                            }
                        } else {
                            $price = $this->addHazmatAmountsInServices($price, $srvcType, $hazmatBoxes);
                        }
                    }

                    $price = $this->getServiceRate($price, $srvcType);

                    $access2 = $isSurchargeRates ? $access2 . '+SC' : $access2;
                    // Get service title
                    $title = $this->getServiceTitle($data, $srvcType, $this->quoteSettings, $residential, $showRadNotation);
                    $price = (float) str_replace(',', '', $price);

                    $originQuotes[$origin]['simple'][$key]['code'] = 'parcel_12uniship' . $srvcType . $access2;
                    $originQuotes[$origin]['simple'][$key]['rate'] = $price;
                    $originQuotes[$origin]['simple'][$key]['title'] = $title;
                }
            }

            if (isset($inStoreLdData) && $inStoreLdData) {
                $originQuotes[$origin] = $this->CompileQuotes->inStoreLocalDeliveryQuotes($originQuotes[$origin], $inStoreLdData, $allOrigins);
            }
        }
        return $originQuotes;
    }

    public function compileQuotesNewApi($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $alwaysResi, $isSbsEnable, $isMultiShipment, $items, $storeId = '', $carrierName = '', $totalHazmatBoxes)
    {
        $shippingRule = new ShippingRuleController();
        $shipments = $this->formateQuoteBeforeCompileNewApi($shipments);
        $this->quoteSettings = [];
        $isHazmat = $smalLtlHazmat['smallHazmat'] ?? false;
        $this->quoteSettings = $connectionSettings['unishippers-small']['quote_settings'] ?? '';
        $this->isSbsEnable = $isSbsEnable;
        $this->items = $items;
        $access = $this->CompileQuotes->getAccessorialCodeSmall($residential || $alwaysResi);

        $numberOfShipments = 0;
        foreach ($shipments as $key => $ship) {
            if (!isset($ship['severity']) && !in_array($key, ['ground', 'air', 'simpleRate'])) {
                $numberOfShipments++;
            }
        }

        if (!$isMultiShipment) {
            $isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }

        $rad_settings = Functions::getRADsettings($storeId) ?? [];
        $isRadNotation = isset($rad_settings['suppress_rad_notation']) && $rad_settings['suppress_rad_notation'];

        $returnResp = [
            'isMultiShipment' => $isMultiShipment,
        ];
        $originQuotes = $multiShipmentQuotes = $multiShipmentQuote = [];
        $shipmentCount = 0;
        $count = 0;
        foreach ($shipments as $origin => $quote) {

            if(in_array($origin, $this->SuppressParcelRates)){
                continue;
            }
            
            if ((isset($quote['severity']) || (isset($quote['q']) && empty($quote['q'])) || (!isset($quote['q']) && !empty($quote['InstorPickupLocalDelivery'])))) {
                $instoreResp[$origin] = $this->CompileQuotes->getInsPicAndLocDelQuotes($quote, $allOrigins) ?? [];                
                return $instoreResp;
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
                    // Adding Product and Origin Markup in services if added
                    $productOriginMarkupFee = Functions::calProductOriginMarkupFee($data['totalNetCharge']['Amount'], $origin, $items, $allOrigins);
                    $data['totalNetCharge']['Amount'] = $data['totalNetCharge']['Amount'] + $productOriginMarkupFee;

                    // Adding markup values if available
                    $data['totalNetCharge']['Amount'] = $this->addHandlingMarkupOfHazmat($data['totalNetCharge']['Amount']);

                    
                    // Apply override rates shipping rule
                    $overrideRates = $shippingRule->overrideRates($storeId, $items, $connectionSettings, $data, $carrierName, $origin, $allOrigins);
                    $data = isset($overrideRates['data']) ? $overrideRates['data'] : $data; 
                    // Apply Surcharge rates shipping rule
                    $surchargeRates = $shippingRule->surchargeRates($storeId, $items, $connectionSettings, $data, $carrierName, $origin, $allOrigins);
                    $isSurchargeRates = isset($surchargeRates['isSurchargeRates']) && $surchargeRates['isSurchargeRates'];
                    $data = isset($surchargeRates['data']) ? $surchargeRates['data'] : $data;
                    $price = $data['totalNetCharge']['Amount'];
                    
                    $access2 = $access;
                    $showRadNotation = $isRadNotation;
                    // Checking hazmat and adding hazmat amounts in services
                    if ($isHazmat) {
                        $hazmatBoxes = isset($totalHazmatBoxes['totalHazmatBoxes'][$origin]) ? $totalHazmatBoxes['totalHazmatBoxes'][$origin]['normal'] : 1;
                        if ($isMultiShipment) {
                            if ($hazmatAllItems[$origin] == 'Y') {
                                $price = $this->addHazmatAmountsInServices($price, $srvcType, $hazmatBoxes);
                            }
                        } else {
                            $price = $this->addHazmatAmountsInServices($price, $srvcType, $hazmatBoxes);
                        }
                    }

                    $price = $this->getServiceRate($price, $srvcType);
                    $access2 = $isSurchargeRates ? $access2 . '+SC' : $access2;

                    // Get service title
                    $title = $this->getServiceTitle($data, $srvcType, $this->quoteSettings, $residential, $showRadNotation);
                    $price = (float) str_replace(',', '', $price);

                    $originQuotes[$origin]['simple'][$key]['code'] = 'parcel_12uniship_new' . $srvcType . $access2;
                    $originQuotes[$origin]['simple'][$key]['rate'] = $price;
                    $originQuotes[$origin]['simple'][$key]['title'] = $title;
                }
            }

            if (isset($inStoreLdData) && $inStoreLdData) {
                $originQuotes[$origin] = $this->CompileQuotes->inStoreLocalDeliveryQuotes($originQuotes[$origin], $inStoreLdData, $allOrigins);
            }
        }
        return $originQuotes;
    }

    private function formateQuoteBeforeCompileNewApi($shipments)
    {
        $servicesDesc = '';

        foreach ($shipments as $shipment => $quotes) {
            $temp = [];
            if (!isset($quotes['q'])) {
                continue;
            }

            foreach ($quotes['q'] as $key => $quote) {

                if (!isset($quote['severity'])) {
                    $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = $quote['totalOfferPrice']['value'] ?? 0;
                    if(isset($quote['timeInTransit'])){
                        $servicesDesc = $quote['timeInTransit']['serviceDescription'] ?? '';
                        $shipments[$shipment]['q'][$key]['serviceType'] = $quote['timeInTransit']['upsServiceCode'] ?? '';
                        $shipments[$shipment]['q'][$key]['deliveryDate'] = $quote['timeInTransit']['estimatedDeliveryDate'] ?? '';
                        $shipments[$shipment]['q'][$key]['serviceDesc']['CalenderDaysInTransit'] = $quote['timeInTransit']['CalenderDaysInTransit'] ?? null;
                        $shipments[$shipment]['q'][$key]['serviceDesc']['TransitTimeInDays'] = $quote['timeInTransit']['totalTransitTimeInDays'] ?? null;
                        $shipments[$shipment]['q'][$key]['totalTransitTimeInDays'] = $quote['timeInTransit']['totalTransitTimeInDays'] ?? null;
                    }
                    
                    if (isset($shipments[$shipment]['q'][$key]['timeInTransit']['CalenderDaysInTransit']) && $shipments[$shipment]['q'][$key]['timeInTransit']['CalenderDaysInTransit'] == '') {
                        if (isset($quotes['tnt']['TransitResponse']['ServiceSummary'])) {

                            $shipments[$shipment]['q'][$key]['CalenderDaysInTransit'] = $this->calenderDays($servicesDesc, $quotes['tnt']['TransitResponse']['ServiceSummary']);
                        }
                    }
                } else {
                    unset($shipments[$shipment]['q'][$key]);
                }
            }
        }
        return $shipments;
    }

    private function formateQuoteBeforeCompile($shipments)
    {
        $servicesDesc = [];

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

        if ($srvcType == "SG" || $srvcType == "SGR" || $srvcType == "GND" || $srvcType == "03" || $srvcType == "3DS" || $srvcType == "SC3" || $srvcType == "ZZ11") {
            if (isset($this->quoteSettings['number_of_transit_days']) && $this->quoteSettings['number_of_transit_days'] != null && isset($this->quoteSettings['ground_metric']) && $this->quoteSettings['ground_metric'] != null) {
                // Check limited to carrier transit days
                if ($this->quoteSettings['ground_metric'] == 1) {
                    if (isset($quote['serviceDesc']['TransitTimeInDays']) && $quote['serviceDesc']['TransitTimeInDays'] > $this->quoteSettings['number_of_transit_days']) {
                        $islimited = true;
                    }
                } // Check by calendar days
                else {
                    if (isset($quote['serviceDesc']['CalenderDaysInTransit']) && $quote['serviceDesc']['CalenderDaysInTransit'] > $this->quoteSettings['number_of_transit_days']) {
                        $islimited = true;
                    }
                }
            }
        }

        return $islimited;
    }

    public function getServiceRate($amount, $srvcType)
    {
        
        $markupIndex = $this->getServiceIndexFromServiceType($srvcType) . '_markup';
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
        $grdServicesArr = ['SG', 'SGR', 'GND', '03', '3DS', 'SC3', 'ZZ11'];
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
        $indexesArr = [
            /** Services Index for Unishipper */
            'ND' => 'ups_next_day_air',
            'ND4' => 'ups_next_day_air_saver',
            'ND5' => 'ups_next_day_air_early_am',
            'SC' => 'ups_2nd_day_air',
            'SC25' => 'ups_2nd_day_air_am',
            'SC3' => 'ups_3_day_select',
            'SG' => 'ups_ground',
            'SGR' => 'ups_ground_residential_delivery',
            'SND' => 'ups_next_day_air_saturday',
            'SND5' => 'ups_next_day_air_early_am_saturday',
            'SSC' => 'ups_2nd_day_air_saturday',
            'ZZ1' => 'ups_worldwide_express',
            'ZZ2' => 'ups_worldwide_expedited',
            'ZZ90' => 'ups_worldwide_saver',
            'ZZ11' => 'ups_standard',
            /** Services Index for Unishipper New API */
            'GND' => 'ups_ground',
            '3DS' => 'ups_3_day_select',
            '2DA' => 'ups_2nd_day_air',
            '2DM' => 'ups_2nd_day_air_am',
            '2DAS' => 'ups_2nd_day_air_saver',
            '1DA' => 'ups_next_day_air',
            '1DP' => 'ups_next_day_air_saver',
            '1DM' => 'ups_next_day_air_early',
            /** International Services Index for Unishipper New API */
            '01' => 'ups_worldwide_express',
            '05' => 'ups_worldwide_expedited',
            '28' => 'ups_worldwide_saver',
            '03' => 'ups_standard',
            '21' => 'ups_worldwide_express_plus',
        ];

        return $indexesArr[$srvcType] ?? '';
    }

    public function getServiceTitleFromServiceType($srvcType)
    {
        $titlesArr = [
            /** Services Name for Unishipper */
            'ND' => 'UPS Next Day Air', 
            'ND4' => 'UPS Next Day Air Saver', 
            'ND5' => 'UPS Next Day Air Early A.M.', 
            'SC' => 'UPS 2nd Day Air', 
            'SC25' => 'UPS 2nd Day Air A.M.', 
            'SC3' => 'UPS 3 Day Select', 
            'SG' => 'UPS Ground', 
            'SGR' => 'UPS Ground (Residential Delivery)', 
            'SND' => 'Saturday - UPS Next Day Air', 
            'SND5' => 'Saturday - UPS Next Day Air Early A.M.', 
            'SSC' => 'Saturday - UPS 2nd Day Air', 
            'ZZ1' => 'Worldwide Express', 
            'ZZ2' => 'Worldwide Expedited', 
            'ZZ90' => 'Worldwide Saver', 
            'ZZ11' => 'Standard (Canada)',
            /** Services Name for Unishipper New API */
            'GND' => 'UPS Ground',
            '3DS' => 'UPS 3 Day Select',
            '2DA' => 'UPS 2nd Day Air',
            '2DM' => 'UPS 2nd Day Air Early',
            '2DAS' => 'UPS 2nd Day Air Saver',
            '1DA' => 'UPS Next Day Air',
            '1DP' => 'UPS Next Day Air Saver',
            '1DM' => 'UPS Next Day Air Early',
            /** International Name for Unishipper New API */
            "01" => "UPS Worldwide Express",
            "03" => "UPS Standard",
            "05" => "UPS Worldwide Expedited",
            "21" => "UPS Worldwide Express Plus",
            "28" => "UPS Worldwide Saver",
        ];

        return $titlesArr[$srvcType] ?? '';
    }

    public function addHazmatAmountsInServices($amount, $serviceCode, $hazmatBoxes = 1)
    {
        $quoteSettings = $this->quoteSettings;
        $totalHazmatBoxes = Functions::getHazmatItemBoxes($this->isSbsEnable, $quoteSettings, $this->items, $hazmatBoxes);
        // Adding hazmat fee to Ground Service
        if ($serviceCode == "SG" || $serviceCode == "SGR" || $serviceCode == "GND" || $serviceCode == "03" || $serviceCode == "3DS" || $serviceCode == "SC3" || $serviceCode == "ZZ11") {
            $grdHazMatFee = $quoteSettings['ground_hazardous_material_fee'] * $totalHazmatBoxes ?? null;
            if (isset($grdHazMatFee) && is_numeric($grdHazMatFee) && !empty($grdHazMatFee)) {
                $amount = $amount + $grdHazMatFee;
            }
        } // Adding hazmat fee to Air Services
        else {
            $airHazMatFee = $quoteSettings['air_hazardous_material_fee'] * $totalHazmatBoxes ?? null;
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

    public function getServiceTitle($data, $serviceCode, $quoteSettings, $isResi = false, $showRadNotation = false)
    {
        $title = $this->getServiceTitleFromServiceType($serviceCode);

        $title = $this->getServiceLabel($title, $data['serviceType'], $quoteSettings);

        if ($isResi && $showRadNotation) {
            $title = $title . Constant::RESI_LABEL;
        }

        if (isset($data['totalTransitTimeInDays']) && $data['totalTransitTimeInDays'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 2) {
            $title = $title . ' (Intransit days: ' . $data['totalTransitTimeInDays'] . ')';
        } else if (isset($data['deliveryDate']) && $data['deliveryDate'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 3) {
            $title = $title . ' (Delivery by ' . date('m-d-Y', strtotime($data['deliveryDate'])) . ')';
        }

        return $title;
    }

    public function getServiceLabel($title, $serviceType, $quoteSettings)
    {
        $title = strpos($title, 'UPS') === false ? 'UPS ' . $title : $title;
        $title = strpos($title, 'Standard') === 0 ? 'UPS Standard': $title;
        $title = str_replace(' ', '_', $title);
        $title = str_replace('.', '', $title);
        $title = str_replace('(', '', $title);
        $title = str_replace(')', '', $title);
        $title = str_replace('_Canada', '', $title);
        $title = strpos($title, 'Saturday') === 0 ? $title . ' Saturday' : $title;
        $title = str_replace('Saturday - ', '', $title);
        $labelIndex =  strtolower($title) . '_label';
        return !empty($quoteSettings['carrier_services'][$labelIndex]) ? $quoteSettings['carrier_services'][$labelIndex] : str_replace('_', ' ', $title);
    }

    public function calenderDays($fDesc, $tnts)
    {
        $resp = '';
        foreach ($tnts as $key => $tnt) {
            $desc = $tnt['Service']['Description'] ?? '';
            if ($desc === $fDesc) {
                $resp = $tnt['EstimatedArrival']['BusinessDaysInTransit'] ?? null;
            }
        }

        return $resp;
    }
}
