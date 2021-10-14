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


}
