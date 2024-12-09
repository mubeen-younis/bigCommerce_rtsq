<?php

namespace App\CustomClasses;

use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;
use Illuminate\Support\Facades\Log;


class LtlSmallCompileQuotes
{
    public function __construct($suppressParcelRates = [])
    {
        $this->SuppressParcelRates = $suppressParcelRates;
    }

    public function compileQuotes($quotes, $connectionSettings, $residential, $quotesFromWs, $requestArr, $storeId)
    {
        $quoteSettings = $connectionSettings['ltl-quotes']['quote_settings'] ?? [];
        $lgQuotesAlways =
            (isset($quoteSettings['alwaysLiftGateDelivery']) && $quoteSettings['alwaysLiftGateDelivery']);

        $parcel = $ltl = $ltlLG = $upsLtlLG = $upsLtl = $ownArrangement = [];
        $quotesCarrier = [];

        foreach ($quotes as $quote) {
            if (!empty($quote) && $quote['code'] !== 'own_arrangement' && $quote['code'] !== 'freernlltl') {
                if (strpos($quote['code'], 'parcel_12') !== false) {
                    if (strpos($quote['code'], 'parcel_12ups') !== false) {
                        $alwaysResi = (isset($requestArr['carriers']['upsSmall']['api']['ups_small_pkg_resid_delivery']) && $requestArr['carriers']['upsSmall']['api']['ups_small_pkg_resid_delivery'] == 'yes');
                        $quote['alwaysResi'] = $alwaysResi;
                        $quote['isResi'] = $residential['upsSmall'] == 'Y';
                    } else if (strpos($quote['code'], 'parcel_12Purolator') !== false) {
                        $alwaysResi = (isset($requestArr['carriers']['purolator']['api']['purolator_pkg_resid_delivery']) && $requestArr['carriers']['purolator']['api']['purolator_pkg_resid_delivery'] == 'yes');
                        $quote['alwaysResi'] = $alwaysResi;
                        $quote['isResi'] = $residential['purolatorSmall'] == 'Y';
                    } else if (strpos($quote['code'], 'parcel_12fd') !== false) {
                        $alwaysResi = (isset($requestArr['carriers']['fedexSmall']['api']['residentials_delivery']) && $requestArr['carriers']['fedexSmall']['api']['residentials_delivery'] == 'yes');
                        $quote['alwaysResi'] = $alwaysResi;
                        $quote['isResi'] = $residential['fedexSmall'] == 'Y';
                    } else if (strpos($quote['code'], 'parcel_12uniship_new') !== false) {
                        $alwaysResi = (isset($requestArr['carriers']['wweSmallN']['api']['residentials_delivery']) && $requestArr['carriers']['wweSmallN']['api']['residentials_delivery'] == 'yes');
                        $quote['alwaysResi'] = $alwaysResi;
                        $quote['isResi'] = $residential['unishippersSmallNewApi'] == 'Y';
                    } else if (strpos($quote['code'], 'parcel_12uniship') !== false) {
                        $alwaysResi = (isset($requestArr['carriers']['unishippersSmall']['api']['residentials_delivery']) && $requestArr['carriers']['unishippersSmall']['api']['residentials_delivery'] == 'yes');
                        $quote['alwaysResi'] = $alwaysResi;
                        $quote['isResi'] = $residential['unishippersSmall'] == 'Y';
                    } else if (strpos($quote['code'], 'parcel_12usps') !== false) {
                        $alwaysResi = (isset($requestArr['carriers']['uspsSmall']['api']['residentials_delivery']) && $requestArr['carriers']['uspsSmall']['api']['residential_delivery'] == 'yes');
                        $quote['alwaysResi'] = $alwaysResi;
                        $quote['isResi'] = $residential['uspsSmall'] == 'Y';
                    } else if (strpos($quote['code'], 'parcel_12shipEng') !== false) {
                        $alwaysResi = (isset($requestArr['carriers']['shipEngine']['api']['residentials_delivery']) && $requestArr['carriers']['shipEngine']['api']['residential_delivery'] == 'yes');
                        $quote['alwaysResi'] = $alwaysResi;
                        $quote['isResi'] = $residential['shipEngine'] == 'Y';
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
                    $quote['isLG'] = isset($connectionSettings['ups-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['ups-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isNBD'] = isset($connectionSettings['ups-ltl']['quote_settings']['offer_notify_as_option']) && $connectionSettings['ups-ltl']['quote_settings']['offer_notify_as_option'];
                    $quoteSettings = isset($connectionSettings['ups-ltl']['quote_settings']) ? $connectionSettings['ups-ltl']['quote_settings'] : [];

                    if (strpos($quote['code'], '+LG+NBD') !== false) {
                        $quotesCarrier['ltl']['ups']['LGNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+NBD') !== false) {
                        $quotesCarrier['ltl']['ups']['NBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['ups']['LG'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['ups']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'priority1ltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = $residential['priority1Ltl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['priority-one-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['priority-one-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isNBD'] = isset($connectionSettings['priority-one-ltl']['quote_settings']['offer_notify_as_option']) && $connectionSettings['priority-one-ltl']['quote_settings']['offer_notify_as_option'];
                    $quoteSettings = isset($connectionSettings['priority-one-ltl']['quote_settings']) ? $connectionSettings['priority-one-ltl']['quote_settings'] : [];

                    if (strpos($quote['code'], '+LG+NBD') !== false) {
                        $quotesCarrier['ltl']['priority1']['LGNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+NBD') !== false) {
                        $quotesCarrier['ltl']['priority1']['NBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['priority1']['LG'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['priority1']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'fedexltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = $residential['fedexLtl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['fedex-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['fedex-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isNBD'] = isset($connectionSettings['fedex-ltl']['quote_settings']['offer_notify_as_option']) && $connectionSettings['fedex-ltl']['quote_settings']['offer_notify_as_option'];
                    $quote['isLAD'] = isset($connectionSettings['fedex-ltl']['quote_settings']['offer_limited_access_delivery']) && $connectionSettings['fedex-ltl']['quote_settings']['offer_limited_access_delivery'];
                    $quoteSettings = isset($connectionSettings['fedex-ltl']['quote_settings']) ? $connectionSettings['fedex-ltl']['quote_settings'] : [];

                    if (strpos($quote['code'], '+LG+LAD+NBD') !== false) {
                        $quotesCarrier['ltl']['fedex']['LGLADNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+NBD') !== false) {
                        $quotesCarrier['ltl']['fedex']['LGNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD+NBD') !== false) {
                        $quotesCarrier['ltl']['fedex']['LADNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+LAD') !== false) {
                        $quotesCarrier['ltl']['fedex']['LGLAD'][] = $quote;
                    } else if (strpos($quote['code'], '+NBD') !== false) {
                        $quotesCarrier['ltl']['fedex']['NBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['fedex']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD') !== false) {
                        $quotesCarrier['ltl']['fedex']['LAD'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['fedex']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'gtzltl_new') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = $residential['gtzLtl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['gtz-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['gtz-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isNBD'] = isset($connectionSettings['gtz-ltl']['quote_settings']['offer_notify_as_option']) && $connectionSettings['gtz-ltl']['quote_settings']['offer_notify_as_option'];
                    $quoteSettings = isset($connectionSettings['gtz-ltl']['quote_settings']) ? $connectionSettings['gtz-ltl']['quote_settings'] : [];
                    $quote['isID'] = isset($connectionSettings['gtz-ltl']['quote_settings']['offer_inside_delivery']) && $connectionSettings['gtz-ltl']['quote_settings']['offer_inside_delivery'];
                    $quote['isLAD'] = isset($connectionSettings['gtz-ltl']['quote_settings']['offer_limited_access_delivery']) && $connectionSettings['gtz-ltl']['quote_settings']['offer_limited_access_delivery'];

                    if (strpos($quote['code'], '+LG+ID+NBD') !== false) {
                        $quotesCarrier['ltl']['gtznew']['LGIDNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+ID+LAD') !== false) {
                        $quotesCarrier['ltl']['gtznew']['LGIDLAD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+NBD') !== false) {
                        $quotesCarrier['ltl']['gtznew']['LGNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+ID+NBD') !== false) {
                        $quotesCarrier['ltl']['gtznew']['IDNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+LAD') !== false) {
                        $quotesCarrier['ltl']['gtznew']['LGLAD'][] = $quote;
                    } else if (strpos($quote['code'], '+ID+LAD') !== false) {
                        $quotesCarrier['ltl']['gtznew']['IDLAD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+ID') !== false) {
                        $quotesCarrier['ltl']['gtznew']['LGID'][] = $quote;
                    } else if (strpos($quote['code'], '+NBD') !== false) {
                        $quotesCarrier['ltl']['gtznew']['NBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['gtznew']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD') !== false) {
                        $quotesCarrier['ltl']['gtznew']['LAD'][] = $quote;
                    } else if (strpos($quote['code'], '+ID') !== false) {
                        $quotesCarrier['ltl']['gtznew']['ID'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['gtznew']['simple'][] = $quote;
                    }

                } else if (strpos($quote['code'], 'gtzltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = $residential['gtzLtl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['gtz-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['gtz-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isNBD'] = isset($connectionSettings['gtz-ltl']['quote_settings']['offer_notify_as_option']) && $connectionSettings['gtz-ltl']['quote_settings']['offer_notify_as_option'];
                    $quote['isLAD'] = isset($connectionSettings['gtz-ltl']['quote_settings']['offer_limited_access_delivery']) && $connectionSettings['gtz-ltl']['quote_settings']['offer_limited_access_delivery'];
                    $quoteSettings = isset($connectionSettings['gtz-ltl']['quote_settings']) ? $connectionSettings['gtz-ltl']['quote_settings'] : [];

                    if (strpos($quote['code'], '+LG+LAD+NBD') !== false) {
                        $quotesCarrier['ltl']['gtz']['LGLADNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+NBD') !== false) {
                        $quotesCarrier['ltl']['gtz']['LGNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD+NBD') !== false) {
                        $quotesCarrier['ltl']['gtz']['LADNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+LAD') !== false) {
                        $quotesCarrier['ltl']['gtz']['LGLAD'][] = $quote;
                    } else if (strpos($quote['code'], '+NBD') !== false) {
                        $quotesCarrier['ltl']['gtz']['NBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['gtz']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD') !== false) {
                        $quotesCarrier['ltl']['gtz']['LAD'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['gtz']['simple'][] = $quote;
                    }

                } else if (strpos($quote['code'], 'tqlltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = isset($residential['tqlLtl']) && $residential['tqlLtl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['tql-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['tql-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isNBD'] = isset($connectionSettings['tql-ltl']['quote_settings']['offer_notify_as_option']) && $connectionSettings['tql-ltl']['quote_settings']['offer_notify_as_option'];
                    $quoteSettings = isset($connectionSettings['tql-ltl']['quote_settings']) ? $connectionSettings['tql-ltl']['quote_settings'] : [];

                    if (strpos($quote['code'], '+LG+NBD') !== false) {
                        $quotesCarrier['ltl']['tql']['LGNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+NBD') !== false) {
                        $quotesCarrier['ltl']['tql']['NBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['tql']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+HAT') !== false) {
                        $quotesCarrier['ltl']['tql']['HAT'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['tql']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'yrcltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = $residential['yrcLtl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['yrc-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['yrc-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isNBD'] = isset($connectionSettings['yrc-ltl']['quote_settings']['offer_notify_as_option']) && $connectionSettings['yrc-ltl']['quote_settings']['offer_notify_as_option'];
                    $quote['isLAD'] = isset($connectionSettings['yrc-ltl']['quote_settings']['offer_limited_access_delivery']) && $connectionSettings['yrc-ltl']['quote_settings']['offer_limited_access_delivery'];
                    $quoteSettings = isset($connectionSettings['yrc-ltl']['quote_settings']) ? $connectionSettings['yrc-ltl']['quote_settings'] : [];

                    if (strpos($quote['code'], '+LG+LAD+NBD') !== false) {
                        $quotesCarrier['ltl']['yrc']['LGLADNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+NBD') !== false) {
                        $quotesCarrier['ltl']['yrc']['LGNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD+NBD') !== false) {
                        $quotesCarrier['ltl']['yrc']['LADNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+NBD') !== false) {
                        $quotesCarrier['ltl']['yrc']['NBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+LAD') !== false) {
                        $quotesCarrier['ltl']['yrc']['LGLAD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['yrc']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD') !== false) {
                        $quotesCarrier['ltl']['yrc']['LAD'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['yrc']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'cltl') !== false) {
                    $quote['alwaysResi'] = false;
                    $quote['isResi'] = false;
                    if (isset($connectionSettings['gtz-ltl']['quote_settings']['shipping_service']) && $connectionSettings['gtz-ltl']['quote_settings']['shipping_service'] == 'standard_ltl') {
                        $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                        $quote['isResi'] = $residential['gtzLtl'] == 'Y';
                        $quote['isLG'] = isset($connectionSettings['gtz-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['gtz-ltl']['quote_settings']['offerLiftGateDelivery'];
                        $quote['isNBD'] = isset($connectionSettings['gtz-ltl']['quote_settings']['offer_notify_as_option']) && $connectionSettings['gtz-ltl']['quote_settings']['offer_notify_as_option'];
                        $quote['isLAD'] = isset($connectionSettings['gtz-ltl']['quote_settings']['offer_limited_access_delivery']) && $connectionSettings['gtz-ltl']['quote_settings']['offer_limited_access_delivery'];
                        $quoteSettings = isset($connectionSettings['gtz-ltl']['quote_settings']) ? $connectionSettings['gtz-ltl']['quote_settings'] : [];
                    }

                    if (strpos($quote['code'], '+LG+LAD+NBD') !== false) {
                        $quotesCarrier['ltl']['cerasis']['LGLADNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+NBD') !== false) {
                        $quotesCarrier['ltl']['cerasis']['LGNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD+NBD') !== false) {
                        $quotesCarrier['ltl']['cerasis']['LADNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+LAD') !== false) {
                        $quotesCarrier['ltl']['cerasis']['LGLAD'][] = $quote;
                    } else if (strpos($quote['code'], '+NBD') !== false) {
                        $quotesCarrier['ltl']['cerasis']['NBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['cerasis']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD') !== false) {
                        $quotesCarrier['ltl']['cerasis']['LAD'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['cerasis']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'xpoltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = $residential['xpoLtl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['xpo-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['xpo-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isNBD'] = isset($connectionSettings['xpo-ltl']['quote_settings']['offer_notify_as_option']) && $connectionSettings['xpo-ltl']['quote_settings']['offer_notify_as_option'];
                    $quoteSettings = isset($connectionSettings['xpo-ltl']['quote_settings']) ? $connectionSettings['xpo-ltl']['quote_settings'] : [];

                    if (strpos($quote['code'], '+LG+NBD') !== false) {
                        $quotesCarrier['ltl']['xpo']['LGNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+NBD') !== false) {
                        $quotesCarrier['ltl']['xpo']['NBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['xpo']['LG'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['xpo']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'odflltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = $residential['odflLtl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['odfl-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['odfl-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isNBD'] = isset($connectionSettings['odfl-ltl']['quote_settings']['offer_notify_as_option']) && $connectionSettings['odfl-ltl']['quote_settings']['offer_notify_as_option'];
                    $quote['isLAD'] = isset($connectionSettings['odfl-ltl']['quote_settings']['offer_limited_access_delivery']) && $connectionSettings['odfl-ltl']['quote_settings']['offer_limited_access_delivery'];
                    $quoteSettings = isset($connectionSettings['odfl-ltl']['quote_settings']) ? $connectionSettings['odfl-ltl']['quote_settings'] : [];
                    if (strpos($quote['code'], '+LG+LAD+NBD') !== false) {
                        $quotesCarrier['ltl']['odfl']['LGLADNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+NBD') !== false) {
                        $quotesCarrier['ltl']['odfl']['LGNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD+NBD') !== false) {
                        $quotesCarrier['ltl']['odfl']['LADNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+LAD') !== false) {
                        $quotesCarrier['ltl']['odfl']['LGLAD'][] = $quote;
                    } else if (strpos($quote['code'], '+NBD') !== false) {
                        $quotesCarrier['ltl']['odfl']['NBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['odfl']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD') !== false) {
                        $quotesCarrier['ltl']['odfl']['LAD'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['odfl']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'rnlltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = isset($residential['rnlLtl']) && $residential['rnlLtl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['rl-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['rl-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isNBD'] = isset($connectionSettings['rl-ltl']['quote_settings']['offer_notify_as_option']) && $connectionSettings['rl-ltl']['quote_settings']['offer_notify_as_option'];
                    $quote['isID'] = isset($connectionSettings['rl-ltl']['quote_settings']['offer_inside_delivery']) && $connectionSettings['rl-ltl']['quote_settings']['offer_inside_delivery'];
                    $quoteSettings = isset($connectionSettings['rl-ltl']['quote_settings']) ? $connectionSettings['rl-ltl']['quote_settings'] : [];

                    if (strpos($quote['code'], '+LG+ID+NBD') !== false) {
                        $quotesCarrier['ltl']['rnl']['LGIDNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+NBD') !== false) {
                        $quotesCarrier['ltl']['rnl']['LGNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+ID+NBD') !== false) {
                        $quotesCarrier['ltl']['rnl']['IDNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+NBD') !== false) {
                        $quotesCarrier['ltl']['rnl']['NBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+ID') !== false) {
                        $quotesCarrier['ltl']['rnl']['LGID'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['rnl']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+ID') !== false) {
                        $quotesCarrier['ltl']['rnl']['ID'][] = $quote;
                    } else if (strpos($quote['code'], '+HAT') !== false) {
                        $quotesCarrier['ltl']['rnl']['HAT'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['rnl']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'dayrossltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = isset($residential['dayrossLtl']) && $residential['dayrossLtl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['dayross-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['dayross-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quoteSettings = isset($connectionSettings['dayross-ltl']['quote_settings']) ? $connectionSettings['dayross-ltl']['quote_settings'] : [];

                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['dayross']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+HAT') !== false) {
                        $quotesCarrier['ltl']['dayross']['HAT'][] = $quote;
                    } elseif (strpos($quote['code'], Functions::$twoManAptDelAccess) !== false) {
                        $quotesCarrier['ltl']['dayross']['TMDAPD'][] = $quote;
                    } else if (strpos($quote['code'], '+TMD') !== false) {
                        $quotesCarrier['ltl']['dayross']['TMD'][] = $quote;
                    } else if (strpos($quote['code'], '+APD') !== false) {
                        $quotesCarrier['ltl']['dayross']['APD'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['dayross']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'fqltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = isset($residential['freightQuoteLtl']) && $residential['freightQuoteLtl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['freightquote-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['freightquote-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isLAD'] = isset($connectionSettings['freightquote-ltl']['quote_settings']['offer_limited_access_delivery']) && $connectionSettings['freightquote-ltl']['quote_settings']['offer_limited_access_delivery'];
                    $quoteSettings = isset($connectionSettings['freightquote-ltl']['quote_settings']) ? $connectionSettings['freightquote-ltl']['quote_settings'] : [];
                    if (strpos($quote['code'], '+LG+LAD') !== false) {
                        $quotesCarrier['ltl']['fq']['LGLAD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['fq']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD') !== false) {
                        $quotesCarrier['ltl']['fq']['LAD'][] = $quote;
                    } else if (strpos($quote['code'], '+HAT') !== false) {
                        $quotesCarrier['ltl']['fq']['HAT'][] = $quote;
                    } else if (strpos($quote['code'], '+TL') !== false) {
                        $quotesCarrier['ltl']['fq']['TL'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['fq']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'fqchrltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = isset($residential['freightQuoteChrLtl']) && $residential['freightQuoteChrLtl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['freightquote-chr-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['freightquote-chr-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isLAD'] = isset($connectionSettings['freightquote-chr-ltl']['quote_settings']['offer_limited_access_delivery']) && $connectionSettings['freightquote-chr-ltl']['quote_settings']['offer_limited_access_delivery'];
                    $quoteSettings = isset($connectionSettings['freightquote-chr-ltl']['quote_settings']) ? $connectionSettings['freightquote-chr-ltl']['quote_settings'] : [];
                    if (strpos($quote['code'], '+LG+LAD') !== false) {
                        $quotesCarrier['ltl']['fqchr']['LGLAD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['fqchr']['LG'][] = $quote;
                    } else if(strpos($quote['code'], '+HAT') !== false){
                        $quotesCarrier['ltl']['fqchr']['HAT'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD') !== false) {
                        $quotesCarrier['ltl']['fqchr']['LAD'][] = $quote;
                    } else if (strpos($quote['code'], '+TL') !== false) {
                        $quotesCarrier['ltl']['fqchr']['TL'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['fqchr']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'estesltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = isset($residential['estesLtl']) && $residential['estesLtl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['estes-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['estes-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isNBD'] = isset($connectionSettings['estes-ltl']['quote_settings']['offer_notify_as_option']) && $connectionSettings['estes-ltl']['quote_settings']['offer_notify_as_option'];
                    $quoteSettings = isset($connectionSettings['estes-ltl']['quote_settings']) ? $connectionSettings['estes-ltl']['quote_settings'] : [];

                    if (strpos($quote['code'], '+LG+NBD') !== false) {
                        $quotesCarrier['ltl']['estes']['LGNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+NBD') !== false) {
                        $quotesCarrier['ltl']['estes']['NBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['estes']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+HAT') !== false) {
                        $quotesCarrier['ltl']['estes']['HAT'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['estes']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'echoltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = isset($residential['echoLtl']) && $residential['echoLtl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['echo-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['echo-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isNBD'] = isset($connectionSettings['echo-ltl']['quote_settings']['offer_notify_as_option']) && $connectionSettings['echo-ltl']['quote_settings']['offer_notify_as_option'];
                    $quoteSettings = isset($connectionSettings['echo-ltl']['quote_settings']) ? $connectionSettings['echo-ltl']['quote_settings'] : [];

                    if (strpos($quote['code'], '+LG+NBD') !== false) {
                        $quotesCarrier['ltl']['echo']['LGNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+NBD') !== false) {
                        $quotesCarrier['ltl']['echo']['NBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['echo']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+HAT') !== false) {
                        $quotesCarrier['ltl']['echo']['HAT'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['echo']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'saialtl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = isset($residential['saiaLtl']) && $residential['saiaLtl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['saia-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['saia-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isNBD'] = isset($connectionSettings['saia-ltl']['quote_settings']['offer_notify_as_option']) && $connectionSettings['saia-ltl']['quote_settings']['offer_notify_as_option'];
                    $quote['isLAD'] = isset($connectionSettings['saia-ltl']['quote_settings']['offer_limited_access_delivery']) && $connectionSettings['saia-ltl']['quote_settings']['offer_limited_access_delivery'];
                    $quoteSettings = isset($connectionSettings['saia-ltl']['quote_settings']) ? $connectionSettings['saia-ltl']['quote_settings'] : [];

                    if (strpos($quote['code'], '+LG+LAD+NBD') !== false) {
                        $quotesCarrier['ltl']['saia']['LGLADNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+NBD') !== false) {
                        $quotesCarrier['ltl']['saia']['LGNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD+NBD') !== false) {
                        $quotesCarrier['ltl']['saia']['LADNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+LAD') !== false) {
                        $quotesCarrier['ltl']['saia']['LGLAD'][] = $quote;
                    } else if (strpos($quote['code'], '+NBD') !== false) {
                        $quotesCarrier['ltl']['saia']['NBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['saia']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD') !== false) {
                        $quotesCarrier['ltl']['saia']['LAD'][] = $quote;
                    } else if (strpos($quote['code'], '+HAT') !== false) {
                        $quotesCarrier['ltl']['saia']['HAT'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['saia']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'abfltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = isset($residential['abfLtl']) && $residential['abfLtl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['abf-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['abf-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isNBD'] = isset($connectionSettings['abf-ltl']['quote_settings']['offer_notify_as_option']) && $connectionSettings['abf-ltl']['quote_settings']['offer_notify_as_option'];
                    $quote['isLAD'] = isset($connectionSettings['abf-ltl']['quote_settings']['offer_limited_access_delivery']) && $connectionSettings['abf-ltl']['quote_settings']['offer_limited_access_delivery'];
                    $quoteSettings = isset($connectionSettings['abf-ltl']['quote_settings']) ? $connectionSettings['abf-ltl']['quote_settings'] : [];

                    if (strpos($quote['code'], '+LG+LAD+NBD') !== false) {
                        $quotesCarrier['ltl']['abf']['LGLADNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+NBD') !== false) {
                        $quotesCarrier['ltl']['abf']['LGNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD+NBD') !== false) {
                        $quotesCarrier['ltl']['abf']['LADNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+LAD') !== false) {
                        $quotesCarrier['ltl']['abf']['LGLAD'][] = $quote;
                    } else if (strpos($quote['code'], '+NBD') !== false) {
                        $quotesCarrier['ltl']['abf']['NBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['abf']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD') !== false) {
                        $quotesCarrier['ltl']['abf']['LAD'][] = $quote;
                    } else if (strpos($quote['code'], '+HAT') !== false) {
                        $quotesCarrier['ltl']['abf']['HAT'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['abf']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'daylightltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = isset($residential['dayLightLtl']) && $residential['dayLightLtl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['daylight-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['daylight-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quoteSettings = isset($connectionSettings['daylight-ltl']['quote_settings']) ? $connectionSettings['daylight-ltl']['quote_settings'] : [];
                    if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['daylight']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+HAT') !== false) {
                        $quotesCarrier['ltl']['daylight']['HAT'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['daylight']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'seflltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = isset($residential['SouthEastern']) && $residential['SouthEastern'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['southeastern-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['southeastern-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isNBD'] = isset($connectionSettings['southeastern-ltl']['quote_settings']['offer_notify_as_option']) && $connectionSettings['southeastern-ltl']['quote_settings']['offer_notify_as_option'];
                    $quoteSettings = isset($connectionSettings['southeastern-ltl']['quote_settings']) ? $connectionSettings['southeastern-ltl']['quote_settings'] : [];

                    if (strpos($quote['code'], '+LG+NBD') !== false) {
                        $quotesCarrier['ltl']['SouthEastern']['LGNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+NBD') !== false) {
                        $quotesCarrier['ltl']['SouthEastern']['NBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['SouthEastern']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+HAT') !== false) {
                        $quotesCarrier['ltl']['SouthEastern']['HAT'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['SouthEastern']['simple'][] = $quote;
                    }
                } else if (strpos($quote['code'], 'unlltl') !== false) {
                    $quote['alwaysResi'] = strpos($quote['code'], '+R') !== false;
                    $quote['isResi'] = $residential['uniLtl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['unishipper-ltl']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['unishipper-ltl']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isNBD'] = isset($connectionSettings['unishipper-ltl']['quote_settings']['offer_notify_as_option']) && $connectionSettings['unishipper-ltl']['quote_settings']['offer_notify_as_option'];
                    $quoteSettings = isset($connectionSettings['unishipper-ltl']['quote_settings']) ? $connectionSettings['unishipper-ltl']['quote_settings'] : [];
                    $quote['isID'] = isset($connectionSettings['unishipper-ltl']['quote_settings']['offer_inside_delivery']) && $connectionSettings['unishipper-ltl']['quote_settings']['offer_inside_delivery'];
                    $quote['isLAD'] = isset($connectionSettings['unishipper-ltl']['quote_settings']['offer_limited_access_delivery']) && $connectionSettings['unishipper-ltl']['quote_settings']['offer_limited_access_delivery'];

                    if (strpos($quote['code'], '+LG+ID+NBD') !== false) {
                        $quotesCarrier['ltl']['uniltl']['LGIDNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+ID+LAD') !== false) {
                        $quotesCarrier['ltl']['uniltl']['LGIDLAD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+NBD') !== false) {
                        $quotesCarrier['ltl']['uniltl']['LGNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+ID+NBD') !== false) {
                        $quotesCarrier['ltl']['uniltl']['IDNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+LAD') !== false) {
                        $quotesCarrier['ltl']['uniltl']['LGLAD'][] = $quote;
                    } else if (strpos($quote['code'], '+ID+LAD') !== false) {
                        $quotesCarrier['ltl']['uniltl']['IDLAD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+ID') !== false) {
                        $quotesCarrier['ltl']['uniltl']['LGID'][] = $quote;
                    } else if (strpos($quote['code'], '+NBD') !== false) {
                        $quotesCarrier['ltl']['uniltl']['NBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['uniltl']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD') !== false) {
                        $quotesCarrier['ltl']['uniltl']['LAD'][] = $quote;
                    } else if (strpos($quote['code'], '+ID') !== false) {
                        $quotesCarrier['ltl']['uniltl']['ID'][] = $quote;
                    } else {
                        $quotesCarrier['ltl']['uniltl']['simple'][] = $quote;
                    }

                } else {
                    $alwaysResi = (isset($requestArr['carriers']['wweLTL']['api']['speed_freight_residential_delivery']) && $requestArr['carriers']['wweLTL']['api']['speed_freight_residential_delivery'] == 'Y');
                    $quote['alwaysResi'] = $alwaysResi;
                    $quote['isResi'] = isset($residential['wweLtl']) && $residential['wweLtl'] == 'Y';
                    $quote['isLG'] = isset($connectionSettings['ltl-quotes']['quote_settings']['offerLiftGateDelivery']) && $connectionSettings['ltl-quotes']['quote_settings']['offerLiftGateDelivery'];
                    $quote['isNBD'] = isset($connectionSettings['ltl-quotes']['quote_settings']['offer_notify_as_option']) && $connectionSettings['ltl-quotes']['quote_settings']['offer_notify_as_option'];
                    $quote['isID'] = isset($connectionSettings['ltl-quotes']['quote_settings']['offer_inside_delivery']) && $connectionSettings['ltl-quotes']['quote_settings']['offer_inside_delivery'];
                    $quote['isLAD'] = isset($connectionSettings['ltl-quotes']['quote_settings']['offer_limited_access_delivery']) && $connectionSettings['ltl-quotes']['quote_settings']['offer_limited_access_delivery'];
                    $quoteSettings = isset($connectionSettings['ltl-quotes']['quote_settings']) ? $connectionSettings['ltl-quotes']['quote_settings'] : [];

                    if (strpos($quote['code'], '+LG+ID+NBD') !== false) {
                        $quotesCarrier['ltl']['wwe']['LGIDNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+ID+LAD') !== false) {
                        $quotesCarrier['ltl']['wwe']['LGIDLAD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+NBD') !== false) {
                        $quotesCarrier['ltl']['wwe']['LGNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+ID+NBD') !== false) {
                        $quotesCarrier['ltl']['wwe']['IDNBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+LAD') !== false) {
                        $quotesCarrier['ltl']['wwe']['LGLAD'][] = $quote;
                    } else if (strpos($quote['code'], '+ID+LAD') !== false) {
                        $quotesCarrier['ltl']['wwe']['IDLAD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG+ID') !== false) {
                        $quotesCarrier['ltl']['wwe']['LGID'][] = $quote;
                    } else if (strpos($quote['code'], '+NBD') !== false) {
                        $quotesCarrier['ltl']['wwe']['NBD'][] = $quote;
                    } else if (strpos($quote['code'], '+LG') !== false) {
                        $quotesCarrier['ltl']['wwe']['LG'][] = $quote;
                    } else if (strpos($quote['code'], '+LAD') !== false) {
                        $quotesCarrier['ltl']['wwe']['LAD'][] = $quote;
                    } else if (strpos($quote['code'], '+ID') !== false) {
                        $quotesCarrier['ltl']['wwe']['ID'][] = $quote;
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

        if (isset($quotesCarrierNew) && empty($quotesCarrierNew['ltl'])) {
            return [];
        }

        foreach ($quotesCarrierNew['ltl'] as $ltlQuote) {
            foreach ($ltlQuote as $simpleLg => $ltlQuot) {
                $ltlQuot = $ltlQuot[0] ?? $ltlQuot;
                $rCode = (($parcel['isResi'] ?? false) || ($ltlQuot['isResi'] ?? false) || ($parcel['alwaysResi'] ?? false) || ($ltlQuot['alwaysResi'] ?? false)) ? '+R' : '';
                $isResi = ($parcel['isResi'] ?? false) || ($ltlQuot['isResi'] ?? false);
                if ($simpleLg === 'simple') {
                    $rtitle = Functions:: getAccessTitle($quoteSettings, $isResi, false, false, false, false, false, false, $storeId);

                    $newQuotes[] = [
                        'code' => 'multi' . $rCode,
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                } else if ($simpleLg === 'LG') {
                    $rtitle = Functions:: getAccessTitle($quoteSettings, $isResi, $ltlQuot['isLG'], false, false, false, false, false, $storeId);

                    $newQuotes[] = [
                        'code' => 'multi' . $rCode . '+LG',
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                } else if ($simpleLg === 'ID') {
                    $rtitle = Functions:: getAccessTitle($quoteSettings, $isResi, false, $ltlQuot['isID'], false, false, false, false, $storeId);

                    $newQuotes[] = [
                        'code' => 'multi' . $rCode . '+ID',
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                } else if ($simpleLg === 'LGID') {
                    $rtitle = Functions:: getAccessTitle($quoteSettings, $isResi, $ltlQuot['isLG'], $ltlQuot['isID'], false, false, false, false, $storeId);

                    $newQuotes[] = [
                        'code' => 'multi' . $rCode . '+LG+ID',
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                } else if ($simpleLg === 'LAD') {
                    // Create Multi Quotes Array of Limited Access Delivery, When Small and Ltl Products
                    $rtitle = Functions:: getAccessTitle($quoteSettings, $isResi, false, false, false, $ltlQuot['isLAD'], false, false, $storeId);

                    $newQuotes[] = [
                        'code' => 'multi' . $rCode . '+LAD',
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                } else if ($simpleLg === 'LGLAD') {
                    // Create Multi Quotes Array of liftgate and Limited Access Delivery, When Small and Ltl Products
                    $rtitle = Functions:: getAccessTitle($quoteSettings, $isResi, $ltlQuot['isLG'], false, false, $ltlQuot['isLAD'], false, false, $storeId);

                    $newQuotes[] = [
                        'code' => 'multi' . $rCode . '+LG+LAD',
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                } else if ($simpleLg === 'IDLAD') {
                    // Create Multi Quotes Array of inside and Limited Access Delivery, When Small and Ltl Products
                    $rtitle = Functions:: getAccessTitle($quoteSettings, $isResi, false, $ltlQuot['isID'], false, $ltlQuot['isLAD'], false, false, $storeId);

                    $newQuotes[] = [
                        'code' => 'multi' . $rCode . '+ID+LAD',
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                } else if ($simpleLg === 'LGIDLAD') {
                    // Create Multi Quotes Array of liftgate, inside and Limited Access Delivery, When Small and Ltl Products
                    $rtitle = Functions:: getAccessTitle($quoteSettings, $isResi, $ltlQuot['isLG'], $ltlQuot['isID'], false, $ltlQuot['isLAD'], false, false, $storeId);

                    $newQuotes[] = [
                        'code' => 'multi' . $rCode . '+LG+ID+LAD',
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                } else if ($simpleLg === 'IDLADNBD') {

                    // Create Multi Quotes Array of inside, Limited Access and noftify before Delivery, When Small and Ltl Products

                    $rtitle = Functions:: getAccessTitle($quoteSettings, $isResi, false, $ltlQuot['isID'], $ltlQuot['isNBD'], $ltlQuot['isLAD']);

                    

                    $newQuotes[] = [

                        'code' => 'multi' . $rCode . 'ID+LAD+NBD',

                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],

                        'title' => 'Freight' . $rtitle

                    ];

                } else if ($simpleLg === 'LGIDLADNBD') {

                    // Create Multi Quotes Array of liftgate, inside, Limited Access and notify before Delivery, When Small and Ltl Products

                    $rtitle = Functions:: getAccessTitle($quoteSettings, $isResi, $ltlQuot['isLG'], $ltlQuot['isID'], $ltlQuot['isNBD'], $ltlQuot['isLAD']);

                    

                    $newQuotes[] = [

                        'code' => 'multi' . $rCode . '+LG+ID+LAD+NBD',

                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],

                        'title' => 'Freight' . $rtitle

                    ];

                } else if ($simpleLg === 'TL') {

                    $newQuotes[] = [
                        'code' => 'multi' . '+TL',
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight'
                    ];
                } elseif ($simpleLg == 'TMD') {
                    $rtitle = ($parcel['isResi'] ?? $ltlQuot['isResi']) ? Functions::$twoManDelResiLabel : Functions::$twoManDeliveryLabel;

                    $newQuotes[] = [
                        'code' => 'multi' . $rCode . Functions::$twoManDelAccess,
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                } elseif ($simpleLg == 'APD') {
                    $rtitle = ($parcel['isResi'] ?? $ltlQuot['isResi']) ? Functions::$appointmentDelResiLabel : Functions::$appointmentDeliveryLabel;

                    $newQuotes[] = [
                        'code' => 'multi' . $rCode . Functions::$appointmentDelAccess,
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                } elseif ($simpleLg == 'TMDAPD') {
                    $rtitle = ($parcel['isResi'] ?? $ltlQuot['isResi']) ? Functions::$twoManAptDelResiLabel : Functions::$twoManAppDelLabel;

                    $newQuotes[] = [
                        'code' => 'multi' . $rCode . Functions::$twoManAptDelAccess,
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                } else if ($simpleLg == 'IDNBD') {
                    // Create Multi Quotes Array of Inside and Notify before Delivery, When Small and Ltl Products
                    $rtitle = Functions:: getAccessTitle($quoteSettings, $isResi, false, $ltlQuot['isID'], $ltlQuot['isNBD'], false, false, false, $storeId);

                    $newQuotes[] = [
                        'code' => 'multi' . $rCode . Functions::$insideNotifyDelAccess,
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                } else if ($simpleLg == 'LGNBD') {
                    // Create Multi Quotes Array of Liftgate and Notify before Delivery, When Small and Ltl Products
                    $rtitle = Functions:: getAccessTitle($quoteSettings, $isResi, $ltlQuot['isLG'], false, $ltlQuot['isNBD'], false, false, false, $storeId);

                    $newQuotes[] = [
                        'code' => 'multi' . $rCode . Functions::$notifyDelLgAccess,
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                } else if ($simpleLg == 'LGIDNBD') {
                    // Create Multi Quotes Array of liftgate and  Notify before Delivery, When Small and Ltl Products
                    $rtitle = Functions:: getAccessTitle($quoteSettings, $isResi, $ltlQuot['isLG'], $ltlQuot['isID'], $ltlQuot['isNBD'], false, false, false, $storeId);

                    $newQuotes[] = [
                        'code' => 'multi' . $rCode . Functions::$lginsideNotifyDelAccess,
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                } else if ($simpleLg == 'NBD') {
                    // Create Multi Quotes Array of Notify before Delivery, When Small and Ltl Products
                    $rtitle = Functions:: getAccessTitle($quoteSettings, $isResi, false, false, $ltlQuot['isNBD'], false, false, false, $storeId);

                    $newQuotes[] = [
                        'code' => 'multi' . $rCode . Functions::$notifyDelAccess,
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                } else if ($simpleLg == 'LADNBD') {
                    // Create Multi Quotes Array of Limited Access and  Notify before Delivery, When Small and Ltl Products
                    $rtitle = Functions:: getAccessTitle($quoteSettings, $isResi, false, false, $ltlQuot['isNBD'], $ltlQuot['isLAD'], false, false, $storeId);

                    $newQuotes[] = [
                        'code' => 'multi' . $rCode . Functions::$laccessNotifyDelAccess,
                        'rate' => ($parcel['rate'] ?? 0) + $ltlQuot['rate'],
                        'title' => 'Freight' . $rtitle
                    ];
                } else if ($simpleLg == 'LGLADNBD') {
                    // Create Multi Quotes Array of liftgate, Limited Access and  Notify before Delivery, When Small and Ltl Products
                    $rtitle = Functions:: getAccessTitle($quoteSettings, $isResi, $ltlQuot['isLG'], false, $ltlQuot['isNBD'], $ltlQuot['isLAD'], false, false, $storeId);

                    $newQuotes[] = [
                        'code' => 'multi' . $rCode . Functions::$lglaccesseNotifyDelAccess,
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

            if (isset($quotes['ID'][0])) {
                $multiShipments[$count]['insideDelivery'][$indexes['ltl'][0]] = $quotes['ID'][0];
                $multiShipments[$count]['insideDelivery'][$indexes['small'][0]] = $parcel;
                $count++;
            }

            if (isset($quotes['LGID'][0])) {
                $multiShipments[$count]['insideLiftGateDelivery'][$indexes['ltl'][0]] = $quotes['LGID'][0];
                $multiShipments[$count]['insideLiftGateDelivery'][$indexes['small'][0]] = $parcel;
                $count++;
            }

            if (isset($quotes['LAD'][0])) {
                $multiShipments[$count]['limitedaccess'][$indexes['ltl'][0]] = $quotes['LAD'][0];
                $multiShipments[$count]['limitedaccess'][$indexes['small'][0]] = $parcel;
                $count++;
            }

            if (isset($quotes['LGLAD'][0])) {
                $multiShipments[$count]['limitedaccessLG'][$indexes['ltl'][0]] = $quotes['LGLAD'][0];
                $multiShipments[$count]['limitedaccessLG'][$indexes['small'][0]] = $parcel;
                $count++;
            }

            if (isset($quotes['TL'][0])) {
                $multiShipments[$count]['Truckload'][$indexes['ltl'][0]] = $quotes['TL'][0];
                $multiShipments[$count]['Truckload'][$indexes['small'][0]] = $parcel;
                $count++;
            }

            if (isset($quotes['TMD'][0])) {
                $multiShipments[$count]['twoMan'][$indexes['ltl'][0]] = $quotes['TMD'][0];
                $multiShipments[$count]['twoMan'][$indexes['small'][0]] = $parcel;
                $count++;
            }

            if (isset($quotes['APD'][0])) {
                $multiShipments[$count]['appointment'][$indexes['ltl'][0]] = $quotes['APD'][0];
                $multiShipments[$count]['appointment'][$indexes['small'][0]] = $parcel;
                $count++;
            }

            if (isset($quotes['TMDAPD'][0])) {
                $multiShipments[$count]['twoManAptDelivery'][$indexes['ltl'][0]] = $quotes['TMDAPD'][0];
                $multiShipments[$count]['twoManAptDelivery'][$indexes['small'][0]] = $parcel;
                $count++;
            }

            if (isset($quotes['NBD'][0])) {
                $multiShipments[$count]['notifydelivery'][$indexes['ltl'][0]] = $quotes['NBD'][0];
                $multiShipments[$count]['notifydelivery'][$indexes['small'][0]] = $parcel;
                $count++;
            }

            if (isset($quotes['LGNBD'][0])) {
                $multiShipments[$count]['lgnotifydelivery'][$indexes['ltl'][0]] = $quotes['LGNBD'][0];
                $multiShipments[$count]['lgnotifydelivery'][$indexes['small'][0]] = $parcel;
                $count++;
            }

            if (isset($quotes['LADNBD'][0])) {
                $multiShipments[$count]['laccessnotifydelivery'][$indexes['ltl'][0]] = $quotes['LADNBD'][0];
                $multiShipments[$count]['laccessnotifydelivery'][$indexes['small'][0]] = $parcel;
                $count++;
            }

            if (isset($quotes['LGLADNBD'][0])) {
                $multiShipments[$count]['lglaccessnotifydelivery'][$indexes['ltl'][0]] = $quotes['LGLADNBD'][0];
                $multiShipments[$count]['lglaccessnotifydelivery'][$indexes['small'][0]] = $parcel;
                $count++;
            }
        }

        return $multiShipments;
    }


    private function indexesOfQuotes($quotes)
    {
        $small = $ltl = [];
        $ltlQuotes = $quotes['wweLTL'] ?? $quotes['wweLTLN'] ?? $quotes['upsLTL'] ?? $quotes['fedexLTL'] ?? $quotes['globalTranz'] ?? $quotes['cerasis'] ?? $quotes['xpoLogistics'] ?? $quotes['rnl'] ?? $quotes['yrc'] ?? $quotes['freightQuote'] ?? $quotes['estes'] ?? $quotes['dayross'] ?? $quotes['odfl4me'] ?? $quotes['saia'] ?? $quotes['abf'] ?? $quotes['southeastern'] ?? $quotes['tql'] ?? $quotes['echoLogistics'] ?? $quotes['daylight'] ?? $quotes['chr'] ?? $quotes['priority1'] ?? [];
        foreach ($ltlQuotes as $key => $quote) {
            $ltl[] = $key;
        }

        $smallQuotes = $quotes['wweSmall'] ?? $quotes['wweSmallN'] ?? $quotes['upsSmall'] ?? $quotes['fedexSmall'] ?? $quotes['unishippersSmall'] ?? $quotes['usps'] ?? $quotes['purolator'] ?? $quotes['shipEngine'] ?? [];
        foreach ($smallQuotes as $key => $quote) {
            if(in_array($key, $this->SuppressParcelRates)){
                continue;
            }
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
        if ($hasSmallLtl) {
            return true;
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
        $smallCarriers = ['wweSmall', 'upsSmall', 'fedexSmall', 'unishippersSmall', 'usps', 'purolator'];
        $ltlCarriers = ['wweLTL', 'upsLTL', 'fedexLTL', 'globalTranz', 'cerasis', 'xpoLogistics', 'rnl', 'yrc', 'freightQuote', 'estes', 'dayross', 'odfl4me', 'saia', 'abf', 'southeastern', 'tql', 'echoLogistics', 'daylight', 'chr', 'priority1'];
        $ltl = $small = false;
        $locationsIds = [];
        
        foreach ($smallCarriers as $carName) {
            if (isset($carriers[$carName]) && !$small) {
                foreach ($quotes[$carName] as $key => $quote) {
                    $locationsIds[] = $key;

                    if (!isset($quote['severity'])) {
                        $small = true;
                        break 2;
                    }
                }
            }
        }
        foreach ($ltlCarriers as $carName) {
            if (isset($carriers[$carName]) && !$ltl) {
                foreach ($quotes[$carName] as $key => $quote) {
                    $locationsIds[] = $key;

                    $dayRossLtlError = $carName === 'dayross' && isset($quote['q']['soapBody']['soapFault']);
                    if (!isset($quote['severity']) || !$dayRossLtlError) {
                        $ltl = true;
                        break 2;
                    }
                }
            }
        }

        if ($ltl && $small && count(array_unique($locationsIds)) > 1) {
            return true;
        }

        return false;
    }
}
