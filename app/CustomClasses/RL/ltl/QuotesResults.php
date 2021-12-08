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

    public function formateQuoteBeforeCompile($shipments){
        foreach ($shipments as $shipment => $quotes){
            if(isset($quotes['q']) || isset($quotes['quotesWithInsideDel'])) {
                unset($shipments[$shipment]['q']);
                unset($shipments[$shipment]['quotesWithInsideDel']);
                if(isset($quotes['q']['ServiceLevels']['ServiceLevel'])) {
                    foreach ($quotes['q']['ServiceLevels']['ServiceLevel'] as $key => $quote) {
                        $shipments[$shipment]['q'][$key] = $quote;
                        $shipments[$shipment]['q'][$key]['serviceType'] = $quote['Code'];
                        $shipments[$shipment]['q'][$key]['serviceDesc'] = $quote['Title'];
                        $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = (float) str_replace('$', '',$quote['NetCharge']);
                        $shipments[$shipment]['q'][$key]['deliveryTimestamp'] = $quote['deliveryDate'] ?? '';
                        $shipments[$shipment]['q'][$key]['transitTime'] = $quote['totalTransitTimeInDays'] ?? '';
                        $shipments[$shipment]['q'][$key]['surcharges']['liftgateFee'] = $this->liftGateFees($quotes);
                    }
                }
                if(isset($quotes['quotesWithInsideDel']['ServiceLevels']['ServiceLevel'])){
                    foreach ($quotes['quotesWithInsideDel']['ServiceLevels']['ServiceLevel'] as $key => $quote) {
                        $key = count($shipments[$shipment]['q']);
                        $shipments[$shipment]['q'][$key] = $quote;
                        $shipments[$shipment]['q'][$key]['serviceType'] = $quote['Code'];
                        $shipments[$shipment]['q'][$key]['serviceDesc'] = $quote['Title'];
                        $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = (float) str_replace('$', '',$quote['NetCharge']);
                        $shipments[$shipment]['q'][$key]['deliveryTimestamp'] = $quote['deliveryDate'] ?? '';
                        $shipments[$shipment]['q'][$key]['transitTime'] = $quote['totalTransitTimeInDays'] ?? '';
                        $shipments[$shipment]['q'][$key]['surcharges']['liftgateFee'] = $this->liftGateFees($quotes);
                    }
                }
            }
        }
        return $shipments;
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
