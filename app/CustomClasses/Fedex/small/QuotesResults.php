<?php


namespace App\CustomClasses\Fedex\small;


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
        $amount = $data;//$data['totalNetCharge']['Amount'];
        //print_r($quoteSettings); exit; dd($quoteSettings['rate_source']);
        if( isset($quoteSettings['rate_source']) && $quoteSettings['rate_source'] === 1 ){
            $boxFee = $data['boxFees']['Amount'] ?? 0;
            $amount = $data['NegotiatedRates']['Amount'] > 0 ? $data['NegotiatedRates']['Amount']+$boxFee : $amount;
        }
        $markupIndex = strtolower(str_replace(' ','_',$serviceDesc).'_markup');
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
            if ( isset($quoteSettings['ground_hazardous_material_fee']) && is_numeric($quoteSettings['ground_hazardous_material_fee']) && !empty($quoteSettings['ground_hazardous_material_fee'])) {
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
        $amount = (float) str_replace(',', '', $amount);
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
        if ( isset($data['totalTransitTimeInDays']) && $data['totalTransitTimeInDays'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 2) {
            $title = $title . ' (Estimated number of days until delivery is '.$data['totalTransitTimeInDays'].')';
        }else if( isset($data['deliveryTimestamp']) && $data['deliveryTimestamp'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 3){
            $title = $title . ' (Estimated delivery date is '.date ('m-d-Y', strtotime($data['deliveryTimestamp'])).')';
        }
        $resiTitle = '';
        if($isResi){
            $resiTitle = " ( R ) ";
        }
        return $title . $resiTitle;
    }

    public function checkGroundTransit($quote, $quoteSettings)
    {
        // Check limited to carrier transit days
        if ($quoteSettings['ground_metric'] == 1) {
            //  2>3
            if (isset($quote['totalTransitTimeInDays']) && isset($quoteSettings['number_of_transit_days']) && $quote['totalTransitTimeInDays'] > $quoteSettings['number_of_transit_days']) {
                return true;
            }
            // Check by calendar days
        } else {
            if ( isset($quote['CalenderDaysInTransit']) && isset($quoteSettings['number_of_transit_days']) && $quote['CalenderDaysInTransit'] > $quoteSettings['number_of_transit_days']) {
                return true;
            }
        }
        return false;
    }



    public function compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $access, $isMultiShipment){
        //print_r($shipments); exit;
        $shipments = $this->formateQuoteBeforeCompile($shipments);

        $this->quoteSettings = $connectionSettings['fedex-small']['quote_settings'] ?? [];
        $isHazmat = $smalLtlHazmat['smallHazmat'] ?? false;
        $allConfigServices = [];
        foreach ($this->quoteSettings['carrier_services'] as $key => $serviceName){
            if($serviceName){
                $allConfigServices[] = strtoupper($key);
            }
        }
        $this->quoteSettingsData();
        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = false;
        $numberOfShipments = 0;
        foreach ($shipments as $ship){
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }
        if(!$isMultiShipment) {
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
                // $this->getAutoResidentialTitle('');
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
            }
            $lowestAmount = 0;

            if (isset($quote['q'])) {
                foreach ($quote['q'] as $key => $data) {

                    // Check if service type is checked to show
                    if (!in_array($data['serviceType'], $allConfigServices)) {
                        continue;
                    }
                    //  CHeck FOr Ups ground transit days
                    if ($data['serviceType'] == "GND") {
                        // TODO: ALso We have to check plan here
                        if (isset($this->quoteSettings['number_of_transit_days']) && $this->quoteSettings['number_of_transit_days'] != null && isset($this->quoteSettings['ground_metric']) && $this->quoteSettings['ground_metric'] != null) {
                            $islimited = $this->checkGroundTransit($data, $this->quoteSettings);
                            if ($islimited) {
                                continue;
                            }
                        }
                    }
                    //  CHecks FOr Only quote ground service if hazardous
                    if ($isHazmat && isset($this->quoteSettings['ground_service_for_hazardous_material']) && $this->quoteSettings['ground_service_for_hazardous_material']) {
                        if ($data['serviceType'] != "GND") {
                            continue;
                        }
                    }

                    //$access = $this->getAccessorialCodeSmall();
                    // Adding Markup in services if enabled
                    $price = $this->getServiceRate($data['totalNetCharge']['Amount'], $data['serviceType'], $this->quoteSettings);
                    $quoteSettings = $this->quoteSettings;

                    $price = $this->addHandlingMarkupOfHazmat($price, $quoteSettings['handling_fee_markup'] ?? 0);
                    // Checking hazmat and adding hazmat amounts in services
                    if ($isHazmat) {
                        if($this->isMultiShipment){
                            if ($hazmatAllItems[$origin] == 'Y'){
                                $price = $this->addHazmatAmountsInServices($price, $data['serviceType'], $this->quoteSettings);
                            }
                        }else{
                            $price = $this->addHazmatAmountsInServices($price, $data['serviceType'], $this->quoteSettings);
                        }
                    }


                    $title = $this->getServiceTitle($data['serviceDesc'], $data, $data['serviceType'], $this->quoteSettings, $residential);
                    $price = (float) str_replace(',','',$price);
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['code'] = 'parcel_12fedex'.$data['serviceType'] . $access;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['rate'] = $price;
                    $originQuotes[$shipmentCount]['shipment'][$key]['simple']['title'] = $title;
                    $multiShipmentQuotes['simple'][$origin] = $originQuotes[$shipmentCount]['shipment'][$key]['simple'];
                }
            }
            $shipmentCount++;
        }
        //  dd($originQuotes,'dds',$this->isMultiShipment);
        // $multiShipmentQuotes
        // Check for mukti shipment finding lowest price in each shipment and adding them for multi shipment
        if ($this->isMultiShipment) {
            $originQuotesMulti = [];
            $multiShipPrice = 0;
            foreach ($originQuotes as $shipmentKey => $shipment) {
                $netChargeArray = array_column($shipment['shipment'], 'simple');
                $minValueFromNetChargeArr = min(array_column($netChargeArray, 'rate'));
                $multiShipPrice += str_replace(',', '', $minValueFromNetChargeArr);
                $originQuotesMulti[0]['code'] = 'Multifedexsmall'.$access;
                $originQuotesMulti[0]['rate'] = number_format($multiShipPrice, 2);
                $originQuotesMulti[0]['title'] = $residential ? 'Shipping ( R ) ' : 'Shipping';
            }
            $resp = [
                'checkoutQuotes' => $originQuotesMulti,
                'multiShipmentQuotes' => $multiShipmentQuotes,
            ];
            $returnResp['resp'] = $resp;
            return $returnResp;
        }
        // Doing For SIngle Shipment
        //dd($originQuotes);
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


    public function formateQuoteBeforeCompile($shipments){
        foreach ($shipments as $shipment => $serviceTypes){
            foreach ($serviceTypes as $serviseName => $quotes) {
                if (!isset($quotes['q'])) {
                    continue;
                }
                foreach ($quotes['q'] as $key => $quote) {
                    $shipments[$shipment]['q'][$key] = $quote;
                    $shipments[$shipment]['q'][$key]['serviceDesc'] = ucwords(strtolower(str_replace('_', ' ', $quote['serviceType'])));
                }
                unset($shipments[$shipment][$serviseName]);
            }
        }
        return $shipments;
    }

    public function calenderDays($fDesc, $tnts){
        $resp = '';
        foreach ($tnts as $key => $tnt){
            $desc = $tnt['Service']['Description'] ?? '';
            if($desc === $fDesc){
                $resp = $tnt['EstimatedArrival']['BusinessDaysInTransit'];
            }
        }
        return $resp;
    }

    public function quoteSettingsData()
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
        $this->resiLabel = ' (R)';
        $this->lgLabel = ' (L)';
        $this->resiLgLabel = ' (R | L)';
    }

    public function getCompiledQuotes($services, $arraySorting, $lgQuotes, $isMulitshipment)
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

}
