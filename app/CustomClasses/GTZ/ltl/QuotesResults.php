<?php


namespace App\CustomClasses\GTZ\ltl;


use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;
use App\CustomClasses\Functions;

class QuotesResults
{
    public $isMultiShipment = false;
    public function __construct()
    {
        $this->CompileQuotes = new CompileQuotes();
    }


    public function GTZcompileQuotes($shipments, $connectionSettings, $allOrigins, $smalLtlHazmat, $hazmatAllItems, $residential, $isMultiShipment){
        
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
                        if(!isset($shipments[$shipment]['q'][$key]['surcharges']['liftgateFee'])){
                            $shipments[$shipment]['q'][$key]['surcharges']['liftgateFee'] = 0;
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

    public function calculatePrice($data, $uoteSettings, $lgOption = false, $notify = false, $laccess = false, $originKey = '', $items = [], $allOrigins = [])
    {
        $lgCost = $lgOption ? 0 : $data['surcharges']['liftgateFee'] ?? 0;
        $nCost = $notify ? 0 : $data['surcharges']['notifyDeliveryFee'] ?? 0;
        $laCost = $laccess ? 0 : $data['surcharges']['limitedAccessDeliveryFee'] ?? 0;
        $basePrice = (float)$data['totalNetCharge']['Amount'];
        $basePrice = $basePrice - $lgCost - $nCost - $laCost;
        $productOriginMarkupFee = Functions::calProductOriginMarkupFee($basePrice, $originKey, $items, $allOrigins);
        $basePrice = $this->CompileQuotes->calculateHandlingFee($basePrice, $uoteSettings);
        $basePrice = $basePrice + $productOriginMarkupFee;
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

    public function formateCerasisQuoteBeforeCompile($shipments){

        foreach ($shipments as $shipment => $quotes){
            unset($shipments[$shipment]['q']);
            unset($shipments[$shipment]['quotesWithLiftGate']);
            unset($shipments[$shipment]['debug']);
            if(!isset($quotes['q'])){
                continue;
            }
            foreach ($quotes['q'] as $key => $quote){
                $key = $quote['CarrierScac'];
                $shipments[$shipment]['q'][$key] = $quote;
            }
            if(isset($quotes['quotesWithLiftGate'])) {
                foreach ($quotes['quotesWithLiftGate'] as $key => $quote){
                    $key = $quote['CarrierScac'];
                    $shipments[$shipment]['quotesWithLiftGate'][$key] = $quote;
                }
            }

        }
        
        foreach ($shipments as $shipment => $quotes){
            if(!isset($quotes['q'])){
                continue;
            }
            foreach ($quotes['q'] as $key => $quote){
                //$key = $quote['CarrierScac'];
                $shipments[$shipment]['q'][$key]['serviceType'] = $quote['CarrierScac'] ?? '';
                $shipments[$shipment]['q'][$key]['serviceDesc'] = $quote['CarrierName'] ?? '';
                $shipments[$shipment]['q'][$key]['transitTime'] = $quote['TransitDays'] ?? '';
                $shipments[$shipment]['q'][$key]['totalTransitTimeInDays'] = $quote['totalTransitTimeInDays'] ?? '';
                $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = $quotes['quotesWithLiftGate'][$key]['ShipmentRate'] ?? $quote['ShipmentRate'] ?? 0;
                if(isset($quotes['quotesWithLiftGate'][$key]['ShipmentRate'])) {
                    $shipments[$shipment]['q'][$key]['surcharges']['liftgateFee'] = $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] - $quote['ShipmentRate'];
                }
            }
            unset($shipments[$shipment]['quotesWithLiftGate']);
            unset($shipments[$shipment]['debug']);
        }

        return $shipments;
    }




}
