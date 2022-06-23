<?php

namespace App\CustomClasses\Fedex\ltl;

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
        if (strpbrk($markupValue, '%') !== false) {
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

    public function getServiceTitle($title, $data, $serviceCode, $quoteSettings, $isResi = false)
    {
        if (isset($data['totalTransitTimeInDays']) && $data['totalTransitTimeInDays'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 2) {
            $title = $title . ' (Estimated number of days until delivery is ' . $data['totalTransitTimeInDays'] . ')';
        } else if (isset($data['deliveryTimestamp']) && $data['deliveryTimestamp'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 3) {
            $title = $title . ' (Delivery by ' . date('m-d-y h:i A', strtotime($data['deliveryTimestamp'])) . ')';
        }
        $resiTitle = '';
        if ($isResi) {
            $resiTitle = Constant::RESI_LABEL;
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
            if (isset($quote['CalenderDaysInTransit']) && isset($quoteSettings['number_of_transit_days']) && $quote['CalenderDaysInTransit'] > $quoteSettings['number_of_transit_days']) {
                return true;
            }
        }
        return false;
    }

    public function compileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $isMultiShipment)
    {
        //print_r($shipments); exit;
        $shipments = $this->formateQuoteBeforeCompile($shipments);
        $this->quoteSettings = $connectionSettings['fedex-ltl']['quote_settings'] ?? [];
        $allConfigServices = [];
        if (isset($this->quoteSettings['fedex_freight_economy']) && $this->quoteSettings['fedex_freight_economy']) {
            array_push($allConfigServices, 'FEDEX_FREIGHT_ECONOMY');
        }
        if (isset($this->quoteSettings['fedex_freight_priority']) && $this->quoteSettings['fedex_freight_priority']) {
            array_push($allConfigServices, 'FEDEX_FREIGHT_PRIORITY');
        }
        $this->quoteSettingsData();
        $allQuotes = $odwArr = $hazShipmentArr = $multiShipmentQuotes = [];
        $count = 0;
        $lgQuotes = false;
        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }
        if (!$isMultiShipment) {
            $isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        }
        foreach ($shipments as $origin => $quote) {

            if (isset($quote['severity'])) {
                continue;
            }

            if ($count == 0) { //To be checked only once
                $isRad = $quote['autoResidentialsStatus'] ?? '';
                $inStoreLdData = $quote['InstorPickupLocalDelivery'] ?? false;
                unset($quote['InstorPickupLocalDelivery']);
                $lgQuotes =
                    (isset($this->quoteSettings['alwaysLiftGateDelivery']) && $this->quoteSettings['alwaysLiftGateDelivery']) ||
                    (isset($this->quoteSettings['offerLiftGateDelivery']) && $this->quoteSettings['offerLiftGateDelivery']);
                if (!$lgQuotes) {
                    $lgQuotes = ((isset($this->quoteSettings['autoDetectedResidentialAddresses']) && $this->quoteSettings['autoDetectedResidentialAddresses']) &&
                            (isset($this->quoteSettings['autoDetectedResidentialAddressesLfg']) && $this->quoteSettings['autoDetectedResidentialAddressesLfg'])) && $this->isResi;
                }
            }
            $originQuotes = [];
            $arraySorting = [];
            if (isset($quote['q'])) {
                if (isset($quote['hazardousStatus'])) {
                    $hazShipmentArr[$origin] = $quote['hazardousStatus'] == 'y' ? 'Y' : 'N';
                }
                foreach ($quote['q'] as $key => $data) {
                    if (isset($data['serviceType']) && in_array($data['serviceType'], $allConfigServices)) {
                        $access = $this->CompileQuotes->getAccessorialCode();
                        $price = $this->CompileQuotes->calculatePrice($data);
                        $title = $this->CompileQuotes->getTitle($data['serviceType'], false, false, $data['transitTime']);
                        $arraySorting['simple'][$key] = $price;
                        $originQuotes[$key]['simple']['code'] = $data['serviceType'] . $access;
                        $originQuotes[$key]['simple']['rate'] = $price;
                        $originQuotes[$key]['simple']['title'] = $title;
                        if ($lgQuotes) {
                            $lgAccess = $this->CompileQuotes->getAccessorialCode(true);
                            $lgPrice = $this->CompileQuotes->calculatePrice($data, true);
                            $lgTitle = $this->CompileQuotes->getTitle($data['serviceType'], true, false, $data['transitTime']);
                            $arraySorting['liftgate'][$key] = $lgPrice;
                            $originQuotes[$key]['liftgate']['code'] = $data['serviceType'] . $lgAccess;
                            $originQuotes[$key]['liftgate']['rate'] = $lgPrice;
                            $originQuotes[$key]['liftgate']['title'] = $lgTitle;
                        }
                    }
                }
            }

            $compiledQuotes = $this->CompileQuotes->getCompiledQuotes($originQuotes, $arraySorting, $lgQuotes);

            if ($compiledQuotes !== null && !empty($compiledQuotes)) {
                if (count($compiledQuotes) > 1) {
                    foreach ($compiledQuotes as $k => $service) {
                        $allQuotes['simple'][] = $service['simple'];
                        $multiShipmentQuotes['simple'][$origin] = $service['simple'];
                        $lgQuotes ? $allQuotes['liftgate'][] = $service['liftgate'] : null;
                        $lgQuotes ? $multiShipmentQuotes['liftgate'][$origin] = $service['liftgate'] : null;
                    }
                } else {
                    $service = reset($compiledQuotes);
                    $allQuotes['simple'][] = $service['simple'] ?? '';
                    $multiShipmentQuotes['simple'][$origin] = $service['simple'] ?? '';
                    $lgQuotes ? $allQuotes['liftgate'][] = $service['liftgate'] : null;
                    $lgQuotes ? $multiShipmentQuotes['liftgate'][$origin] = $service['liftgate'] : null;
                }
            }

            if ($this->isMultiShipment) {
                $odwArr[$origin]['quotes'] = $compiledQuotes;
            }
            $count++;
        }
        $allQuotes = $this->CompileQuotes->getFinalQuotesArray($allQuotes);
        if (!$this->isMultiShipment && isset($inStoreLdData) && !empty($inStoreLdData)) {
            $allQuotes = $this->CompileQuotes->inStoreLocalDeliveryQuotes($allQuotes, $inStoreLdData, $allOrigins);
        }
        if ((!empty($multiShipmentQuotes['simple']) && count($multiShipmentQuotes['simple']) > 1) || (!empty($multiShipmentQuotes['liftgate']) && count($multiShipmentQuotes['liftgate']) > 1)) {

            $allQuotes = $this->CompileQuotes->forceChangeTitle($allQuotes);
            $resp = [
                'checkoutQuotes' => $this->CompileQuotes->arrangeOwnFreight($allQuotes),
                'multiShipmentQuotes' => $multiShipmentQuotes,
            ];
            return $resp;
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
            'isMultiShipment' => $isMultiShipment,
        ];
        return $resp;
    }

    public function formateQuoteBeforeCompile($shipments){
        foreach ($shipments as $shipment => $quotes){
            if(!isset($quotes['q']) || isset($quotes['q']['severity'])){
                continue;
            }
            foreach ($quotes['q'] as $key => $quote) {
                if (isset($quote['serviceType'])) {
                    $shipments[$shipment]['q'][$key]['serviceDesc'] = $quote['serviceType'] === 'FEDEX_FREIGHT_PRIORITY' ? 'Freight Priority' : 'Freight Economy';
                    if (isset($quote['surcharges'])) {
                        foreach ($quote['surcharges'] as $surcharge) {
                            if (isset($surcharge['SurchargeType']) && $surcharge['SurchargeType'] === 'LIFTGATE_DELIVERY') {
                                unset($shipments[$shipment]['q'][$key]['surcharges']);
                                $shipments[$shipment]['q'][$key]['surcharges']['liftgateFee'] = $surcharge['Amount']['Amount'] ?? 0;
                            }

                        }

                    }
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

    public function quoteSettingsData()
    {
        $fields = [
            'fedex_freight_economy_label' => 'fedex_freight_economy_label',
            'fedex_freight_priority_label' => 'fedex_freight_priority_label',
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
        $this->resiLabel = Constant::RESI_LABEL;
        $this->lgLabel = Constant::LIFT_LABEL;
        $this->resiLgLabel = Constant::RESI_LIFT_LABEL;
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
