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
            if(!isset($quotes['q'])){
                continue;
            }
            /*
             * formate if only old versions
             * check $shipments[$shipment]['q']['serviceType'] is old version
             */
            $quote = $quotes['q'];
            $key = 0;
            if(!isset($shipments[$shipment]['q']['serviceType'])) {
                unset($shipments[$shipment]['q']);
                $shipments[$shipment]['q'][$key] = $quote;
                $shipments[$shipment]['q'][$key]['serviceType'] = 'xpo';
                $shipments[$shipment]['q'][$key]['serviceDesc'] = 'Freight';
                $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = $quote['NetCharge'][0] ?? 0;
                $shipments[$shipment]['q'][$key]['deliveryTimestamp'] = $quote['deliveryDate'] ?? '';
                $shipments[$shipment]['q'][$key]['transitTime'] = $quote['TransitTime'][0] ?? '';
                $shipments[$shipment]['q'][$key]['surcharges']['liftgateFee'] = $quote['AccessorialCharges']['OtherAccessorialChargesFormated']['DLG'] ?? 0;
            }else{
                unset($shipments[$shipment]['q']);
                $shipments[$shipment]['q'][$key] = $quote;
                unset($shipments[$shipment]['q'][$key]['totalNetCharge']);
                $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = $quote['totalNetCharge'] ?? 0;
                $shipments[$shipment]['q'][$key]['serviceType'] = 'xpo';
                $shipments[$shipment]['q'][$key]['serviceDesc'] = 'Freight';
                $shipments[$shipment]['q'][$key]['transitTime'] = $quote['transitDays'] ?? '';
            }
        }
        return $shipments;
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
