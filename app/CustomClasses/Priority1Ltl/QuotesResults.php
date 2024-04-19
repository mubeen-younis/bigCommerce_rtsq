<?php


namespace App\CustomClasses\Priority1Ltl;


use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;

class QuotesResults
{
    public $isMultiShipment = false;
    public function __construct()
    {
        $this->CompileQuotes = new CompileQuotes();
    }

    public function formateQuoteBeforeCompile($shipments){
        foreach ($shipments as $shipment => $quotes){
            if(!isset($quotes['q'])){
                continue;
            }
            foreach ($quotes['q'] as $key => $quote){
                $shipments[$shipment]['q'][$key]['serviceType'] = $quote['carrierCode'] ?? '';
                $shipments[$shipment]['q'][$key]['serviceDesc'] = $quote['carrierName'] ?? '';
                $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = $quote['rateQuoteDetail']['total'] ?? 0;
                if(isset($quote['rateQuoteDetail']['charges'])) {
                    foreach ($quote['rateQuoteDetail']['charges'] as $surcharge){
                        if(isset($surcharge['code']) && ($surcharge['code'] == 'LGDEL' || $surcharge['code'] == 'LiftgateService')){
                            $shipments[$shipment]['q'][$key]['surcharges']['liftgateFee'] = $surcharge['amount'] ?? 0;
                        }
                        if(isset($surcharge['code']) && ($surcharge['code'] == 'NOTIFY' || $surcharge['code'] == 'APPT')){
                            $shipments[$shipment]['q'][$key]['surcharges']['notifyDeliveryFee'] = $surcharge['amount'] ?? 0;
                        }
                        if(isset($surcharge['code']) && ($surcharge['code'] == 'RESDEL') || ($surcharge['code'] == 'RES')){
                            $shipments[$shipment]['q'][$key]['surcharges']['residentialFee'] = $surcharge['amount'] ?? 0;
                        }
                    }
                }
                unset($shipments[$shipment]['q'][$key]['rateQuoteDetail']);
            }
            
        }
        return $shipments;
    }
}
