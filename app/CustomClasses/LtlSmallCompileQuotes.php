<?php

namespace App\CustomClasses;

use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;
use Illuminate\Support\Facades\Log;


class LtlSmallCompileQuotes{
    public function compileQuotes($quotes, $connectionSettings,  $residential, $quotesFromWs, $requestArr)
    {
        $quoteSettings = $connectionSettings['ltl-quotes']['quote_settings'] ?? [];
        $lgQuotesAlways =
            (isset($quoteSettings['alwaysLiftGateDelivery']) && $quoteSettings['alwaysLiftGateDelivery']);

        $parcel = $ltl = $ltlLG = $upsLtlLG = $upsLtl = $ownArrangement =  [];
        $quotesCarrier = [];
        //print_r($quotes); exit;
        foreach ($quotes as $quote){
            if(!empty($quote) && $quote['code'] !== 'own_arrangement') {
                /*if(strpos($quote['code'], 'parcel_12ups') !== false){
                    $quotesCarrier['parcel']['simple'][] = $quote;
                }else */
                if (strpos($quote['code'], 'parcel_12') !== false) {
                    if(strpos($quote['code'], 'parcel_12ups') !== false){
                        $alwaysResi = (isset($requestArr['carriers']['upsSmall']['api']['ups_small_pkg_resid_delivery']) && $requestArr['carriers']['upsSmall']['api']['ups_small_pkg_resid_delivery'] == 'yes');
                        $quote['alwaysResi'] = $alwaysResi;
                        $quote['isResi'] = $residential['upsSmall'] == 'Y';
                    }else{
                        $alwaysResi = (isset($requestArr['carriers']['wweSmall']['api']['residentials_delivery']) && $requestArr['carriers']['wweSmall']['api']['residentials_delivery'] == 'yes');
                        $quote['alwaysResi'] = $alwaysResi;
                        $quote['isResi'] = $residential['wweSmall'] == 'Y';
                    }
                    $quotesCarrier['parcel'][] = $quote;
                } else if(strpos($quote['code'], 'upsltl') !== false){

                    $alwaysResi = (isset($requestArr['carriers']['upsLTL']['api']['accessorial']['residentialDelivery']) && $requestArr['carriers']['upsLTL']['api']['accessorial']['residentialDelivery'] == 'Y');
                    $quote['alwaysResi'] = $alwaysResi;
                    $quote['isResi'] = $residential['upsLtl'] == 'Y';

                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['ups']['LG'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['ups']['simple'][] = $quote;
                    }
                }
                else if(strpos($quote['code'], 'fedexltl') !== false){

                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = $residential['fedexLtl'] == 'Y';
//dd($quote['isResi'], $quote['alwaysResi']);
                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['fedex']['LG'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['fedex']['simple'][] = $quote;
                    }
                }
                else {
                    $alwaysResi = (isset($requestArr['carriers']['wweLTL']['api']['speed_freight_residential_delivery']) && $requestArr['carriers']['wweLTL']['api']['speed_freight_residential_delivery'] == 'Y');
                    $quote['alwaysResi'] = $alwaysResi;
                    $quote['isResi'] = $residential['wweLtl'] == 'Y';
                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['wwe']['LG'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['wwe']['simple'][] = $quote;
                    }
                }
            }else if($quote['code'] === 'own_arrangement'){
                $ownArrangement = $quote;
            }
        }
        $quotesCarrierNew = [];
        $isParcel = $isltl = false;
        foreach ($quotesCarrier as $ltlPacel => $quotesCar){
            if($ltlPacel === 'parcel'){
                $keys = array_column($quotesCar, 'rate');
                array_multisort($keys, SORT_ASC, $quotesCar);
                $quotesCarrierNew[$ltlPacel][] = array_values($quotesCar)[0];
                $isParcel = true;
            }else {
                foreach ($quotesCar as $carName => $quote) {
                    foreach ($quote as $simpleLg => $quot) {
                        $keys = array_column($quot, 'rate');
                        array_multisort($keys, SORT_ASC, $quot);
                        $quotesCarrierNew[$ltlPacel][$carName][$simpleLg][] = array_values($quot)[0];
                        $isltl = true;
                    }
                }
            }
        }
        if(!$isParcel && !$isltl){
            return ['checkoutQuotes' => $quotes];
        }
        $isLG = count($ltlLG) > 0;
        $parcel = $quotesCarrierNew['parcel'][0] ?? [];

        foreach ($quotesCarrierNew['ltl'] as $ltlQuote){
            foreach ($ltlQuote as $simpleLg => $ltlQuot){
                $ltlQuot = $ltlQuot[0] ?? $ltlQuot;
                $rCode = ($parcel['isResi'] || $ltlQuot['isResi'] || $parcel['alwaysResi'] || $ltlQuot['alwaysResi'] ) ? '+R':'';
                if($simpleLg === 'simple') {
                    $rtitle = ($parcel['isResi'] || $ltlQuot['isResi'])  ? ' ( R )':'';
                    $newQuotes[] = [
                        'code' => 'multi' . $rCode,
                        'rate' => $parcel['rate'] + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                }else{
                    $rtitle = ($parcel['isResi'] || $ltlQuot['isResi']) ? ' ( R | L )' : ' ( L )';
                    $newQuotes[] = [
                        'code' => 'multi'.$rCode.'+LG',
                        'rate' => $parcel['rate'] + $ltlQuot['rate'],
                        'title' => 'Freight'.$rtitle
                    ];
                }
            }
        }
        $indexes = $this->indexesOfQuotes($quotesFromWs);
        $multiShipmentQuotes = $this->createOrderWidget($quotesCarrierNew, $indexes);
        if(!empty($ownArrangement)){
            $newQuotes[count($newQuotes)] = $ownArrangement;
        }
        $resp = [
            'multiShipmentQuotes' => $multiShipmentQuotes,
            'checkoutQuotes' => $newQuotes
        ];
        return $resp;
    }

    private function createOrderWidget($quotesDetail, $indexes)
    {
        $parcel = $quotesDetail['parcel'][0] ?? [];
        $multiShipments = [];
        $count = 0;
        foreach ($quotesDetail['ltl'] as $key=> $quotes)
        {
            if(isset($quotes['simple'][0])){
                $multiShipments[$count]['simple'][$indexes['ltl'][0]] = $quotes['simple'][0];
                $multiShipments[$count]['simple'][$indexes['small'][0]] = $parcel;
                $count++;
            }
            if(isset($quotes['LG'][0])){
                $multiShipments[$count]['liftgate'][$indexes['ltl'][0]] = $quotes['LG'][0];
                $multiShipments[$count]['liftgate'][$indexes['small'][0]] = $parcel;
                $count++;
            }

        }
        return $multiShipments;
    }


    private function indexesOfQuotes($quotes){
        $small = $ltl = [];
        $ltlQuotes = $quotes['wweLTL'] ?? $quotes['upsLTL'] ?? $quotes['fedexLTL'] ?? [];
        foreach ($ltlQuotes as $key => $quote) {
            $ltl[] = $key;
        }

        $smallQuotes = $quotes['wweSmall'] ?? $quotes['upsSmall'] ?? [];
        foreach ($smallQuotes as $key=>$quote){
            $small[]=$key;
        }
        $indexes['small'] = $small;
        $indexes['ltl'] = $ltl;
        return $indexes;
    }

    function checkIsRequestMiltiShipment($request, $quotes)
    {
        $carriers = $request['carriers'] ?? [];
        $isMultiOrign = false;
        $hasSmallLtl = $this->requestContainSmallLlt($carriers, $quotes);
        if(!$hasSmallLtl){
            return false;
        }
        foreach ($carriers as $carrier){
            $output= $this->multi_unique($carrier['originAddress']);
            if(count($output) > 1) {
                $isMultiOrign = true;
                break;
            }
        }
        return $isMultiOrign;
    }

    private function multi_unique($src){
        $output = array_map("unserialize",
            array_unique(array_map("serialize", $src)));
        return $output;
    }

    private function requestContainSmallLlt($carriers, $quotes){
        $smallCarriers = ['wweSmall','upsSmall'];
        $ltlCarriers = ['wweLTL','upsLTL', 'fedexLTL'];
        $ltl = $small = false;
        foreach ($smallCarriers as $carName){
            if(isset($carriers[$carName]) && !$small){
                foreach ($quotes[$carName] as $quote){
                    if(!isset($quote['severity'])){
                        $small = true;
                        break 2;
                    }
                }
            }
        }
        foreach ($ltlCarriers as $carName){
            if(isset($carriers[$carName]) && !$ltl){
                foreach ($quotes[$carName] as $quote){
                    if(!isset($quote['severity'])){
                        $ltl = true;
                        break 2;
                    }
                }
            }
        }
        return $ltl && $small;
    }
}
