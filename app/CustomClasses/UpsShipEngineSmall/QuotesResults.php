<?php


namespace App\CustomClasses\UpsShipEngineSmall;


use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;
use App\CustomClasses\Functions;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\ShippingRuleController;

class QuotesResults
{
    private $isSurchargeRates = false;
    public function __construct($suppressParcelRates = [])
    {
        $this->CompileQuotes = new CompileQuotes();
        $this->SuppressParcelRates = $suppressParcelRates;
    }


    /**
     * @param $data
     * @param $serviceCode
     * @param $quoteSettings
     * @return mixed|string
     */
    public function getServiceRate($amount, $serviceCode, $quoteSettings)
    {
        $markupIndex = strtolower(str_replace(' ', '_', $serviceCode) . '_markup');
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

    /**
     * Adds hazmat fee to services
     * @param $amount
     * @param $serviceCode
     * @param $quoteSettings
     * @param $groundServiceCodes
     * @return string
     */
    public function addHazmatAmountsInServices($amount, $serviceCode, $quoteSettings, $groundServiceCodes, $hazmatBoxes = 1)
    {
        $totalHazmatBoxes = Functions::getHazmatItemBoxes($this->isSbsEnable, $quoteSettings, $this->items, $hazmatBoxes);
        // Adding hazmat fee to Ground Service
        if (in_array($serviceCode, $groundServiceCodes)) {
            if (isset($quoteSettings['ground_hazardous_material_fee']) && is_numeric($quoteSettings['ground_hazardous_material_fee']) && !empty($quoteSettings['ground_hazardous_material_fee'])) {
                $amount = $amount + $quoteSettings['ground_hazardous_material_fee'] * $totalHazmatBoxes;
            }
            // Adding hazmat fee to Air Services
        } else {
            if (isset($quoteSettings['air_hazardous_material_fee']) && is_numeric($quoteSettings['air_hazardous_material_fee']) && !empty($quoteSettings['air_hazardous_material_fee'])) {
                $amount = $amount + $quoteSettings['air_hazardous_material_fee'] * $totalHazmatBoxes;
            }
        }
        return number_format($amount, 2);

    }

    /**
     * @param $amount
     * @param $markupValue
     * @return float|int
     */
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

    /**
     * @param $amount
     * @param $markupPercentage
     * @return float|int
     */
    public function getvalueFromPercent($amount, $markupPercentage)
    {
        $markupValue = $markupPercentage / 100 * $amount;
        $amountWithMarkup = $amount + $markupValue;
        return $amountWithMarkup;
    }

    /**
     * Returns service title
     * @param $title
     * @param $data
     * @param $quoteSettings
     * @param $isResi
     * @return mixed|string
     */
    public function getServiceTitle($title, $data, $quoteSettings, $isResi = false, $showRadNotation = false)
    {
        $title = $this->getServiceLabel($title, $data['service_code'], $quoteSettings);

        if ($isResi && $showRadNotation) {
            $title = $title . Constant::RESI_LABEL;
        }

        try {
            if (
                isset($data['totalTransitTimeInDays']) && $data['totalTransitTimeInDays'] !== '' &&
                isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 2
            ) {
                $title = $title . ' (Intransit days: ' . $data['totalTransitTimeInDays'] . ')';
            } else if (
                isset($data['estimated_delivery_date']) && $data['estimated_delivery_date'] !== '' &&
                isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 3
            ) {
                $title = $title . ' (Delivery by ' . date('h:i A m-d-Y', strtotime($data['estimated_delivery_date'])) . ')';
            }
            return $title;
        } catch (\Exception $exception) {
            return $title;
        }

    }

    public function getServiceLabel($title, $serviceType, $quoteSettings)
    {
        $title = $serviceType == 'ups_standard_international' ? 'UPS Standard International®' : $title;
        $labelIndex =  strtolower($serviceType) . '_label';
        return !empty($quoteSettings['carrier_services'][$labelIndex]) ? $quoteSettings['carrier_services'][$labelIndex] : $title;
    }

    /**
     * @param $quote
     * @param $quoteSettings
     * @return bool
     */
    public function checkGroundTransit($quote, $quoteSettings)
    {
        // Check limited to carrier transit days
        if ($quoteSettings['ground_metric'] == 1) {
            //  2>3
            if (isset($quote['delivery_days']) && isset($quoteSettings['number_of_transit_days']) && $quote['delivery_days'] > $quoteSettings['number_of_transit_days']) {
                return true;
            }
            // Check by calendar days
        } else {
            if (isset($quote['totalTransitTimeInDays']) && isset($quoteSettings['number_of_transit_days']) && $quote['totalTransitTimeInDays'] > $quoteSettings['number_of_transit_days']) {
                return true;
            }
        }
        return false;
    }

    /*
     * Returns compiled quotes of shipengine
     * */
    public function compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $isSbsEnable, $isMultiShipment, $items, $storeId = '', $carrierName = '', $totalHazmatBoxes)
    {
        $shippingRule = new ShippingRuleController();
        $shipments = $this->formateQuoteBeforeCompile($shipments);
        $isHazmat = $smalLtlHazmat['smallHazmat'] ?? false;
        $this->quoteSettings = $connectionSettings['ups-ship-engine']['quote_settings'] ?? '';
        $this->isSbsEnable = $isSbsEnable;
        $this->items = $items;
        $access = $this->CompileQuotes->getAccessorialCodeSmall();

        if (!$isMultiShipment) {
            $isMultiShipment = is_countable($shipments) && count($shipments) > 1;
        }

        $returnResp = [
            'isMultiShipment' => $isMultiShipment
        ];

        $originQuotes = $multiShipmentQuotes = $multiShipmentQuote = [];
        $shipmentCount = 0;
        $count = 0;
        $groundServiceCodes = ["ups_ground", "ups_3_day_select", "ups_standard", "ups_standard_international"];
        $carrierCode = "shipEng";

        $rad_settings = Functions::getRADsettings($storeId) ?? [];
        $isRadNotation = isset($rad_settings['suppress_rad_notation']) && $rad_settings['suppress_rad_notation'];

        foreach ($shipments as $origin => $quote) {

            if(in_array($origin, $this->SuppressParcelRates)){
                continue;
            }
            if ((isset($quote['severity']) || (isset($quote['q']) && empty($quote['q'])) || (!isset($quote['q']) && !empty($quote['InstorPickupLocalDelivery'])))) {
                $allQuotes = $this->CompileQuotes->getInsPicAndLocDelQuotes($quote, $allOrigins);
                $returnResp['resp'] = $allQuotes;
                return $returnResp;
            }

            if ($count == 0) { //To be checked only once
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
            }

            if (isset($quote['q'])) {
                foreach ($quote['q'] as $key => $data) {

                    // Check if service type is checked to show
                    if (isset($data['severity'])) {
                        continue;
                    }
                    $access2 = $access;
                    $serviceCode = $data['service_code'] ?? "";
                    //$serviceName = $this->getServiceNameByCode($serviceCode);
                    $isServiceEnabled = isset($this->quoteSettings['carrier_services'][$serviceCode]) &&
                    $this->quoteSettings['carrier_services'][$serviceCode] ? true : false;
                    if (!$isServiceEnabled) {
                        continue;
                    }

                    //  CHeck FOr Ups ground transit days
                    if (in_array($serviceCode, $groundServiceCodes)) {
                        if (
                            isset($this->quoteSettings['number_of_transit_days']) && $this->quoteSettings['number_of_transit_days'] != null &&
                            isset($this->quoteSettings['ground_metric']) && $this->quoteSettings['ground_metric'] != null
                        ) {
                            $islimited = $this->checkGroundTransit($data, $this->quoteSettings);
                            if ($islimited) {
                                continue;
                            }
                        }
                    }

                    //  CHecks FOr Only quote ground service if hazardous
                    if ($isHazmat && isset($this->quoteSettings['ground_service_for_hazardous_material']) && $this->quoteSettings['ground_service_for_hazardous_material']) {
                        if (!in_array($serviceCode, $groundServiceCodes)) {
                            continue;
                        }
                    }
                    // Apply override rates shipping rule
                    $overrideRates = $shippingRule->overrideRates($storeId, $items, $connectionSettings, $data, $carrierName, $origin, $allOrigins);
                    $data = isset($overrideRates['data']) ? $overrideRates['data'] : $data;
                    // Apply Surcharge rates shipping rule
                    $surchargeRates = $shippingRule->surchargeRates($storeId, $items, $connectionSettings, $data, $carrierName, $origin, $allOrigins);
                    $isSurchargeRates = isset($surchargeRates['isSurchargeRates']) && $surchargeRates['isSurchargeRates'];
                    $data = isset($surchargeRates['data']) ? $surchargeRates['data'] : $data;

                    // Adding Product and Origin Markup in services if added
                    $productOriginMarkupFee = Functions::calProductOriginMarkupFee($data['shipping_amount']['amount'], $origin, $items, $allOrigins);
                    $data['shipping_amount']['amount'] = $data['shipping_amount']['amount'] + $productOriginMarkupFee;

                    $quoteSettings = $this->quoteSettings;
                    $amount = $data['shipping_amount']['amount'];

                    $data['shipping_amount']['amount'] = $this->addHandlingMarkupOfHazmat($data['shipping_amount']['amount'], $quoteSettings['handling_fee_markup'] ?? 0);
                    $price = $data['shipping_amount']['amount'];
                    
                    // Checking hazmat and adding hazmat amounts in services
                    if ($isHazmat) {
                        $hazmatBoxes = isset($totalHazmatBoxes['totalHazmatBoxes'][$origin]) ? $totalHazmatBoxes['totalHazmatBoxes'][$origin]['normal'] : 1;
                        if ($isMultiShipment) {
                            if ($hazmatAllItems[$origin] == 'Y') {
                                $price = $this->addHazmatAmountsInServices($price, $serviceCode, $this->quoteSettings, $groundServiceCodes, $hazmatBoxes);
                            }
                        } else {
                            $price = $this->addHazmatAmountsInServices($price, $serviceCode, $this->quoteSettings, $groundServiceCodes, $hazmatBoxes);
                        }
                    }

                    $access2 = $isSurchargeRates ? $access2 . '+SC' : $access2;

                    $price = $this->getServiceRate($price, $serviceCode, $this->quoteSettings);

                    $title = $this->getServiceTitle($data['serviceDesc'], $data, $this->quoteSettings, $residential, $isRadNotation);
                    $price = (float)str_replace(',', '', $price);
                    $shortServiceCode = $this->getShortCodesOfService($serviceCode);
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['code'] = 'parcel_12' . $carrierCode . $shortServiceCode . $access2;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['rate'] = $price;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['title'] = $title;

                    $multiShipmentQuotes[$origin][$key] = $originQuotes[$shipmentCount]['shipment'][$key]['simple'];

                }
            }
            $shipmentCount++;
        }

        // Check for mukti shipment finding lowest price in each shipment and adding them for multi shipment
        if ($isMultiShipment && count($multiShipmentQuotes) > 1) {
            $originQuotesMulti = [];
            $multiShipPrice = 0;


            foreach ($originQuotes as $shipmentKey => $shipment) {
                $netChargeArray = array_column($shipment['shipment'], 'simple');
                $minValueFromNetChargeArr = min(array_column($netChargeArray, 'rate'));

                $multiShipPrice += str_replace(',', '', $minValueFromNetChargeArr);
                $originQuotesMulti[0]['code'] = 'Multi' . $carrierCode . $access2;
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


        // Doing For Single Shipment
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
            unset($returnResp['isMultiShipment']);
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


    /**
     * Format quotes before compilation of them
     * Adding service desc and calendardaysintransit index
     * @param $shipments
     * @return array
     */
    private function formateQuoteBeforeCompile($shipments)
    {
        try {
            foreach ($shipments as $shipment => $quotes) {
                $temp = [];
                if (!isset($quotes['q'])) {
                    continue;
                }

                foreach ($quotes['q'] as $key => $quote) {
                    if (!isset($quote['severity'])) {
                        if (!in_array($quote['shipping_amount']['amount'], $temp)) {
                            $temp[] = $quote['shipping_amount']['amount'] ?? 0;
                            $shipments[$shipment]['q'][$key]['serviceDesc'] = $this->getServiceNameByCode($quote['service_code']);
                            $shipments[$shipment]['q'][$key]['CalenderDaysInTransit'] = $shipments[$shipment]['q'][$key]['delivery_days'] ?? 1;
                        } else {
                            unset($shipments[$shipment]['q'][$key]);
                        }
                    } else {
                        unset($shipments[$shipment]['q'][$key]);
                    }
                }
            }

            return $shipments;
        } catch (\Exception $exception) {
            return [];
        }


    }


    /**
     * Returns ups shipengine service name by code
     * @param $code
     * @return string|null
     */
    public function getServiceNameByCode($code)
    {
        $upsShipEngineServices = [
            "ups_ground" => "UPS Ground®",
            "ups_2nd_day_air" => "UPS 2nd Day Air®",
            "ups_next_day_air_saver" => "UPS Next Day Air Saver®",
            "ups_next_day_air" => "UPS Next Day Air®",
            "ups_standard" => "UPS Standard®",
            "ups_next_day_air_early_am" => "UPS Next Day Air® Early",
            "ups_2nd_day_air_am" => "UPS 2nd Day Air AM®",
            "ups_3_day_select" => "UPS 3 Day Select®",
            "ups_worldwide_express" => "UPS Worldwide Express®",
            "ups_worldwide_expedited" => "UPS Worldwide Expedited®",
            "ups_worldwide_saver" => "UPS Worldwide Saver®",
            "ups_standard_international" => "UPS Standard®",
            "ups_ground_international" => "UPS Ground® (International)",
            "ups_worldwide_express_plus" => "UPS Worldwide Express Plus®",
        ];
        return $upsShipEngineServices[$code] ?? null;


    }


    public function getShortCodesOfService($code)
    {
        $upsShipEngineServices = [
            "ups_ground" => "03",
            "ups_2nd_day_air" => "02",
            "ups_next_day_air_saver" => "01",
            "ups_next_day_air" => "04",
            "ups_standard" => "05",
            "ups_next_day_air_early_am" => "06",
            "ups_2nd_day_air_am" => "07",
            "ups_3_day_select" => "08",
            "ups_worldwide_express" => "09",
            "ups_worldwide_expedited" => "10",
            "ups_worldwide_saver" => "11",
            "ups_standard_international" => "12",
            "ups_ground_international" => "13",
            "ups_worldwide_express_plus" => "14",
        ];
        return $upsShipEngineServices[$code] ?? "00";

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

    public function compileCompareQuotes($shipment, $connectionSettings)
    {
        $shipments = $this->formateQuoteBeforeCompile($shipment);
        $originQuotes = [];

        foreach ($shipments as $origin => $quote) {

            if ((isset($quote['severity']) || (isset($quote['q']) && empty($quote['q'])) || (!isset($quote['q'])))) {
                return $quote['Message'];
            }

            if (isset($quote['q'])) {
                foreach ($quote['q'] as $key => $data) {

                    // Check if service type is checked to show
                    if (isset($data['severity'])) {
                        continue;
                    }

                    $serviceCode = $data['service_code'] ?? "";

                    // Adding Markup in services if enabled
                    $price = $this->getServiceRate($data['shipping_amount']['amount'], $serviceCode, []);

                    $price = $this->addHandlingMarkupOfHazmat($price, 0);
                    $price = (float)str_replace(',', '', $price) ?? 0;

                    $title = $data['serviceDesc'] ?? '';
                    
                    $dateTime = $this->getEstimatedDateTime($data) ?? '';
                    $originQuotes[$key]['date'] = $dateTime;
                    $originQuotes[$key]['rate'] = $price;
                    $originQuotes[$key]['title'] = $title;
                    $sortedArray[$key] = $price;
                }
            }
        }
        array_multisort($sortedArray, SORT_ASC, $originQuotes);

        if (!empty($originQuotes)) {
            return $originQuotes;
        }

        return [];
    }

    public function getEstimatedDateTime($data)
    {
        $dateTime = '';
        try {
            if (isset($data['estimated_delivery_date']) && !empty($data['estimated_delivery_date'])){
                $date = date('l, F d, Y', strtotime($data['estimated_delivery_date']));
                $dateTime = 'Delivery By ' . $date;
            } else {
                $date = date('l, F d, Y', strtotime($data['ship_date']));
                $dateTime = 'Delivery By ' . $date;
            }

            return $dateTime;
        } catch (\Exception $exception) {
            return $dateTime;
        }

    }

}
