<?php

namespace App\CustomClasses\SouthEasternLtl;

use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;

class QuotesResults
{
    public function __construct()
    {
        $this->CompileQuotes = new CompileQuotes();
    }

    public function getServiceTitle($title, $data, $serviceCode, $quoteSettings, $isResi = false)
    {
        if (isset($data['totalTransitTimeInDays']) && $data['totalTransitTimeInDays'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 2) {
            $title = $title . ' (Estimated number of days until delivery is ' . $data['totalTransitTimeInDays'] . ')';
        } else if (isset($data['deliveryTimestamp']) && $data['deliveryTimestamp'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 3) {
            $title = $title . ' (Delivery by ' . date('m-d-y h:i A', strtotime($data['deliveryTimestamp'])) . ')';
        }

        $resiTitle = '';
        if ($isResi) {
            $resiTitle = Constant::RESI_LABEL;
        }

        return $title . $resiTitle;
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
        foreach ($shipments as $shipment => $quotes) {
            if (!isset($quotes['q']) || isset($quotes['severity']) && $quotes['severity'] == 'ERROR') {
                continue;
            }
            $quotesArr = $quotes['q'];
            $lgStatus = $quotes['liftGateStatus'] ?? '';
            $radStatus = $quotes['residentialStatus'] ?? '';
            $lgFee = 0;

            if(isset($quotes['q']) && isset($quotes['q']['error']) && $quotes['q']['error'] == []){

                $lineItems = [];
                $formattedShipments[$shipment]['q'] = $this->formatShipments($quotesArr,
                'Standard', 'SouthEastern', $lineItems, $lgStatus, $radStatus, $quotesArr['rateQuote']);

                if (isset($lgStatus) && $lgStatus != 'n') {
                
                    $formattedShipments[$shipment]['q']['surcharges']['liftgateFee'] = 134 ?? 0;        
                }  

            }else{

                $formattedShipments = [];
                
            }

        }

        return $formattedShipments;
    }

    private function formatShipments($quotesArr, $srvcType, $srvcDesc, $lineItems, $lgStatus, $radStatus, $charges): array
    {
        return array(
            'serviceType' => $srvcType ?? '',
            'serviceDesc' => $srvcDesc ?? '',
            'lineItems' => $lineItems,
            'liftGateStatus' => $lgStatus,
            'residentialStatus' => $radStatus,
            'deliveryDate' => $quotesArr['deliveryDate'] ?? '',
            'totalTransitTimeInDays' => $quotesArr['totalTransitTimeInDays'] ?? 0,
            'totalNetCharge' => array('Amount' => $charges ?? 0),

        );
    }

    public function quoteSettingsData()
    {
        $fields = [
            'labelAs' => 'labelAs',
            'dlrvyEstimates' => 'dlrvyEstimates',
            'residentialDlvry' => 'residentialDlvry',
            'liftGate' => 'liftGate',
            'OfferLiftgateAsAnOption' => 'OfferLiftgateAsAnOption',
            'RADforLiftgate' => 'RADforLiftgate',
            'hndlngFee' => 'hndlngFee',
            'symbolicHndlngFee' => 'symbolicHndlngFee',
        ];

        foreach ($fields as $key => $field) {
            $this->$key = $this->configSettings[$field] ?? '';
        }

        $this->resiLabel = Constant::RESI_LABEL;
        $this->lgLabel = Constant::LIFT_LABEL;
        $this->resiLgLabel = Constant::RESI_LIFT_LABEL;
    }

    public function getCompiledQuotes($services, $arraySorting, $isMulitshipment)
    {
        if (empty($arraySorting) || empty($services)) {
            return [];
        }

        asort($arraySorting['simple']);
        $options = $isMulitshipment ? 1 : 2;
        $sliced = array_slice($arraySorting['simple'], 0, $options, true);
        $resp = array_intersect_key($services, $sliced);

        return $resp;
    }
}
