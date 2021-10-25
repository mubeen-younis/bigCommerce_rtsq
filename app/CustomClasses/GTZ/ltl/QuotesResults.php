<?php


namespace App\CustomClasses\GTZ\ltl;


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
            foreach ($quotes['q'] as $key => $quote){
                $shipments[$shipment]['q'][$key]['serviceType'] = $quote['CarrierDetail']['CarrierCode'] ?? '';
                $shipments[$shipment]['q'][$key]['serviceDesc'] = $quote['CarrierDetail']['CarrierName'] ?? '';
                $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = $quote['LtlAmount'] ?? 0;
                if(isset($quote['Charges'])) {
                    foreach ($quote['Charges'] as $surcharge){
                        if(isset($surcharge['AccessorialID']) && $surcharge['AccessorialID'] == 12){
                            $shipments[$shipment]['q'][$key]['surcharges']['liftgateFee'] = $surcharge['Charge'] ?? 0;
                            unset($shipments[$shipment]['q'][$key]['Charges']);
                        }
                        if(isset($surcharge['AccessorialID']) && $surcharge['AccessorialID'] == 17){
                            $shipments[$shipment]['q'][$key]['surcharges']['notifyDeliveryFee'] = $surcharge['Charge'] ?? 0;
                            unset($shipments[$shipment]['q'][$key]['Charges']);
                        }
                        if(isset($surcharge['AccessorialID']) && $surcharge['AccessorialID'] == 139){
                            $shipments[$shipment]['q'][$key]['surcharges']['limitedAccessDeliveryFee'] = $surcharge['Charge'] ?? 0;
                            unset($shipments[$shipment]['q'][$key]['Charges']);
                        }
                    }
                }
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
