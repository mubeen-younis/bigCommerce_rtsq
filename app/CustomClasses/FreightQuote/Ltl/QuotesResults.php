<?php

namespace App\CustomClasses\FreightQuote\Ltl;

use App\Constants\Constant;
use App\CustomClasses\CompileQuotes as compileQuotes;
use App\CustomClasses\Functions;
use App\Http\Controllers\ShippingRuleController;

class QuotesResults
{
    public $isMultiShipment = false;
    public function __construct()
    {
        $this->CompileQuotes = new CompileQuotes();
    }

    public function truckLoadQuotes($quote, $allConfigServices, $connectionSettings = [], $origin, $items, $allOrigins, $carrierName)
    {
        $shippingRule = new ShippingRuleController();
        $originQuotes = [];
        $arraySorting = [];
        $access = '';
        $quoteSettings = $connectionSettings['freightquote-ltl']['quote_settings'] ?? [];
        $storeId = $connectionSettings['freightquote-ltl']['creds']['store_id'] ?? '';
        if(isset($quote['Truckload'])){
            
            foreach($quote['Truckload'] as $key => $data){
                if (isset($data['serviceType']) && in_array($data['serviceType'], $allConfigServices)) {
                    $access = $this->CompileQuotes->getAccessorialCode();
                    $charges = array(
                        'totalNetCharge' => array(
                            'Amount' => $data['totalNetCharge'],
                        ),
                        'surcharges' => $data['surcharges'],
                    );
                    unset($data['totalNetCharge']);
                    $data = array_merge($data, $charges);

                    // Apply Override rates shipping rule
                    $overrideRates = $shippingRule->overrideRates($storeId, $items, $connectionSettings, $data, $carrierName, $origin, $allOrigins);
                    $isOverrideRate = isset($overrideRates['isOverrideRates']) && $overrideRates['isOverrideRates'] ?? false;
                    $data = isset($overrideRates['data']) ? $overrideRates['data'] : $data;

                    $price = $this->CompileQuotes->calculatePrice($data, false, false, false, false, false, false, false, false, $origin, $items, $allOrigins, $quoteSettings);
                    /*
                     * Adding Functionality of Delivery Estimate Options
                     * */
                    $date = $data['deliveryTimestamp'] ?? null;
                    $days = $data['totalTransitTimeInDays'] ?? null;
                    $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];
                    
                    $title = $this->getTruckLoadTitle($data['serviceDesc'], $quoteSettings, $data['totalTransitTimeInDays'], $dateAndDays, $data['serviceType']);
                    $arraySorting['simple'][$key] = $price;
                    $originQuotes[$key]['Truckload']['code'] = 'fqltl' . $data['serviceType'] . '+TL' . $access;
                    $originQuotes[$key]['Truckload']['rate'] = $price;
                    $originQuotes[$key]['Truckload']['title'] = $title;
                }
            }
        }
        return [$originQuotes, $arraySorting];

    }

    public function getTruckLoadTitle($serviceName, $quoteSettings = [], $deliveryEstimate = '', $dateAndDays = '', $serviceType = '')
    {
        $serviceTitle = $this->truckLoadCustomLabel($serviceName, $quoteSettings, $serviceType);
        $deliveryEstimateLabel = $this->getDeliveryEstimates($dateAndDays);

        return $serviceTitle . $deliveryEstimateLabel;
    }

    public function truckLoadCustomLabel($serviceName, $quoteSettings = [], $serviceType = '')
    {
        if (!empty($quoteSettings)) {
            $this->quoteSettings = $quoteSettings;
        }
        $this->quoteSettings['method'] = $this->quoteSettings['method'] ?? 1;
        if(($this->quoteSettings['method'] == 1 || $this->quoteSettings['method'] == 3 || $this->quoteSettings['method'] == 2)){
            if($serviceType === 'TSM'){
                return $this->quoteSettings['flatbed'] ?? 'Flatbed Truckload Service';
            } else if($serviceType === 'REEF'){
                return $this->quoteSettings['refrigerated'] ?? 'Refrigerated Truckload Service';
            } else if($serviceType === 'ABHB'){
                return $this->quoteSettings['van'] ?? 'Truckload Service';
            } else {
                return $serviceName;
            }
        }
        return $serviceName;
    }
    
    public function formateQuoteBeforeCompile($shipments){
        foreach ($shipments as $shipment => $quotes){
            if(!isset($quotes['q'])){
                continue;
            }
            /*
             * formate if only old versions
             * check $shipments[$shipment]['q']['serviceType'] is old version
             */
            $quote = $quotes['q'];
            $key = 0;
            if (!isset($shipments[$shipment]['q']['serviceType'])) {
                unset($shipments[$shipment]['q']);
                $shipments[$shipment]['q'][$key] = $quote;
                $shipments[$shipment]['q'][$key]['serviceType'] = 'xpo';
                $shipments[$shipment]['q'][$key]['serviceDesc'] = 'Freight';
                $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = $quote['NetCharge'][0] ?? 0;
                $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = $this->netCharge($quote['NetCharge']);

                $shipments[$shipment]['q'][$key]['deliveryTimestamp'] = $quote['deliveryDate'] ?? '';
                $shipments[$shipment]['q'][$key]['transitTime'] = $quote['TransitTime'][0] ?? '';
                $shipments[$shipment]['q'][$key]['totalTransitTimeInDays'] = $quote['totalTransitTimeInDays'] ?? '';
                $shipments[$shipment]['q'][$key]['surcharges']['liftgateFee'] = $quote['AccessorialCharges']['OtherAccessorialChargesFormated']['DLG'] ?? 0;
            } else {
                unset($shipments[$shipment]['q']);
                $shipments[$shipment]['q'][$key] = $quote;
                unset($shipments[$shipment]['q'][$key]['totalNetCharge']);
                $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = $quote['totalNetCharge'] ?? 0;
                $shipments[$shipment]['q'][$key]['serviceType'] = 'xpo';
                $shipments[$shipment]['q'][$key]['serviceDesc'] = 'Freight';
                $shipments[$shipment]['q'][$key]['transitTime'] = $quote['transitDays'] ?? '';
                $shipments[$shipment]['q'][$key]['totalTransitTimeInDays'] = $quote['totalTransitTimeInDays'] ?? '';
            }
        }
        return $shipments;
    }

    public function netCharge($netCharge)
    {
        $amount = 0;
        foreach ($netCharge as $charge) {
            if (is_array($charge)) {
                if (isset($charge['currency']) && $charge['currency'] === 'USD') {
                    $amount = $charge[0] ?? 0;
                    break;
                }

            } else {
                $amount = $netCharge[0] ?? 0;
                break;
            }
        }
        return $amount;
    }

    public function getDeliveryEstimates($dateAndDays): string
    {
        $date = $dateAndDays['deliveryDate'] ?? null;
        $days = $dateAndDays['totalTransitTimeInDays'] ?? null;

        $deliveryEstimates = "";
        if (isset($this->quoteSettings['delivery_estimate_options']) && $this->quoteSettings['delivery_estimate_options'] == 2) {
            $deliveryEstimates = !blank($days) ? " (Estimated number of days until delivery is " . $days . ")" : "";
        } elseif (isset($this->quoteSettings['delivery_estimate_options']) && $this->quoteSettings['delivery_estimate_options'] == 3) {
            $deliveryEstimates = !blank($date) ? " (Estimated delivery date is " . date('m-d-Y', strtotime($date)) . ")" : "";
        }

        return $deliveryEstimates;
    }

    public function calculatePrice($data, $uoteSettings, $lgOption = false, $notify = false, $laccess = false)
    {
        $lgCost = $lgOption ? 0 : $data['surcharges']['liftgateFee'] ?? 0;
        $nCost = $notify ? 0 : $data['surcharges']['notifyDeliveryFee'] ?? 0;
        $laCost = $laccess ? 0 : $data['surcharges']['limitedAccessDeliveryFee'] ?? 0;
        $basePrice = (float) $data['totalNetCharge']['Amount'];
        $basePrice = $basePrice - $lgCost - $nCost - $laCost;
        $basePrice = $this->CompileQuotes->calculateHandlingFee($basePrice, $uoteSettings);
        return $basePrice;
    }

    public function getAccessorialCode($isResi = false, $lgOption = false, $notify = false, $laccess = false)
    {
        $access = '';
        if ($isResi) {
            $access .= '+R';
        }
        if ($lgOption) {
            $access .= '+LG';
        }
        if ($notify) {
            $access .= '+N';
        }
        if ($laccess) {
            $access .= '+LA';
        }
        return $access;
    }

}
