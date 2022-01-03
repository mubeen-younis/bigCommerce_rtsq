<?php


namespace App\CustomClasses\RL\ltl;


use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;

class QuotesResults
{
    public $isMultiShipment = false;
    public function __construct()
    {
        $this->CompileQuotes = new CompileQuotes();
    }


    public function GTZcompileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $isMultiShipment){
        //print_r($shipments); exit;
    }

    public function formateQuoteBeforeCompile($shipments, $quoteSettings){
        //print_r($shipments); exit;
        foreach ($shipments as $shipment => $quotes){
            if(isset($quotes['q']) || isset($quotes['quotesWithInsideDel']) || isset($quotes['holdAtTerminalResponse']) || isset($quotes['InstorPickupLocalDelivery'])) {
                unset($shipments[$shipment]);
                /*if(isset($quotes['q']['ServiceLevels']['ServiceLevel'])) {
                    if(!isset($quotes['q']['ServiceLevels']['ServiceLevel'][0])){
                        $services = $quotes['q']['ServiceLevels']['ServiceLevel'];
                        unset($quotes['q']['ServiceLevels']['ServiceLevel']);
                        $quotes['q']['ServiceLevels']['ServiceLevel'][0] = $services;
                    }

                    foreach ($quotes['q']['ServiceLevels']['ServiceLevel'] as $key => $quote) {
                        $key = isset($shipments[$shipment]['q']) ? count($shipments[$shipment]['q']) :0;
                        $shipments[$shipment]['q'][$key] = $quote;
                        $shipments[$shipment]['q'][$key]['serviceType'] = $quote['Code'] ?? '';
                        $shipments[$shipment]['q'][$key]['serviceDesc'] = $quote['Title'] ?? '';
                        $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = (float) str_replace('$', '',$quote['NetCharge']);
                        $shipments[$shipment]['q'][$key]['deliveryTimestamp'] = $quote['deliveryDate'] ?? '';
                        $shipments[$shipment]['q'][$key]['transitTime'] = $quote['totalTransitTimeInDays'] ?? '';
                        $shipments[$shipment]['q'][$key]['surcharges']['liftgateFee'] = $this->liftGateFees($quotes);
                    }
                }*/
                if(isset($quotes['quotesWithInsideDel']['ServiceLevels']['ServiceLevel'])){
                    if(!isset($quotes['quotesWithInsideDel']['ServiceLevels']['ServiceLevel'][0])){
                        $services = $quotes['quotesWithInsideDel']['ServiceLevels']['ServiceLevel'];
                        unset($quotes['quotesWithInsideDel']['ServiceLevels']['ServiceLevel']);
                        $quotes['quotesWithInsideDel']['ServiceLevels']['ServiceLevel'][0] = $services;
                    }
                    foreach ($quotes['quotesWithInsideDel']['ServiceLevels']['ServiceLevel'] as $key => $quote) {
                        $key = isset($shipments[$shipment]['q']) ? count($shipments[$shipment]['q']) :0;
                        $shipments[$shipment]['q'][$key] = $quote;
                        $shipments[$shipment]['q'][$key]['serviceType'] = 'inside+'.$quote['Code'];
                        $shipments[$shipment]['q'][$key]['serviceDesc'] = $quote['Title'];
                        $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = (float) str_replace('$', '',str_replace(',','',$quote['NetCharge']));
                        $shipments[$shipment]['q'][$key]['deliveryTimestamp'] = $quote['deliveryDate'] ?? '';
                        $shipments[$shipment]['q'][$key]['transitTime'] = $quote['totalTransitTimeInDays'] ?? '';
                        $shipments[$shipment]['q'][$key]['surcharges']['liftgateFee'] = $this->liftGateFees($quotes);
                        $shipments[$shipment]['q'][$key]['surcharges']['insidedelivery'] = $this->insideFees($quotes);
                    }
                }else{
                    //if(isset($quotes['q'])) {
                        if (!isset($quotes['q']['ServiceLevels']['ServiceLevel'][0])) {
                            $services = $quotes['q']['ServiceLevels']['ServiceLevel'];
                            unset($quotes['q']['ServiceLevels']['ServiceLevel']);
                            $quotes['q']['ServiceLevels']['ServiceLevel'][0] = $services;
                        }

                        foreach ($quotes['q']['ServiceLevels']['ServiceLevel'] as $key => $quote) {
                            $key = isset($shipments[$shipment]['q']) ? count($shipments[$shipment]['q']) : 0;
                            $shipments[$shipment]['q'][$key] = $quote;
                            $shipments[$shipment]['q'][$key]['serviceType'] = $quote['Code'] ?? '';
                            $shipments[$shipment]['q'][$key]['serviceDesc'] = $quote['Title'] ?? '';
                            $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = (float) str_replace('$', '',str_replace(',','',$quote['NetCharge']));
                            $shipments[$shipment]['q'][$key]['deliveryTimestamp'] = $quote['deliveryDate'] ?? '';
                            $shipments[$shipment]['q'][$key]['transitTime'] = $quote['transitTime'] ?? '';
                            $shipments[$shipment]['q'][$key]['totalTransitTimeInDays'] = $quote['totalTransitTimeInDays'] ?? '';
                            $shipments[$shipment]['q'][$key]['surcharges']['liftgateFee'] = $this->liftGateFees($quotes);
                        }
                        if (isset($quotes['InstorPickupLocalDelivery'])) {
                            $shipments[$shipment]['InstorPickupLocalDelivery'] = $quotes['InstorPickupLocalDelivery'];
                        }
                    //}
                }

                if(isset($quotes['holdAtTerminalResponse']['serviceLevels'])){
                    foreach ($quotes['holdAtTerminalResponse']['serviceLevels'] as $key => $quote) {
                        $key = count($shipments[$shipment]['q']);
                        $shipments[$shipment]['q'][$key] = $quote;
                        unset($shipments[$shipment]['q'][$key]['totalNetCharge']);
                        $shipments[$shipment]['q'][$key]['serviceType'] = 'rnlltl+HAT+'.$quote['Code'];
                        $shipments[$shipment]['q'][$key]['serviceDesc'] = $this->titleHAT($quote['Title'], $quotes['holdAtTerminalResponse']['address'], $quotes['holdAtTerminalResponse']['distance']);
                        $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = $this->getPrice($quote['totalNetCharge'], $quoteSettings['hold_at_terminal_price']);
                        $shipments[$shipment]['q'][$key]['deliveryTimestamp'] = $quote['deliveryDate'] ?? '';
                        $shipments[$shipment]['q'][$key]['totalTransitTimeInDays'] = $quote['totalTransitTimeInDays'] ?? '';
                        $shipments[$shipment]['q'][$key]['transitTime'] = $quote['transitTime'] ?? '';
                    }
                }
            }
        }
        return $shipments;
    }

    function getPrice($price, $hatPrice){
        if((strlen($hatPrice) > 0)) {
            $symbolicHATFee = strpos($hatPrice, '%') ? '%' : '';
            $hatPrice = (float)$hatPrice ?? 0;
            if ($symbolicHATFee === '%') {
                $hatPrice = $hatPrice / 100 * $price;
                $price = $price + $hatPrice;
            } else {
                $price = $price + $hatPrice;
            }
        }
        return $price;
    }
    function titleHAT($title, $address, $distance){
        return $title.' | Hold At Terminal | '.$distance['text']. ' | ' .$address['Code']. ', '. $address['State']. ', '. $address['ZipCode']. ' | '. $address['Phone'];
    }

    function liftGateFees($quotes){
        $fees = 0;
        if(isset($quotes['q']['Charges']['Charge'])){
            foreach ($quotes['q']['Charges']['Charge'] as $charge){
                if(isset($charge['Type']) && $charge['Type'] == 'LIFT'){
                    $fees = (float) str_replace('$', '',$charge['Amount']);
                    break;
                }
            }
        }
        return $fees;
    }

    function insideFees($quotes){
        $fees = 0;
        if(isset($quotes['quotesWithInsideDel']['Charges']['Charge'])){
            foreach ($quotes['quotesWithInsideDel']['Charges']['Charge'] as $charge){
                if(isset($charge['Type']) && $charge['Type'] == 'ID'){
                    $fees = (float) str_replace('$', '',$charge['Amount']);
                    break;
                }
            }
        }
        return $fees;
    }

    public function calculatePrice($data, $uoteSettings, $lgOption = false, $notify = false, $laccess = false)
    {
        $lgCost = $lgOption ? 0 : $data['surcharges']['liftgateFee'] ?? 0;
        $nCost = $notify ? 0 : $data['surcharges']['notifyDeliveryFee'] ?? 0;
        $laCost = $laccess ? 0 : $data['surcharges']['limitedAccessDeliveryFee'] ?? 0;
        $basePrice = (float)$data['totalNetCharge']['Amount'];
        $basePrice = $basePrice - $lgCost - $nCost - $laCost;
        $basePrice = $this->CompileQuotes->calculateHandlingFee($basePrice, $uoteSettings);
        return $basePrice;
    }

    public function getAccessorialCode($isResi = false, $lgOption = false, $notify = false, $laccess = false){
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
