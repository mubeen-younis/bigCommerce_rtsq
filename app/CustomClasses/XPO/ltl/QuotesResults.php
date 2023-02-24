<?php


namespace App\CustomClasses\XPO\ltl;


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

    public function formateQuoteBeforeCompile($shipments, $quoteSettings){
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
                $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = $this->netCharge($quote['NetCharge']);

                $shipments[$shipment]['q'][$key]['deliveryTimestamp'] = $quote['deliveryDate'] ?? '';
                $shipments[$shipment]['q'][$key]['transitTime'] = $quote['TransitTime'][0] ?? '';
                $shipments[$shipment]['q'][$key]['totalTransitTimeInDays'] = $quote['totalTransitTimeInDays'] ?? '';
                $shipments[$shipment]['q'][$key]['surcharges']['liftgateFee'] = $quote['AccessorialCharges']['OtherAccessorialChargesFormated']['DLG'] ?? 0;
            }else{
                unset($shipments[$shipment]['q']);
                $shipments[$shipment]['q'][$key] = $quote;
                unset($shipments[$shipment]['q'][$key]['totalNetCharge']);
                $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = $quote['totalNetCharge'] ?? 0;
                $shipments[$shipment]['q'][$key]['serviceType'] = 'xpo';
                $shipments[$shipment]['q'][$key]['serviceDesc'] = 'Freight';
                $shipments[$shipment]['q'][$key]['transitTime'] = $quote['transitDays'] ?? '';
                $shipments[$shipment]['q'][$key]['totalTransitTimeInDays'] = $quote['totalTransitTimeInDays'] ?? '';
            }

            if (isset($quote['holdAtTerminalResponse']) && !empty($quote['holdAtTerminalResponse'])) {
                $hatResp[] = $quote['holdAtTerminalResponse'];
                $srvcTitle = $quoteSettings['label_as'] ?? Functions::$simpleLTLTitle ?? '';

                $hatCompiledQuotes = $this->formatHATQuotes($hatResp, $srvcTitle, $quoteSettings);
                if (!empty($hatCompiledQuotes)) {
                    $key = count($shipments[$shipment]['q']);
                    $shipments[$shipment]['q'][$key] = $hatCompiledQuotes;
                }
            }
        }

        return $shipments;
    }

    private function formatHATQuotes($hatQuotes = [], $srvcTitle = '', $quoteSettings)
    {
        if (empty($hatQuotes)) {
            return [];
        }

        $compiledQuotes = [];
        foreach ($hatQuotes as $quote) {
            if(isset($quote['severity']) && $quote['severity'] == "ERROR"){
                return [];
            }
            $compiledQuotes['serviceType'] = 'xpoltl+HAT+';
            $title = $srvcTitle ?? $quote['Title'] ?? '';
            $address['city'] = $quote['address']['cityName'] ?? '';
            $address['state'] = $quote['address']['stateCd'] ?? '';
            $address['zipCode'] = $quote['address']['postalCd'] ?? '';
            $distance = $quote['distance']['text'] ?? '0 mi';
            $phoneNumber = $quote['custServicePhoneNbr'] ?? '';

            $compiledQuotes['serviceDesc'] = Functions::getHATTitle($title, $address, $distance, $phoneNumber);
            $compiledQuotes['totalNetCharge']['Amount'] = Functions::getHATPrice($quote['totalNetCharge'], $quoteSettings['hold_at_terminal_price'] ?? 0);
            $compiledQuotes['deliveryTimestamp'] = $quote['deliveryDate'] ?? '';
            $compiledQuotes['totalTransitTimeInDays'] = $quote['totalTransitTimeInDays'] ?? '';
            $compiledQuotes['transitTime'] = $quote['transitTime'] ?? '';
            $compiledQuotes['transitDays'] = $quote['transitDays'] ?? '';
        }

        return $compiledQuotes;
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

    function getPrice($price, $hatPrice){
        if((strlen($hatPrice) > 0)) {
            $symbolicHATFee = strpos($hatPrice, '%') ? '%' : '';
            $hatPrice = (float)$hatPrice ?? 0;
            if ($symbolicHATFee === '%') {
                $hatPrice = $hatPrice / 100 * $price;
                $price = $price + $hatPrice;
            } else {
                $price = $price + $hatPrice;
            }
        }
        
        return $price;
    }

    function netCharge($netCharge){
        $amount = 0;
        foreach ($netCharge as $charge){
            if(is_array($charge)){
                    if(isset($charge['currency']) && $charge['currency'] === 'USD'){
                        $amount = $charge[0] ?? 0;
                        break;
                    }

            }else{
                $amount = $netCharge[0] ?? 0;
                break;
            }
        }
        return $amount;
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
