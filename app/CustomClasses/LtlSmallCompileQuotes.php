<?php

namespace App\CustomClasses;

use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;
use Illuminate\Support\Facades\Log;


class LtlSmallCompileQuotes
{
    public function compileQuotes($quotes, $connectionSettings, $residential, $quotesFromWs, $requestArr)
    {
        //print_r($connectionSettings); exit;
        $quoteSettings = $connectionSettings['ltl-quotes']['quote_settings'] ?? [];
        $lgQuotesAlways =
            (isset($quoteSettings['alwaysLiftGateDelivery']) && $quoteSettings['alwaysLiftGateDelivery']);

        $parcel = $ltl = $ltlLG = $upsLtlLG = $upsLtl = $ownArrangement = [];
        $quotesCarrier = [];
        //print_r($quotes); exit;
        foreach ($quotes as $quote) {
            if (!empty($quote) && $quote['code'] !== 'own_arrangement' && $quote['code'] !== 'freernlltl') {
                /*if(strpos($quote['code'], 'parcel_12ups') !== false){
                    $quotesCarrier['parcel']['simple'][] = $quote;
                }else */
                if (strpos($quote['code'], 'parcel_12') !== false) {
                    if (strpos($quote['code'], 'parcel_12ups') !== false) {
                        $alwaysResi = (isset($requestArr['carriers']['upsSmall']['api']['ups_small_pkg_resid_delivery']) && $requestArr['carriers']['upsSmall']['api']['ups_small_pkg_resid_delivery'] == 'yes');
                        $quote['alwaysResi'] = $alwaysResi;
                        $quote['isResi'] = $residential['upsSmall'] == 'Y';
                    } else if (strpos($quote['code'], 'parcel_12fd') !== false) {
                        $alwaysResi = (isset($requestArr['carriers']['fedexSmall']['api']['residentials_delivery']) && $requestArr['carriers']['fedexSmall']['api']['residentials_delivery'] == 'yes');
                        $quote['alwaysResi'] = $alwaysResi;
                        $quote['isResi'] = $residential['fedexSmall'] == 'Y';
                    } else if (strpos($quote['code'], 'parcel_12uniship') !== false) {
                        $alwaysResi = (isset($requestArr['carriers']['unishippersSmall']['api']['residentials_delivery']) && $requestArr['carriers']['unishippersSmall']['api']['residentials_delivery'] == 'yes');
                        $quote['alwaysResi'] = $alwaysResi;
                        $quote['isResi'] = $residential['unishippersSmall'] == 'Y';
                    } else {
                        $alwaysResi = (isset($requestArr['carriers']['wweSmall']['api']['residentials_delivery']) && $requestArr['carriers']['wweSmall']['api']['residentials_delivery'] == 'yes');
                        $quote['alwaysResi'] = $alwaysResi;
                        $quote['isResi'] = $residential['wweSmall'] == 'Y';
                    }
                    $quotesCarrier['parcel'][] = $quote;
                } else if (strpos($quote['code'], 'upsltl') !== false) {

                    $alwaysResi = (isset($requestArr['carriers']['upsLTL']['api']['accessorial']['residentialDelivery']) && $requestArr['carriers']['upsLTL']['api']['accessorial']['residentialDelivery'] == 'Y');
                    $quote['alwaysResi'] = $alwaysResi;
                    $quote['isResi'] = $residential['upsLtl'] == 'Y';
                    $quote['alwaysLG'] = isset($connectionSettings['ups-ltl']['quote_settings']['alwaysLiftGateDelivery']) && $connectionSettings['ups-ltl']['quote_settings']['alwaysLiftGateDelivery'];

                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['ups']['LG'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['ups']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'fedexltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = $residential['fedexLtl'] == 'Y';
                    $quote['alwaysLG'] = isset($connectionSettings['fedex-ltl']['quote_settings']['alwaysLiftGateDelivery']) && $connectionSettings['fedex-ltl']['quote_settings']['alwaysLiftGateDelivery'];
                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['fedex']['LG'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['fedex']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'gtzltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = $residential['gtzLtl'] == 'Y';
                    $quote['alwaysLG'] = isset($connectionSettings['gtz-ltl']['quote_settings']['alwaysLiftGateDelivery']) && $connectionSettings['gtz-ltl']['quote_settings']['alwaysLiftGateDelivery'];
                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['gtz']['LG'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['gtz']['simple'][] = $quote;
                    }

                } else if (strpos($quote['code'], 'yrcltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = isset($residential['yrcLtl']) && $residential['yrcLtl'] == 'Y';
                    $quote['alwaysLG'] = isset($connectionSettings['yrc-ltl']['quote_settings']['alwaysLiftGateDelivery']) && $connectionSettings['yrc-ltl']['quote_settings']['alwaysLiftGateDelivery'];

                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['yrc']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+HAT') !== false) {
                        $quotesCarrier['ltl']['yrc']['HAT'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['yrc']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'SouthEastern') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = isset($residential['SouthEastern']) && $residential['SouthEastern'] == 'Y';
                    $quote['alwaysLG'] = isset($connectionSettings['southeastern-ltl']['quote_settings']['alwaysLiftGateDelivery']) && $connectionSettings['southeastern-ltl']['quote_settings']['alwaysLiftGateDelivery'];

                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['SouthEastern']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+HAT') !== false) {
                        $quotesCarrier['ltl']['SouthEastern']['HAT'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['SouthEastern']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'cltl') !== false) {
                    $quote['alwaysResi'] = false;
                    $quote['isResi'] = false;
                    $quote['alwaysLG'] = false;
                    if (isset($connectionSettings['gtz-ltl']['quote_settings']['shipping_service']) && $connectionSettings['gtz-ltl']['quote_settings']['shipping_service'] == 'standard_ltl') {
                        $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                        $quote['isResi'] = $residential['gtzLtl'] == 'Y';
                        $quote['alwaysLG'] = isset($connectionSettings['gtz-ltl']['quote_settings']['alwaysLiftGateDelivery']) && $connectionSettings['gtz-ltl']['quote_settings']['alwaysLiftGateDelivery'];
                    }
                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['cerasis']['LG'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['cerasis']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'xpoltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = $residential['xpoLtl'] == 'Y';
                    $quote['alwaysLG'] = isset($connectionSettings['xpo-ltl']['quote_settings']['alwaysLiftGateDelivery']) && $connectionSettings['xpo-ltl']['quote_settings']['alwaysLiftGateDelivery'];
                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['xpo']['LG'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['xpo']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'odflltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = $residential['odflLtl'] == 'Y';
                    $quote['alwaysLG'] = isset($connectionSettings['odfl-ltl']['quote_settings']['alwaysLiftGateDelivery']) && $connectionSettings['odfl-ltl']['quote_settings']['alwaysLiftGateDelivery'];
                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['odfl']['LG'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['odfl']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'rnlltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = isset($residential['rnlLtl']) && $residential['rnlLtl'] == 'Y';
                    $quote['alwaysLG'] = isset($connectionSettings['rl-ltl']['quote_settings']['alwaysLiftGateDelivery']) && $connectionSettings['rl-ltl']['quote_settings']['alwaysLiftGateDelivery'];
                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['rnl']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+HAT') !== false) {
                        $quotesCarrier['ltl']['rnl']['HAT'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['rnl']['simple'][] = $quote;
                    }
                }
                else if (strpos($quote['code'], 'dayrossltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = isset($residential['dayrossLtl']) && $residential['dayrossLtl'] == 'Y';
                    $quote['alwaysLG'] = isset($connectionSettings['yrc-ltl']['quote_settings']['alwaysLiftGateDelivery']) && $connectionSettings['dayross-ltl']['quote_settings']['alwaysLiftGateDelivery'];
                
                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['dayross']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+HAT') !== false) {
                        $quotesCarrier['ltl']['dayross']['HAT'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['dayross']['simple'][] = $quote;
                    }
                }
                else if(strpos($quote['code'], 'fqltl') !== false){
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = isset($residential['freightQuoteLtl']) && $residential['freightQuoteLtl'] == 'Y';
                    $quote['alwaysLG'] = isset($connectionSettings['freightquote-ltl']['quote_settings']['alwaysLiftGateDelivery']) && $connectionSettings['freightquote-ltl']['quote_settings']['alwaysLiftGateDelivery'];
                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['fq']['LG'][] = $quote;
                    } else if(strpos($quote['code'], '+HAT') !== false){
                        $quotesCarrier['ltl']['fq']['HAT'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['fq']['simple'][] = $quote;
                    }
                }
                else if(strpos($quote['code'], 'estesltl') !== false){
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = isset($residential['estesLtl']) && $residential['estesLtl'] == 'Y';
                    $quote['alwaysLG'] = isset($connectionSettings['estes-ltl']['quote_settings']['alwaysLiftGateDelivery']) && $connectionSettings['estes-ltl']['quote_settings']['alwaysLiftGateDelivery'];
                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['estes']['LG'][] = $quote;
                    } else if(strpos($quote['code'], '+HAT') !== false){
                        $quotesCarrier['ltl']['estes']['HAT'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['estes']['simple'][] = $quote;
                    }
                }
                else if(strpos($quote['code'], 'saialtl') !== false){
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = isset($residential['saiaLtl']) && $residential['saiaLtl'] == 'Y';
                    $quote['alwaysLG'] = isset($connectionSettings['saia-ltl']['quote_settings']['alwaysLiftGateDelivery']) && $connectionSettings['saia-ltl']['quote_settings']['alwaysLiftGateDelivery'];
                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['saia']['LG'][] = $quote;
                    } else if(strpos($quote['code'], '+HAT') !== false){
                        $quotesCarrier['ltl']['saia']['HAT'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['saia']['simple'][] = $quote;
                    }
                }
                else {
                    $alwaysResi = (isset($requestArr['carriers']['wweLTL']['api']['speed_freight_residential_delivery']) && $requestArr['carriers']['wweLTL']['api']['speed_freight_residential_delivery'] == 'Y');
                    $quote['alwaysResi'] = $alwaysResi;
                    $quote['isResi'] = isset($residential['wweLtl']) && $residential['wweLtl'] == 'Y';
                    $quote['alwaysLG'] = isset($connectionSettings['ltl-quotes']['quote_settings']['alwaysLiftGateDelivery']) && $connectionSettings['ltl-quotes']['quote_settings']['alwaysLiftGateDelivery'];
                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['wwe']['LG'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['wwe']['simple'][] = $quote;
                    }
                }
            } else if ($quote['code'] === 'own_arrangement' || $quote['code'] === 'freernlltl') {
                $ownArrangement[] = $quote;
            }
        }
        $quotesCarrierNew = [];
        $isParcel = $isltl = false;
        foreach ($quotesCarrier as $ltlPacel => $quotesCar) {
            if ($ltlPacel === 'parcel') {
                $keys = array_column($quotesCar, 'rate');
                array_multisort($keys, SORT_ASC, $quotesCar);
                $quotesCarrierNew[$ltlPacel][] = array_values($quotesCar)[0];
                $isParcel = true;
            } else {
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
        if (!$isParcel && !$isltl) {
            return ['checkoutQuotes' => $quotes];
        }
        $isLG = count($ltlLG) > 0;
        $parcel = $quotesCarrierNew['parcel'][0] ?? [];
        //print_r($quotesCarrierNew['ltl']); exit;
        foreach ($quotesCarrierNew['ltl'] as $ltlQuote) {
            foreach ($ltlQuote as $simpleLg => $ltlQuot) {
                $ltlQuot = $ltlQuot[0] ?? $ltlQuot;
                $rCode = ($parcel['isResi'] ?? $ltlQuot['isResi'] ?? $parcel['alwaysResi'] ?? $ltlQuot['alwaysResi']) ? '+R' : '';
                if ($simpleLg === 'simple') {
                    $rtitle = ($parcel['isResi'] ?? $ltlQuot['isResi']) ? Constant::RESI_LABEL : '';
                    $newQuotes[] = [
                        'code' => 'multi' . $rCode,
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                } else if ($simpleLg === 'LG') {
                    if (isset($ltlQuot['alwaysLG']) && $ltlQuot['alwaysLG']) {
                        $rtitle = ($parcel['isResi'] ?? $ltlQuot['isResi']) ? Constant::RESI_LABEL : '';
                    } else {
                        $rtitle = ($parcel['isResi'] ?? $ltlQuot['isResi']) ? Constant::RESI_LIFT_LABEL : Constant::LIFT_LABEL;
                    }
                    $newQuotes[] = [
                        'code' => 'multi' . $rCode . '+LG',
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                } else {
                    $title = explode('|', $ltlQuot['title']);
                    unset($title[0]);
                    $title = implode('|', $title);
                    $newQuotes[] = [
                        'code' => 'multi' . '+HAT',
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight |' . $title
                    ];
                }
            }
        }        
        $indexes = $this->indexesOfQuotes($quotesFromWs);
        $multiShipmentQuotes = $this->createOrderWidget($quotesCarrierNew, $indexes);
        if (!empty($ownArrangement)) {
            foreach ($ownArrangement as $quote) {
                $newQuotes[count($newQuotes)] = $quote;
            }

        }
        $resp = [
            'multiShipmentQuotes' => $multiShipmentQuotes,
            'checkoutQuotes' => $newQuotes
        ];
        return $resp;
    }

    private function createOrderWidget($quotesDetail, $indexes)
    {
        //print_r($quotesDetail); exit;
        $parcel = $quotesDetail['parcel'][0] ?? [];
        $multiShipments = [];
        $count = 0;
        foreach ($quotesDetail['ltl'] as $key => $quotes) {     
            if (isset($quotes['simple'][0])) {
                $multiShipments[$count]['simple'][$indexes['ltl'][0]] = $quotes['simple'][0];
                $multiShipments[$count]['simple'][$indexes['small'][0]] = $parcel;
                $count++;
            }
            if (isset($quotes['LG'][0])) {
                $multiShipments[$count]['liftgate'][$indexes['ltl'][0]] = $quotes['LG'][0];
                $multiShipments[$count]['liftgate'][$indexes['small'][0]] = $parcel;
                $count++;
            }
            if (isset($quotes['HAT'][0])) {
                $multiShipments[$count]['hat'][$indexes['ltl'][0]] = $quotes['HAT'][0];
                $multiShipments[$count]['hat'][$indexes['small'][0]] = $parcel;
                $count++;
            }
        }
        return $multiShipments;
    }


    private function indexesOfQuotes($quotes)
    {
        $small = $ltl = [];
        $ltlQuotes = $quotes['wweLTL'] ?? $quotes['upsLTL'] ?? $quotes['fedexLTL'] ?? $quotes['globalTranz'] ?? $quotes['cerasis'] ?? $quotes['xpoLogistics'] ?? $quotes['rnl'] ?? $quotes['yrc'] ?? $quotes['freightQuote'] ?? $quotes['estes'] ?? $quotes['dayross'] ?? $quotes['odfl4me'] ?? $quotes['saia'] ?? $quotes['southeastern'] ?? [];
        foreach ($ltlQuotes as $key => $quote) {
            $ltl[] = $key;
        }

        $smallQuotes = $quotes['wweSmall'] ?? $quotes['upsSmall'] ?? $quotes['fedexSmall'] ?? $quotes['unishippersSmall'] ?? [];
        foreach ($smallQuotes as $key => $quote) {
            $small[] = $key;
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
        if (!$hasSmallLtl) {
            return false;
        }
        foreach ($carriers as $carrier) {
            $output = $this->multi_unique($carrier['originAddress']);
            if (count($output) > 1) {
                $isMultiOrign = true;
                break;
            }
        }
        return $isMultiOrign;
    }

    private function multi_unique($src)
    {
        $output = array_map("unserialize",
            array_unique(array_map("serialize", $src)));
        return $output;
    }

    private function requestContainSmallLlt($carriers, $quotes)
    {
        $smallCarriers = ['wweSmall', 'upsSmall', 'fedexSmall', 'unishippersSmall'];
        $ltlCarriers = ['wweLTL', 'upsLTL', 'fedexLTL', 'globalTranz', 'cerasis', 'xpoLogistics', 'rnl', 'yrc', 'freightQuote', 'estes', 'dayross', 'odfl4me', 'saia', 'southeastern'];
        $ltl = $small = false;
        foreach ($smallCarriers as $carName) {
            if (isset($carriers[$carName]) && !$small) {
                foreach ($quotes[$carName] as $quote) {
                    if (!isset($quote['severity'])) {
                        $small = true;
                        break 2;
                    }
                }
            }
        }
        foreach ($ltlCarriers as $carName){
            if(isset($carriers[$carName]) && !$ltl){
                foreach ($quotes[$carName] as $quote){
                    $dayRossLtlError = $carName === 'dayross' && isset($quote['q']['soapBody']['soapFault']);
                    if(!isset($quote['severity']) || !$dayRossLtlError){
                        $ltl = true;
                        break 2;
                    }
                }
            }
        }
        return $ltl && $small;
    }
}
