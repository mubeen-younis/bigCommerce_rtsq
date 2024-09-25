<?php

namespace App\CustomClasses\UpsLandCostApi;

use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;
use App\CustomClasses\Functions;

class QuotesResults
{
    public function __construct()
    {
        $this->CompileQuotes = new CompileQuotes();
    }

    public function isSuppressedRatesShipment($shipments)
    {
        $isSuppressedRates = false;

        foreach ($shipments as $origin => $quote) {
            if (isset($quote['severity']) && isset($quote['q']['error'])) {
                continue;
            }

            $insPickupAndLocDel = $quote['InstorPickupLocalDelivery'] ?? [];
            if (isset($insPickupAndLocDel) && !blank($insPickupAndLocDel)) {
                if (isset($insPickupAndLocDel['suppress']) && $insPickupAndLocDel['suppress'] == 1) {
                    $isSuppressedRates = true;
                    break;
                }
            }
        }

        return $isSuppressedRates;
    }

    public function formateQuoteBeforeCompile($shipments, $connSettings): array
    {
        $formattedShipments = [];
        if ($this->isSuppressedRatesShipment($shipments)) {
            return $shipments;
        }
        $srvcDesc = $connSettings['quote_settings']['label_as'] ?? Functions::$simpleLTLTitle;
        foreach ($shipments as $shipment => $quotes) {
            if (!isset($quotes['q']) || isset($quotes['severity']) && $quotes['severity'] == 'ERROR') {
                continue;
            }

            $quotesArr = $quotes['q'];
            $lgStatus = $quotes['liftGateStatus'] ?? '';
            $radStatus = $quotes['residentialStatus'] ?? '';
            $lgFee = 0;

            if(isset($quotes['q'])){

                $shipments[$shipment]['q']['totalNetCharge']['Amount'] = isset($quotes['q']['grandTotal']) ? $quotes['q']['grandTotal'] : 0;
                $shipments[$shipment]['q']['serviceDesc'] = $srvcDesc;
                $shipments[$shipment]['q']['serviceType'] = 'UPSLandedCostApi';
                
            }else{

                $shipments = [];

            }


            if(isset($quotes['InstorPickupLocalDelivery']) && !empty($quotes['InstorPickupLocalDelivery'])){
                $shipments[$shipment]['q']['InstorPickupLocalDelivery'] = $quotes['InstorPickupLocalDelivery'];
            }
        }

        return $shipments;
    }


}
