<?php

namespace App\CustomClasses\AbfLtl;

use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;
use App\CustomClasses\Functions;

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
        $srvcDesc = $connSettings['quote_settings']['label_as'] ?? Functions::$simpleLTLTitle;
        foreach ($shipments as $shipment => $quotes) {
            if (!isset($quotes['q']) || isset($quotes['q']['NUMERRORS'] ) && $quotes['q']['NUMERRORS'] == 1) {
                continue;
            }
            $quotesArr = $quotes['q'];
            $lgStatus = $quotes['liftGateStatus'] ?? '';
            $radStatus = $quotes['residentialStatus'] ?? '';
            $lgFee = 0;

            if((isset($quotes['q']) && !$this->isAbfError($quotes)) || isset($quotes['q']['NUMERRORS']) && $quotes['q']['NUMERRORS'] == 0){
                $items = $quotesArr['ITEMIZEDCHARGES']['ITEM'] ?? [];
                $lineItems = [];
             
                foreach ($items as $key => $value) {
               
                    if ($value['@attributes']['TYPE'] == 'CHARGE') {
                        $lineItems[$key] = $value;
                        $lineItems[$key]['hazardous'] = $value['Hazardous'] ?? '';
                    }
                }

                $formattedShipments[$shipment]['q'] = $this->formatShipments($quotesArr,
                'Standard', $srvcDesc, $lineItems, $lgStatus, $radStatus, $quotesArr['CHARGE']);

                if (isset($lgStatus) && $lgStatus != 'n') {

                    $formattedShipments[$shipment]['q']['surcharges']['liftgateFee'] = $quotes['q']['INCLUDEDCHARGES']['LIFTGATEGROUNDDELIVERY'] ?? 0;        
                }  

                if(isset($quotes['q']['INCLUDEDCHARGES']['ARRIVALNOTIFICATION']) && !empty($quotes['q']['INCLUDEDCHARGES']['ARRIVALNOTIFICATION'])){
                    $formattedShipments[$shipment]['q']['surcharges']['notifyDeliveryFee'] = $quotes['q']['INCLUDEDCHARGES']['ARRIVALNOTIFICATION'] ?? 0;
                }

            }else{

                $formattedShipments = [];
                
            }

            if (isset($quotes['holdAtTerminalResponse']) && !empty($quotes['holdAtTerminalResponse'])) {
                $hatResp[] = $quotes['holdAtTerminalResponse'];
                $srvcTitle = $connSettings['quote_settings']['label_as'] ?? $srvcDesc;

                $hatCompiledQuotes = $this->formatHATQuotes($hatResp, $srvcTitle, $connSettings);
                if (!empty($hatCompiledQuotes)) {
                    $key = count($shipments[$shipment]['q']);
                    $formattedShipments[$shipment]['q']['holdAtTerminalResponse'] = $hatCompiledQuotes;
                }
            }

            if(isset($quotes['InstorPickupLocalDelivery']) && !empty($quotes['InstorPickupLocalDelivery'])){
                $formattedShipments[$shipment]['q']['InstorPickupLocalDelivery'] = $quotes['InstorPickupLocalDelivery'];
            }
        }

        return $formattedShipments;
    }

    private function isAbfError($q)
    {
        return isset($q['q']['NUMERRORS']) && ($q['q']['NUMERRORS'] == 1 || $q['q']['NUMERRORS'] == 2);
    }

    private function formatShipments($quotesArr, $srvcType, $srvcDesc, $lineItems, $lgStatus, $radStatus, $charges): array
    {
        return array(
            'serviceType' => $srvcType ?? '',
            'serviceDesc' => $srvcDesc ?? '',
            'lineItems' => $lineItems,
            'liftGateStatus' => $lgStatus,
            'residentialStatus' => $radStatus,
            'deliveryDate' => $quotesArr['ADVERTISEDDUEDATE'] ?? '',
            'totalTransitTimeInDays' => $quotesArr['totalTransitTimeInDays'] ?? 0,
            'totalNetCharge' => array('Amount' => $charges ?? 0),

        );
    }

    private function formatHATQuotes($hatQuotes = [], $srvcTitle = '', $quoteSettings)
    {
        if (empty($hatQuotes)) {
            return [];
        }

        $compiledQuotes = [];
        foreach ($hatQuotes as $quote) {
            $compiledQuotes['serviceType'] = 'abfltl+HAT+';
            $title = $srvcTitle ?? $quote['Title'] ?? '';
            $address['city'] = $quote['address']['DESTTERMCITY'] ?? '';
            $address['state'] = $quote['address']['DESTTERMSTATE'] ?? '';
            $address['zipCode'] = $quote['address']['DESTTERMZIP'] ?? '';
            $distance = $quote['distance']['text'] ?? '0 mi';
            $phoneNumber = $quote['address']['DESTTERMPHONE'] ?? '';

            $compiledQuotes['serviceDesc'] = Functions::getHATTitle($title, $address, $distance, $phoneNumber);
            $compiledQuotes['totalNetCharge']['Amount'] = Functions::getHATPrice($quote['totalNetCharge'], $quoteSettings['quote_settings']['hold_at_terminal_price'] ?? 0);
            $compiledQuotes['deliveryTimestamp'] = $quote['deliveryDate'] ?? '';
            $compiledQuotes['totalTransitTimeInDays'] = $quote['totalTransitTimeInDays'] ?? '';
            $compiledQuotes['transitTime'] = $quote['transitTime'] ?? '';
            $compiledQuotes['transitDays'] = $quote['transitDays'] ?? '';
        }

        return $compiledQuotes;
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

    public function arrangeHATFreight($finalQuotes, $HATQuotes)
    {
        if (empty($HATQuotes)) {
            return $finalQuotes;
        }

        $newQuotes = [];
        foreach ($HATQuotes as $data) {
            $newQuotes[] = [
                'code' => $data['serviceType'],
                'title' => $data['serviceDesc'],
                'rate' => $data['totalNetCharge']['Amount'],
            ];
        }

        return array_merge($finalQuotes, $newQuotes);
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
