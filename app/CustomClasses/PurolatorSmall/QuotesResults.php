<?php


namespace App\CustomClasses\PurolatorSmall;


use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;
use App\CustomClasses\Functions;
use Illuminate\Support\Str;
use App\Http\Controllers\ShippingRuleController;

class QuotesResults
{
    private $isSurchargeRates = false;
    public function __construct($suppressParcelRates = [])
    {
        $this->CompileQuotes = new CompileQuotes();
        $this->SuppressParcelRates = $suppressParcelRates;
    }


    public function getServiceRate($amount, $serviceDesc, $quoteSettings)
    {
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

    public function addHazmatAmountsInServices($amount, $serviceCode, $quoteSettings, $hazmatBoxes = 1)
    {
        $totalHazmatBoxes = Functions::getHazmatItemBoxes($this->isSbsEnable, $quoteSettings, $this->items, $hazmatBoxes);
        // Adding hazmat fee to Ground Service
        $serviceDesc = preg_replace("([A-Z])", " $0", $serviceCode);
        $trim = ltrim($serviceDesc);
        if (strpos($trim, 'Ground') !== false || Str::contains($trim, 'Ground')) {
            if (isset($quoteSettings['ground_hazardous_material_fee']) && is_numeric($quoteSettings['ground_hazardous_material_fee']) && !empty($quoteSettings['ground_hazardous_material_fee'])) {
                $amount = $amount + $quoteSettings['ground_hazardous_material_fee'] * $totalHazmatBoxes;
            }
            // Adding hazmat fee to Air Services
        } else {
            if (isset($quoteSettings['air_hazardous_material_fee']) && is_numeric($quoteSettings['air_hazardous_material_fee']) && !empty($quoteSettings['air_hazardous_material_fee'])) {
                $amount = $amount + $quoteSettings['air_hazardous_material_fee'] * $totalHazmatBoxes;
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

    public function getServiceTitle($title, $data, $serviceCode, $quoteSettings, $isResi = false, $showRadNotation = false)
    {
        $title = $this->getServiceLabel($title, $serviceCode, $quoteSettings);

        if ($isResi && $showRadNotation) {
            $title = $title . Constant::RESI_LABEL;
        }
        if (isset($data['totalTransitTimeInDays']) && $data['totalTransitTimeInDays'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 2) {
            $title = $title . ' (Intransit days: ' . $data['totalTransitTimeInDays'] . ')';
        } else if (isset($data['deliveryTimestamp']) && $data['deliveryTimestamp'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 3) {
            $title = $title . ' (Delivery by ' . date('m-d-Y', strtotime($data['deliveryTimestamp'])) . ')';
        }
        return $title;
    }

    public function getServiceLabel($title, $serviceType, $quoteSettings)
    {
        $services = $quoteSettings['carrier_services'] ?? [];
        foreach($services as $service => $checked){
            $serviceLetter =str_replace('_',' ',$service);
            $serviceLetter =str_replace('us','U.S.',$serviceLetter);
            $capitalServiceLetter = ucwords($serviceLetter);
            $serviceType =str_replace('  ',':',$capitalServiceLetter);
            $serviceType =str_replace(' ','',$serviceType);
            $serviceType =str_replace('1030','10:30',$serviceType);
            $serviceType =str_replace('am','AM',$serviceType);
            $serviceType =str_replace('Am','AM',$serviceType);

            if($serviceType == $title && $service == $checked){
                $labelIndex =  strtolower($service) . '_label';
                $labelIndex =str_replace('am','AM',$labelIndex);
                $labelIndex =str_replace('_AM_','_am_',$labelIndex);
                return !empty($quoteSettings['carrier_services'][$labelIndex]) ? $quoteSettings['carrier_services'][$labelIndex] : $title;
            }
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


    public function compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $alwaysResi, $isSbsEnable, $isMultiShipment, $items, $storeId = '', $carrierName = '', $totalHazmatBoxes)
    {
        $shippingRule = new ShippingRuleController();
        $shipments = $this->formateQuoteBeforeCompile($shipments,$connectionSettings);
        $this->quoteSettings = [];
        $isHazmat = $smalLtlHazmat['smallHazmat'] ?? false;
        $this->quoteSettings = $connectionSettings['purolator-small']['quote_settings'] ?? '';
        $this->isSbsEnable = $isSbsEnable;
        $this->items = $items;
        $access = $this->CompileQuotes->getAccessorialCodeSmall($residential || $alwaysResi);

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

        $rad_settings = Functions::getRADsettings($storeId) ?? [];
        $isRadNotation = isset($rad_settings['suppress_rad_notation']) && $rad_settings['suppress_rad_notation'];

        foreach ($shipments as $origin => $quote) {

            if(in_array($origin, $this->SuppressParcelRates)){
                continue;
            }
            
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
                    // Adding Product and Origin Markup in services if added
                    $productOriginMarkupFee = Functions::calProductOriginMarkupFee($data['totalNetCharge']['Amount'], $origin, $items, $allOrigins);
                    $data['totalNetCharge']['Amount'] = $data['totalNetCharge']['Amount'] + $productOriginMarkupFee;

                    // Adding Markup in services if enabled
                    $quoteSettings = $this->quoteSettings;
                    $data['totalNetCharge']['Amount'] = $this->addHandlingMarkupOfHazmat($data['totalNetCharge']['Amount'], $quoteSettings['handling_fee_markup'] ?? 0);

                    $overrideRates = $shippingRule->overrideRates($storeId, $items, $connectionSettings, $data, $carrierName);
                    $data = isset($overrideRates['data']) ? $overrideRates['data'] : $data;
                    // Apply Surcharge rates shipping rule
                    $surchargeRates = $shippingRule->surchargeRates($storeId, $items, $connectionSettings, $data, $carrierName, $origin, $allOrigins);
                    $isSurchargeRates = isset($surchargeRates['isSurchargeRates']) && $surchargeRates['isSurchargeRates'];
                    $data = isset($surchargeRates['data']) ? $surchargeRates['data'] : $data;
                    $price = $data['totalNetCharge']['Amount'];
                    // check: is override rule is applied, if yes then skip to add other features fee
                    if(isset($overrideRates['isOverrideRates']) && $overrideRates['isOverrideRates']){
                        $access2 = '';
                        $showRadNotation = false;
                    } else {
                        $access2 = $access;
                        $showRadNotation = $isRadNotation;
                        // Checking hazmat and adding hazmat amounts in services
                        if ($isHazmat) {
                            $hazmatBoxes = isset($totalHazmatBoxes['totalHazmatBoxes'][$origin]) ? $totalHazmatBoxes['totalHazmatBoxes'][$origin]['normal'] : 1;
                            if ($isMultiShipment) {
                                if ($hazmatAllItems[$origin] == 'Y') {
                                    $price = $this->addHazmatAmountsInServices($price, $data['serviceType'], $this->quoteSettings, $hazmatBoxes);
                                }
                            } else {
                                $price = $this->addHazmatAmountsInServices($price, $data['serviceType'], $this->quoteSettings, $hazmatBoxes);
                            }
                        }

                        $price = $this->getServiceRate($price, $data['serviceType'], $this->quoteSettings);
                    }

                    $access2 = $isSurchargeRates ? $access2 . '+SC' : $access2;

                    $title = $this->getServiceTitle($data['serviceType'], $data, $data['serviceType'], $this->quoteSettings, $residential, $showRadNotation);
                    $price = (float)str_replace(',', '', $price);
                    $originQuotes[$origin]['simple'][$key]['code'] = 'parcel_12' . $data['serviceType'] . $access2;
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

    private function onylQuoteGroundServices($isHazmat, $srvcType)
    {
        $grdServicesArr = ['PurolatorGround9AM', 'PurolatorGround10:30AM', 'PurolatorGround', 'PurolatorGroundU.S.'];
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
                        $serviceLetter =str_replace('us','U.S.',$serviceLetter);
                        $capitalServiceLetter = ucwords($serviceLetter);
                        $serviceType =str_replace('  ',':',$capitalServiceLetter);
                        $serviceType =str_replace(' ','',$serviceType);
                        $serviceType =str_replace('1030','10:30',$serviceType);
                        $serviceType =str_replace('am','AM',$serviceType);
                        $serviceType =str_replace('Am','AM',$serviceType);
                        if($serviceType == $value['serviceType'] && $service == $checked){
                            $checkedshipment[$shipkey]['q'][] = $value;
                            break;
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
