<?php

namespace App\CustomClasses\TQLLtl;

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

        $isLG = $isNotify = false;

        $srvcDesc = $connSettings['quote_settings']['label_as'] ?? Functions::$simpleLTLTitle;
        foreach ($shipments as $shipment => $quotes) {
            if (!isset($quotes['q']) || (isset($quotes['severity']) && $quotes['severity'] == 'ERROR')) {
                continue;
            }
            
            $quotesWithoutLiftgate = $quotesWithoutNofity = $quotesWithoutResidential = $liftgateCharges = $notifyCharges = 0;

            foreach($quotes['q'] as $key => $quote){
                if(!empty($quotes['quotesWithLiftGate'])){
                    foreach($quotes['quotesWithLiftGate'] as $lgQuote){
                        if(isset($quote['scac']) && isset($lgQuote['scac']) && $quote['scac'] == $lgQuote['scac'] && isset($quote['serviceLevel']) && isset($lgQuote['serviceLevel']) && $quote['serviceLevel'] == $lgQuote['serviceLevel']){
                            $priceCharges = $shipments[$shipment]['q'][$key]['priceCharges'] ?? [];
                            $lgFee = $lgQuote['customerRate'] - $quote['customerRate'] ?? 0;
                            foreach($priceCharges as $index => $charge){
                                if(isset($charge['description']) && $charge['description'] == 'Lift Gate'){
                                    $shipments[$shipment]['q'][$key]['priceCharges'][$index]['amount'] = $lgFee;
                                    $isLG = true;
                                }
                            }
                            if(!$isLG){
                                $shipments[$shipment]['q'][$key]['priceCharges'][] = [
                                    'description' => 'Lift Gate',
                                    'amount' => $lgFee ?? 0,
                                ];
                            }
                            $shipments[$shipment]['q'][$key]['customerRate'] += $lgFee;
                        }
                    }
                }

                if(!empty($quotes['quotesWithNotify'])){
                    foreach($quotes['quotesWithNotify'] as $notifyQuote){
                        if(isset($quote['scac']) && isset($notifyQuote['scac']) && $quote['scac'] == $notifyQuote['scac'] && isset($quote['serviceLevel']) && isset($notifyQuote['serviceLevel']) && $quote['serviceLevel'] == $notifyQuote['serviceLevel']){
                            $priceCharges = $shipments[$shipment]['q'][$key]['priceCharges'] ?? [];
                            $notifyFee = $notifyQuote['customerRate'] - $quote['customerRate'] ?? 0;
                            foreach($priceCharges as $index => $charge){
                                if(isset($charge['description']) && $charge['description'] == 'Delivery Call Ahead'){
                                    $shipments[$shipment]['q'][$key]['priceCharges'][$index]['amount'] = $notifyFee;
                                    $isNotify = true;
                                }
                            }
                            if(!$isNotify){
                                $shipments[$shipment]['q'][$key]['priceCharges'][] = [
                                    'description' => 'Delivery Call Ahead',
                                    'amount' => $notifyFee ?? 0,
                                ];
                            }
                            $shipments[$shipment]['q'][$key]['customerRate'] += $notifyFee;
                        }
                    }
                }
            }
        }

        unset($shipments[$shipment]['quotesWithLiftGate'], $shipments[$shipment]['quotesWithNotify']);
        return $shipments;
    }
}
