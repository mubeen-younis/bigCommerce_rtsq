<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Helpers\Helpers;
use App\Models\ShippingRule;
use App\Models\CountryState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Connection;
use App\CustomClasses\Functions;
use App\Http\Controllers\AdditionalCarrierTabSettingController;
use App\CustomClasses\Unishippers\small\QuotesResults;
use App\Models\Store;
use App\CurlRequest;
use App\Models\ProductSetting;
use App\Models\Carrier;
use App\CustomClasses\BigCommerceFunctions;

class ShippingRuleController extends Controller
{

    public function __construct()
    {
        $this->unishippers = new QuotesResults();
        $this->curlRequest = new CurlRequest();
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getShippingRules(Request $request)
    {
        $shippingRules = ShippingRule::getStoreShippingRules($request['store_id']);
        return Helpers::sendJsonResponse(false, "", $shippingRules);
    }


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveShippingRule(Request $request): \Illuminate\Http\JsonResponse
    {
        $res = ShippingRule::saveOrUpdateShippingRule($request->all());
        return Helpers::sendJsonResponse($res['error'], $res['message'], $res['data']);
    }

    public function updateAvaiableStatus(Request $request): \Illuminate\Http\JsonResponse
    {
        $res = ShippingRule::updateAvaiableShippingRuleStatus($request->all());
        return Helpers::sendJsonResponse($res['error'], "Shipping Rule is " . $res['message'], $res['data']);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteShippingRule(Request $request): \Illuminate\Http\JsonResponse
    {
        ShippingRule::deleteShippingRule($request->uuid);
        return Helpers::sendJsonResponse(false, "Shipping Rule is deleted successfully.", $request->uuid);
    }


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getShippingRuleDetail(Request $request)
    {
        $shippingRuleDetail = ShippingRule::getShippingRuleDetailByUuid($request->uuid);
        return Helpers::sendJsonResponse(false, null, $shippingRuleDetail);
    }

    public function applyHideMethodRule($storeId, $lineItemData, $connectionSettings)
    {
        $is_true = false;
        $shippingRules = ShippingRule::getStoreShippingRules($storeId);
        Log::info('Store Shipiing rules ' . json_encode($shippingRules));
        if (!empty($shippingRules)) {
            $cartItems = !empty($lineItemData) ? $lineItemData : [];
            foreach ($shippingRules as $key => $rule) {
                if (isset($rule['available']) && $rule['available']) {
                    $provider = isset($rule['filter_provider']) ? $rule['filter_provider'] : '';
                    switch ($rule['rule_type']) {
                        case 2:
                            if (isset($rule['apply_to']) && $rule['apply_to'] == 1) {
                                $is_true = $this->hideMethods($rule, $cartItems);
                                if (!$is_true) {
                                    foreach ($connectionSettings as $key => $carrier) {
                                        if ($key == $provider) {
                                            unset($connectionSettings[$key]);
                                        }
                                    }
                                }
                            }
                            break;
                    }
                }
            }
        }
        return $connectionSettings;
    }

    public function overrideRates($storeId, $lineItemData, $connectionSettings, $quote = [], $carrierName, $originKey = '', $allOrigins = [], $destination)
    {
        $isRuletrue = $isSamedayApi = false;
        $carrierType = 0;
        $isOverrideRates = false;
        $shippingRules = ShippingRule::getStoreShippingRules($storeId);
        if (!empty($shippingRules)) {
            $cartItems = !empty($lineItemData) ? $lineItemData : [];
            foreach ($shippingRules as $key => $rule) {
                if (isset($rule['available']) && $rule['available']) {

                    
                    $statesProvinces = CountryState::getCountryStatesProvinces($destination['country']);
                    $settings = json_decode($rule['filter_settings'], true);
                    $stateProvince = isset($settings['filter_state_province']) && !empty($settings['filter_state_province']) ? $settings['filter_state_province'] : [];
                    $filterCountry = isset($settings['filter_country']) ? $settings['filter_country'] : '';
                    $statesCode = CountryState::getStateCode($statesProvinces, $stateProvince);
                    $hasLocationFilter = ($filterCountry != '' || !empty($stateProvince));
                    $isSameCountry = $destination['country'] == $filterCountry ?? false;
                    $isSameState = in_array($destination['state'], $statesCode) ?? false;



                    $providerSlug = isset($rule['filter_provider']) ? $rule['filter_provider'] : '';
                    $carrierId = isset($connectionSettings[$providerSlug]) ? $connectionSettings[$providerSlug]['creds']['installed_carrier_id'] : null;
                    $settings = Connection::join('installed_carriers', 'installed_carriers.id', 'connection_settings.installed_carrier_id')
                        ->join('carriers', 'carriers.id', 'installed_carriers.carrier_id')
                        ->select(
                            'carriers.slug',
                            'carriers.carrier_type',
                            'connection_settings.id',
                            'connection_settings.installed_carrier_id',
                            'connection_settings.value'
                        )
                        ->where('connection_settings.installed_carrier_id', $carrierId)->first();
                    if ($settings !== null) {
                        $value = json_decode($settings->value, true);
                        $carrierType = isset($settings['carrier_type']) ? $settings['carrier_type'] : null;
                    }

                    $request = new \Illuminate\Http\Request();

                    if ($providerSlug == 'gtz-ltl') {
                        $request->carrier_type = $value['api_type'] ?? '';
                        $request->store_id = $value['store_id'] ?? '';
                        if (isset($value['api_type']) && $value['api_type'] == 'NEWAPI') {
                            $providerSlug = 'gtz-new';
                        } elseif (isset($value['api_type']) && $value['api_type'] == 'CRS') {
                            $providerSlug = 'cltl';
                        }
                    } else if ($providerSlug == 'unishippers-small') {
                        if (isset($value['api_type']) && $value['api_type'] == 'new_api') {
                            $providerSlug = 'unishippers-small-new';
                        }
                    } else if ($providerSlug == 'dayross-ltl') {
                        if (isset($value['api_type']) && $value['api_type'] == 'sameday') {
                            $isSamedayApi = true;
                        }
                    }

                    $carrIndexName = Functions::getCarrIndexBySlug($providerSlug);
                    $request->installed_carrier_id = $carrierId;
                    $request->store_id = $storeId;
                    if ($rule['rule_type'] == 6 && $carrierId != null && $carrierName == $carrIndexName) {
                        switch ($rule['apply_to']) {
                            case 0: //Apply Shipments level
                                $isRuletrue = $this->checkIsOverrideRuleApply($rule, $cartItems, $originKey, $allOrigins, $destination);
                                break;
                            case 1: //Apply Cart level
                                $isRuletrue = $this->checkIsOverrideRuleApply($rule, $cartItems, $originKey, $allOrigins, $destination);
                                break;
                            case 2: //Apply Products level
                                $isRuletrue = $this->checkProdExistInShipment($rule, $cartItems, $originKey, $allOrigins, $destination);
                                break;
                            default:
                                break;
                        }
                        if (!$isRuletrue) {
                            if ($carrierType == 2) {
                                // Update Parcel carriers WS rate with override rate shipping rule
                                $serviceDesc = isset($quote['timeInTransit']['serviceDescription']) ? $quote['timeInTransit']['serviceDescription'] : '';
                                $serviceDesc = isset($quote['serviceDesc']) && !is_array($quote['serviceDesc']) ? $quote['serviceDesc'] : $serviceDesc;
                                $serviceDesc = isset($quote['service_code']) && $quote['service_code'] == 'ups_standard_international' ? $serviceDesc . ' International' : $serviceDesc;
                                $serviceDesc = str_replace(' AM®', ' A.M.', $serviceDesc) ?? $serviceDesc;
                                $serviceDesc = str_replace('®', '', $serviceDesc) ?? $serviceDesc;
                                $serviceDesc = str_replace(' Saturday', '', $serviceDesc) ?? $serviceDesc;
                                $serviceDesc = str_replace('Fedex ', '', $serviceDesc);
                                $serviceDesc = isset($quote['isInternationQuote']) && $quote['isInternationQuote'] ? str_replace('Ground', 'International Ground', $serviceDesc) : $serviceDesc;
                                $serviceDesc = str_replace('2 Day Am', '2 Day AM', $serviceDesc);

                                if (in_array($serviceDesc, $rule['filter_services'])) {

                                    if (($isSameCountry && empty($stateProvince)) || ($isSameCountry && $isSameState && !empty($stateProvince))) {
                                        $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                        $quote['NegotiatedRates']['Amount'] = $rule['service_rates'];
                                        $quote['shipping_amount']['amount'] = $rule['service_rates'];
                                        $isOverrideRates = true;
                                    }

                                    if (!$hasLocationFilter) {
                                        if (!$isSameCountry || !$isSameCountry && !$isSameState) {
                                            $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                            $quote['NegotiatedRates']['Amount'] = $rule['service_rates'];
                                            $quote['shipping_amount']['amount'] = $rule['service_rates'];
                                            $isOverrideRates = true;
                                        }
                                    }
                                } else if ($providerSlug == 'unishippers-small') {
                                    $serviceTitle = $this->unishippers->getServiceTitleFromServiceType($quote['serviceType']);
                                    if (in_array($serviceTitle, $rule['filter_services'])) {

                                        if (($isSameCountry && empty($stateProvince)) || ($isSameCountry && $isSameState && !empty($stateProvince))) {
                                            $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                            $quote['NegotiatedRates']['Amount'] = $rule['service_rates'];
                                            $isOverrideRates = true;
                                        }

                                        if (!$hasLocationFilter) {
                                            if (!$isSameCountry || !$isSameCountry && !$isSameState) {
                                                $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                                $quote['NegotiatedRates']['Amount'] = $rule['service_rates'];
                                                $isOverrideRates = true;
                                            }
                                        }
                                    }
                                } else if ($providerSlug == 'purolator-small') {
                                    $serviceDesc = preg_replace('/(?<=[a-zA-Z])(?=\d)|(?<=\d)(?=[a-zA-Z])|(?<=[a-z])(?=[A-Z])/', ' ', $quote['serviceType']);
                                    $serviceDesc = str_replace('Am', 'AM', $serviceDesc);
                                    $serviceDesc = str_replace('U.S.10', 'US 10', $serviceDesc);
                                    $serviceDesc = str_replace('U.S.9', 'US 9', $serviceDesc);
                                    $serviceDesc = str_replace('.', '', $serviceDesc);

                                    if (in_array($serviceDesc, $rule['filter_services'])) {

                                        if (($isSameCountry && empty($stateProvince)) || ($isSameCountry && $isSameState && !empty($stateProvince))) {
                                            $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                            $isOverrideRates = true;
                                        }
                                        if (!$hasLocationFilter) {
                                            if (!$isSameCountry || !$isSameCountry && !$isSameState) {
                                                $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                                $isOverrideRates = true;
                                            }
                                        }
                                    }
                                } else if ($providerSlug == 'usps-small') {
                                    $serviceType = 'USPS ' . $quote['serviceType'];
                                    $filterServices = str_replace('*', '', $rule['filter_services']);
                                    if (in_array($serviceType, $filterServices)) {

                                        if (($isSameCountry && empty($stateProvince)) || ($isSameCountry && $isSameState && !empty($stateProvince))) {
                                            $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                            $isOverrideRates = true;
                                        }
                                        if (!$hasLocationFilter) {
                                            if (!$isSameCountry || !$isSameCountry && !$isSameState) {
                                                $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                                $isOverrideRates = true;
                                            }
                                        }
                                    }
                                }
                            } else if ($carrierType == 1) {
                                // Update LTL carriers WS rate with override rate shipping rule
                                $quote = $this->overrideAccessorialsfee($quote, $rule, $destination);
                                $isOverrideRates = true;
                            }
                        }
                    }
                }
            }
        }
        return ['data' => $quote, 'isOverrideRates' => $isOverrideRates];
    }

    public function surchargeRates($storeId, $lineItemData, $connectionSettings, $quote = [], $carrierName, $originKey = '', $allOrigins = [], $destination)
    {
        $isRuletrue = false;
        $carrierType = 0;
        $surchargeServiceRate = 0;
        $isSurchargeRates = false;
        $shippingRules = ShippingRule::getStoreShippingRules($storeId);
        if (!empty($shippingRules)) {
            $cartItems = !empty($lineItemData) ? $lineItemData : [];
            foreach ($shippingRules as $key => $rule) {
                if (isset($rule['available']) && $rule['available'] && $rule['rule_type'] == 8) {
                    $providers = array_keys($connectionSettings);
                    foreach ($providers as $index) {
                        $providerSlug = isset($index) ? $index : " ";
                        $carrierId = isset($connectionSettings[$providerSlug]) ? $connectionSettings[$providerSlug]['creds']['installed_carrier_id'] : null;
                        $settings = Connection::join('installed_carriers', 'installed_carriers.id', 'connection_settings.installed_carrier_id')
                            ->join('carriers', 'carriers.id', 'installed_carriers.carrier_id')
                            ->select(
                                'carriers.slug',
                                'carriers.carrier_type',
                                'connection_settings.id',
                                'connection_settings.installed_carrier_id',
                                'connection_settings.value'
                            )
                            ->where('connection_settings.installed_carrier_id', $carrierId)->first();
                        if ($settings !== null) {
                            $value = json_decode($settings->value, true);
                            $carrierType = isset($settings['carrier_type']) ? $settings['carrier_type'] : null;
                        }

                        if ($providerSlug == 'unishippers-small') {
                            if (isset($value['api_type']) && $value['api_type'] == 'new_api') {
                                $providerSlug = 'unishippers-small-new';
                            }
                        }

                        if ($providerSlug == 'gtz-ltl') {
                            if (isset($value['api_type']) && $value['api_type'] == 'NEWAPI') {
                                $providerSlug = 'gtz-new';
                            } elseif (isset($value['api_type']) && $value['api_type'] == 'CRS') {
                                $providerSlug = 'cltl';
                            }
                        }

                        $carrIndexName = Functions::getCarrIndexBySlug($providerSlug);

                        if ($carrierType == 2 && $carrierId !== null && $carrierName == $carrIndexName) {
                            switch ($rule['apply_to']) {
                                case 0:
                                    $isRuletrue = $this->checkIsSurchargeRuleApply($rule, $cartItems, $originKey, $allOrigins, $destination);
                                    break;
                                case 1:
                                    $isRuletrue = $this->hideMethods($rule, $cartItems);
                                    break;
                                case 2:
                                    $isRuletrue = $this->checkProdExistInShipment($rule, $cartItems, $originKey, $allOrigins, $destination);
                                    if (!$isRuletrue && $carrierType == 1) {
                                        $quote = $this->surchargeRatesAccessorialsfee($quote, $rule);
                                        $isSurchargeRates = true;
                                    }
                                    break;
                                default:
                                    break;
                            }
                            if (!$isRuletrue) {
                                if (isset($rule['service_rates']) && $rule['service_rates'] >= 0) {

                                    if (isset($quote['totalNetCharge']['Amount'])) {
                                        $quote['totalNetCharge']['Amount'] += (float)$rule['service_rates'] ?? 0;
                                    }
                                    if (isset($quote['shipping_amount']['amount'])) {
                                        $quote['shipping_amount']['amount'] += (float)$rule['service_rates'] ?? 0;
                                    }
                                    if (isset($quote['NegotiatedRates']['Amount'])) {
                                        $quote['NegotiatedRates']['Amount'] = $quote['NegotiatedRates']['Amount'] > 0 ? (float)$quote['NegotiatedRates']['Amount'] + (float)$rule['service_rates'] : 0;
                                    }
                                    $isSurchargeRates = true;
                                }
                            }
                        }
                        if ($rule['rule_type'] == 8 && $carrierId !== null && $carrierName == $carrIndexName) {
                            switch ($rule['apply_to']) {
                                case 0:
                                    $isRuletrue = $this->checkIsSurchargeRuleApply($rule, $cartItems, $originKey, $allOrigins, $destination);
                                    if (!$isRuletrue && $carrierType == 1) {
                                        $quote = $this->surchargeRatesAccessorialsfee($quote, $rule);
                                        $isSurchargeRates = true;
                                    }
                                    break;
                                case 1:
                                    $isRuletrue = $this->hideMethods($rule, $cartItems);
                                    if (!$isRuletrue && $carrierType == 1) {
                                        $quote = $this->surchargeRatesAccessorialsfee($quote, $rule);
                                        $isSurchargeRates = true;
                                    }
                                    break;
                                case 2:
                                    $isRuletrue = $this->checkProdExistInShipment($rule, $cartItems, $originKey, $allOrigins, $destination);
                                    if (!$isRuletrue && $carrierType == 1) {
                                        $quote = $this->surchargeRatesAccessorialsfee($quote, $rule);
                                        $isSurchargeRates = true;
                                    }
                                    break;
                                default:
                                    break;
                            }
                        }
                    }
                    $surchargeServiceRate = $isSurchargeRates ? $rule['service_rates'] : 0;
                    if ($isSurchargeRates) {
                        return ['data' => $quote, 'isSurchargeRates' => $isSurchargeRates, 'surchargeServiceRate' => $surchargeServiceRate];
                    }
                }
            }
        }
        return ['data' => $quote, 'isSurchargeRates' => $isSurchargeRates, 'surchargeServiceRate' => $surchargeServiceRate];
    }

    // public function overrideAccessorialsfee($quote, $rule, $destination)
    // {
    //     $updateCount = 0;
    //     $serviceIndex = Functions::$accessorialServices;
    //     $statesProvinces = CountryState::getCountryStatesProvinces($destination['country']);
    //     $settings = json_decode($rule['filter_settings'], true);
    //     $stateProvince = isset($settings['filter_state_province']) && !empty($settings['filter_state_province']) ? $settings['filter_state_province'] : [];
    //     $filterCountry = isset($settings['filter_country']) ? $settings['filter_country'] : '';
    //     $statesCode = CountryState::getStateCode($statesProvinces, $stateProvince);
    //     $hasLocationFilter = ($filterCountry != '' || !empty($stateProvince));
    //     $isSameCountry = $destination['country'] == $filterCountry ?? false;
    //     $isSameState = in_array($destination['state'], $statesCode) ?? false;
    //     // Update WS accessorials rate with override rates shipping rule accessorials rate
    //     foreach ($serviceIndex as $key => $index) {
    //         if (isset($rule['service_rates']) && $rule['service_rates'] >= 0 && $rule['filter_services'] == $key && isset($quote['surcharges'][$index])) {
    //             if ($isSameState && $isSameCountry) {
    //                 $quote['totalNetCharge']['Amount'] -= (float)$quote['surcharges'][$index] ?? 0;
    //                 $quote['surcharges'][$index] = $rule['service_rates'];
    //                 $quote['totalNetCharge']['Amount'] += (float)$rule['service_rates'] ?? 0;
    //                 break;
    //             }

    //             if (!$isSameState && !$isSameCountry) {
    //                 $quote['totalNetCharge']['Amount'] -= (float)$quote['surcharges'][$index] ?? 0;
    //                 $quote['surcharges'][$index] = $rule['service_rates'];
    //                 $quote['totalNetCharge']['Amount'] += (float)$rule['service_rates'] ?? 0;
    //                 break;
    //             }
    //         }
    //         // Update WS base price with override rate shipping rule base price
    //         if (isset($rule['service_rates']) && $rule['service_rates'] >= 0 && $rule['filter_services'] == 'transportation') {
    //             if ($updateCount < 1) {
    //                 if ($hasLocationFilter) {
    //                     if ($isSameCountry || $isSameState && $isSameCountry) {
    //                         $quote['totalNetCharge']['Amount'] = $rule['service_rates'] ?? 0;
    //                     }
    //                 }
    //                 if (!$isSameState && !$isSameCountry) {
    //                     $quote['totalNetCharge']['Amount'] = $rule['service_rates'] ?? 0;
    //                 }
    //             }
    //             // Add WS accessorials rate into override rate shipping rule base price
    //             $quote['totalNetCharge']['Amount'] += isset($quote['surcharges'][$index]) ? (float)$quote['surcharges'][$index] : 0;
    //         }
    //         $updateCount++;
    //     }
    //     return $quote;
    // }


    public function overrideAccessorialsfee($quote, $rule, $destination)
    {
        $updateCount = 0;
        $serviceIndex = Functions::$accessorialServices;
        $statesProvinces = CountryState::getCountryStatesProvinces($destination['country']);
        $settings = json_decode($rule['filter_settings'], true);
        $stateProvince = isset($settings['filter_state_province']) && !empty($settings['filter_state_province']) ? $settings['filter_state_province'] : [];
        $filterCountry = isset($settings['filter_country']) ? $settings['filter_country'] : '';
        $statesCode = CountryState::getStateCode($statesProvinces, $stateProvince);
        $hasLocationFilter = ($filterCountry != '' || !empty($stateProvince));
        $isSameCountry = $destination['country'] == $filterCountry ?? false;
        $isSameState = in_array($destination['state'], $statesCode) ?? false;

        // Ensure filter_services is always an array
        $filterServices = [];
        if (isset($rule['filter_services'])) {
            if (is_array($rule['filter_services'])) {
                $filterServices = $rule['filter_services'];
            } else {
                $filterServices = [$rule['filter_services']];
            }
        }


        // 1. Get surcharge keys for the services in $filterServices
        $selectedSurchargeKeys = [];
        foreach ($serviceIndex as $service => $surchargeKey) {
            if (in_array($service, $filterServices)) {
                $selectedSurchargeKeys[] = $surchargeKey;
            }
        }

        // 2. Sum surcharges that do NOT match the selected keys
        $total = 0;
        foreach ($quote['surcharges'] as $surchargeKey => $val) {
            if (!in_array($surchargeKey, $selectedSurchargeKeys)) {  // exclude selected
                if (!empty($val) && $val != 0) {
                    $total += (float) $val;
                }
            }
        }

        // Handle individual accessorial services
        foreach ($serviceIndex as $key => $index) {
            if (in_array($key, $filterServices)) {
                if (isset($rule['service_rates']) && $rule['service_rates'] >= 0 && isset($quote['surcharges'][$index])) {

                    if (!empty($stateProvince)) {
                        if ($isSameCountry && $isSameState) {
                            $quote['totalNetCharge']['Amount'] -= (float)($quote['surcharges'][$index] ?? 0);
                            $quote['surcharges'][$index] = $rule['service_rates'];
                            $quote['totalNetCharge']['Amount'] += (float)($rule['service_rates'] ?? 0);
                        }
                    } else {
                        if ($isSameCountry) {
                            $quote['totalNetCharge']['Amount'] -= (float)($quote['surcharges'][$index] ?? 0);
                            $quote['surcharges'][$index] = $rule['service_rates'];
                            $quote['totalNetCharge']['Amount'] += (float)($rule['service_rates'] ?? 0);
                        }
                    }

                    if (!$hasLocationFilter) {
                        if (!$isSameCountry || !$isSameState && !$isSameCountry) {
                            $quote['totalNetCharge']['Amount'] -= (float)$quote['surcharges'][$index] ?? 0;
                            $quote['surcharges'][$index] = $rule['service_rates'];
                            $quote['totalNetCharge']['Amount'] += (float)$rule['service_rates'] ?? 0;
                        }
                    }
                }
            }
        }


        // Handle the base transportation service
        if (in_array('transportation', $filterServices) && isset($rule['service_rates']) && $rule['service_rates'] >= 0) {
            if ($hasLocationFilter) {
                if (!empty($stateProvince)) {
                    // Country + State selected
                    if ($isSameCountry && $isSameState) {
                        $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                    }
                } else {
                    // Only Country selected
                    if ($isSameCountry) {
                        $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                    }
                }
            }

            if (!$hasLocationFilter) {
                if (!$isSameCountry || !$isSameState && !$isSameCountry) {
                    $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                }
            }

            $quote['totalNetCharge']['Amount'] = $quote['totalNetCharge']['Amount'] + $total;
        }


        return $quote;
    }


    public function surchargeRatesAccessorialsfee($quote, $rule)
    {
        if (isset($rule['service_rates']) && $rule['service_rates'] >= 0) {
            $quote['totalNetCharge']['Amount'] += (float)$rule['service_rates'] ?? 0;
        }

        return $quote;
    }

    public function disableAllAccessorials($quoteSettings)
    {
        $quoteSettings['offer_inside_delivery'] = false;
        $quoteSettings['offerLiftGateDelivery'] = false;
        $quoteSettings['offer_limited_access_delivery'] = false;
        $quoteSettings['offer_notify_as_option'] = false;
        $quoteSettings['always_inside_delivery'] = false;
        $quoteSettings['alwaysLiftGateDelivery'] = false;
        $quoteSettings['always_quote_notify'] = false;
        $quoteSettings['always_limited_access_delivery'] = false;
        $quoteSettings['autoDetectedResidentialAddressesLfg'] = false;
        $quoteSettings['always_two_man_delivery'] = false;
        $quoteSettings['always_appointment_delivery'] = false;

        return $quoteSettings;
    }

    public function hideMethods($shippingRule, $items)
    {
        $isFilterWeight = $isFilterPrice = $isFilterQuantity = false;
        $isFilterWeightCheck = $isFilterPriceCheck = $isFilterQuantityCheck = false;
        if (isset($shippingRule['isFilterWeight']) && $shippingRule['isFilterWeight']) {
            $weight = collect($items)->map(function ($item) {
                return $item['lineItemWeight'] * $item['piecesOfLineItem'] ?? 0;
            }) ?? 0;
            $totalWeight = collect($weight)->sum();
            if (isset($shippingRule['weight_from']) && $totalWeight >= $shippingRule['weight_from'] && isset($shippingRule['weight_to']) && ($totalWeight < $shippingRule['weight_to'] || $shippingRule['weight_to'] === '')) {
                $isFilterWeight = true;
            }
        } else {
            $isFilterWeightCheck = true;
        }
        if (isset($shippingRule['isFilterPrice']) && $shippingRule['isFilterPrice']) {
            $price = collect($items)->map(function ($item) {
                return $item['lineItemPrice'] * $item['piecesOfLineItem'] ?? 0;
            }) ?? 0;
            $totalPrice = collect($price)->sum() ?? 0;
            if (isset($shippingRule['price_from']) && $totalPrice >= $shippingRule['price_from'] && isset($shippingRule['price_to']) && ($totalPrice < $shippingRule['price_to'] || $shippingRule['price_to'] === '')) {
                $isFilterPrice = true;
            }
        } else {
            $isFilterPriceCheck = true;
        }
        if (isset($shippingRule['isFilterQuantity']) && $shippingRule['isFilterQuantity']) {
            $totalQuantity = collect($items)->sum('piecesOfLineItem') ?? 0;
            if (isset($shippingRule['quantity_from']) && $totalQuantity >= $shippingRule['quantity_from'] && isset($shippingRule['quantity_to']) && ($totalQuantity < $shippingRule['quantity_to'] || $shippingRule['quantity_to'] === '')) {
                $isFilterQuantity = true;
            }
        } else {
            $isFilterQuantityCheck = true;
        }

        if (($isFilterWeight || $isFilterPrice || $isFilterQuantity) || ($isFilterWeightCheck && $isFilterPriceCheck && $isFilterQuantityCheck)) {
            return false;
        }

        return true;
    }

    // public function checkIsOverrideRuleApply($shippingRule, $items, $shipmentKey, $allOrigins, $destination)
    // {
    //     $variants = [];
    //     $totalWeight = 0;
    //     $totalQuantity = 0;
    //     $totalPrice = 0;
    //     $isFilterWeight = $isFilterPrice = $isFilterQuantity = false;
    //     $isFilterWeightCheck = $isFilterPriceCheck = $isFilterQuantityCheck = false;

    //     if (!empty($allOrigins)) {
    //         $variants = collect($allOrigins)->filter(function ($origin) use ($shipmentKey) {
    //             return $origin['locationId'] == $shipmentKey;
    //         })->keys()->all() ?? [];
    //     }

    //     if (!empty($variants)) {
    //         foreach ($variants as $variantId) {
    //             if (isset($items[$variantId])) {
    //                 $item = $items[$variantId];

    //                 $totalWeight += $item['lineItemWeight'] * $item['piecesOfLineItem'] ?? 0;
    //                 $totalPrice += $item['lineItemPrice'] * $item['piecesOfLineItem'] ?? 0;
    //                 $totalQuantity += $item['piecesOfLineItem'] ?? 0;
    //             }

    //         }
    //     }


    //     if (isset($shippingRule['isFilterWeight']) && $shippingRule['isFilterWeight']) {
    //         if (isset($shippingRule['weight_from']) && $totalWeight >= $shippingRule['weight_from'] && isset($shippingRule['weight_to']) && ($totalWeight < $shippingRule['weight_to'] || $shippingRule['weight_to'] === '')) {
    //             $isFilterWeight = true;
    //         }
    //     } else {
    //         $isFilterWeightCheck = true;
    //     }
    //     if (isset($shippingRule['isFilterPrice']) && $shippingRule['isFilterPrice']) {
    //         if (isset($shippingRule['price_from']) && $totalPrice >= $shippingRule['price_from'] && isset($shippingRule['price_to']) && ($totalPrice < $shippingRule['price_to'] || $shippingRule['price_to'] === '')) {
    //             $isFilterPrice = true;
    //         }
    //     } else {
    //         $isFilterPriceCheck = true;
    //     }
    //     if (isset($shippingRule['isFilterQuantity']) && $shippingRule['isFilterQuantity']) {
    //         if (isset($shippingRule['quantity_from']) && $totalQuantity >= $shippingRule['quantity_from'] && isset($shippingRule['quantity_to']) && ($totalQuantity < $shippingRule['quantity_to'] || $shippingRule['quantity_to'] === '')) {
    //             $isFilterQuantity = true;
    //         }
    //     } else {
    //         $isFilterQuantityCheck = true;
    //     }

    //     if (($isFilterWeight || $isFilterPrice || $isFilterQuantity) || ($isFilterWeightCheck && $isFilterPriceCheck && $isFilterQuantityCheck)) {
    //         return false;
    //     }

    //     return true;
    // }


    public function checkIsOverrideRuleApply($shippingRule, $items, $shipmentKey, $allOrigins, $destination)
    {
        $variants = [];
        $totalWeight = 0;
        $totalQuantity = 0;
        $totalPrice = 0;
        $isFilterWeight = $isFilterPrice = $isFilterQuantity = false;
        $isFilterWeightCheck = $isFilterPriceCheck = $isFilterQuantityCheck = false;

        // --- Location filters ---
        $statesProvinces = CountryState::getCountryStatesProvinces($destination['country']);
        $settings = json_decode($shippingRule['filter_settings'], true);
        $stateProvince = (isset($settings['filter_state_province']) && !empty($settings['filter_state_province'])) ? $settings['filter_state_province'] : [];
        $filterCountry = isset($settings['filter_country']) ? $settings['filter_country'] : '';
        $statesCode = CountryState::getStateCode($statesProvinces, $stateProvince);

        $hasLocationFilter = ($filterCountry !== '' || !empty($stateProvince));
        $isSameCountry = ($filterCountry !== '' && $destination['country'] == $filterCountry);
        $isSameState   = (!empty($statesCode) && in_array($destination['state'], $statesCode));

        // --- Collect variants for this shipment key ---
        if (!empty($allOrigins)) {
            $variants = collect($allOrigins)->filter(function ($origin) use ($shipmentKey) {
                return $origin['locationId'] == $shipmentKey;
            })->keys()->all() ?? [];
        }

        // --- Totals ---
        if (!empty($variants)) {
            foreach ($variants as $variantId) {
                if (isset($items[$variantId])) {
                    $item = $items[$variantId];
                    $qty  = $item['piecesOfLineItem'] ?? 0;

                    $totalWeight   += ($item['lineItemWeight'] ?? 0) * $qty;
                    $totalPrice    += ($item['lineItemPrice'] ?? 0) * $qty;
                    $totalQuantity += $qty;
                }
            }
        }

        // --------------------
        // Weight filter block
        // --------------------
        if (isset($shippingRule['isFilterWeight']) && $shippingRule['isFilterWeight']) {
            $inWeightRange = isset($shippingRule['weight_from']) && $totalWeight >= $shippingRule['weight_from']
                && isset($shippingRule['weight_to']) && ($totalWeight < $shippingRule['weight_to'] || $shippingRule['weight_to'] === '');

            if ($hasLocationFilter) {
                if ($inWeightRange) {
                    if ($isSameCountry || ($isSameCountry && $isSameState)) {
                        $isFilterWeight = true;           // match + location match
                    } elseif (!$isSameCountry && !$isSameState) {
                        $isFilterWeightCheck = true;      // match + location mismatch
                    }
                }
                // if not in range → leave both flags false (same as your current logic)
            } else {
                // no location filter → keep current behavior
                if ($inWeightRange) {
                    $isFilterWeight = true;
                }
            }
        } else {
            $isFilterWeightCheck = true; // same as your current logic
        }

        // -------------------
        // Price filter block
        // -------------------
        if (isset($shippingRule['isFilterPrice']) && $shippingRule['isFilterPrice']) {
            $inPriceRange = isset($shippingRule['price_from']) && $totalPrice >= $shippingRule['price_from']
                && isset($shippingRule['price_to']) && ($totalPrice < $shippingRule['price_to'] || $shippingRule['price_to'] === '');

            if ($hasLocationFilter) {
                if ($inPriceRange) {
                    if ($isSameCountry || ($isSameCountry && $isSameState)) {
                        $isFilterPrice = true;
                    } elseif (!$isSameCountry && !$isSameState) {
                        $isFilterPriceCheck = true;
                    }
                }
            } else {
                if ($inPriceRange) {
                    $isFilterPrice = true;
                }
            }
        } else {
            $isFilterPriceCheck = true;
        }

        // -----------------------
        // Quantity filter block
        // -----------------------
        if (isset($shippingRule['isFilterQuantity']) && $shippingRule['isFilterQuantity']) {
            $inQtyRange = isset($shippingRule['quantity_from']) && $totalQuantity >= $shippingRule['quantity_from']
                && isset($shippingRule['quantity_to']) && ($totalQuantity < $shippingRule['quantity_to'] || $shippingRule['quantity_to'] === '');

            if ($hasLocationFilter) {
                if ($inQtyRange) {
                    if ($isSameCountry || ($isSameCountry && $isSameState)) {
                        $isFilterQuantity = true;
                    } elseif (!$isSameCountry && !$isSameState) {
                        $isFilterQuantityCheck = true;
                    }
                }
            } else {
                if ($inQtyRange) {
                    $isFilterQuantity = true;
                }
            }
        } else {
            $isFilterQuantityCheck = true;
        }

        // --- Final decision (unchanged) ---
        if (
            ($isFilterWeight || $isFilterPrice || $isFilterQuantity) ||
            ($isFilterWeightCheck && $isFilterPriceCheck && $isFilterQuantityCheck)
        ) {
            return false;
        }

        return true;
    }


    public function checkIsSurchargeRuleApply($shippingRule, $items, $shipmentKey, $allOrigins, $destination)
    {
        // override rule
        if ($shippingRule['rule_type'] == 6) {

            $variants = [];
            $totalWeight = 0;
            $totalQuantity = 0;
            $totalPrice = 0;
            $isFilterWeight = $isFilterPrice = $isFilterQuantity = false;
            $isFilterWeightCheck = $isFilterPriceCheck = $isFilterQuantityCheck = false;

            // --- Location filters ---
            $statesProvinces = CountryState::getCountryStatesProvinces($destination['country']);
            $settings = json_decode($shippingRule['filter_settings'], true);
            $stateProvince = (isset($settings['filter_state_province']) && !empty($settings['filter_state_province'])) ? $settings['filter_state_province'] : [];
            $filterCountry = isset($settings['filter_country']) ? $settings['filter_country'] : '';
            $statesCode = CountryState::getStateCode($statesProvinces, $stateProvince);

            $hasLocationFilter = ($filterCountry !== '' || !empty($stateProvince));
            $isSameCountry = ($filterCountry !== '' && $destination['country'] == $filterCountry);
            $isSameState   = (!empty($statesCode) && in_array($destination['state'], $statesCode));

            // --- Collect variants for this shipment key ---
            if (!empty($allOrigins)) {
                $variants = collect($allOrigins)->filter(function ($origin) use ($shipmentKey) {
                    return $origin['locationId'] == $shipmentKey;
                })->keys()->all() ?? [];
            }

            // --- Totals ---
            if (!empty($variants)) {
                foreach ($variants as $variantId) {
                    if (isset($items[$variantId])) {
                        $item = $items[$variantId];
                        $qty  = $item['piecesOfLineItem'] ?? 0;

                        $totalWeight   += ($item['lineItemWeight'] ?? 0) * $qty;
                        $totalPrice    += ($item['lineItemPrice'] ?? 0) * $qty;
                        $totalQuantity += $qty;
                    }
                }
            }

            // --------------------
            // Weight filter block
            // --------------------
            if (isset($shippingRule['isFilterWeight']) && $shippingRule['isFilterWeight']) {
                $inWeightRange = isset($shippingRule['weight_from']) && $totalWeight >= $shippingRule['weight_from']
                    && isset($shippingRule['weight_to']) && ($totalWeight < $shippingRule['weight_to'] || $shippingRule['weight_to'] === '');

                if ($hasLocationFilter) {
                    if ($inWeightRange) {
                        if ($isSameCountry || ($isSameCountry && $isSameState)) {
                            $isFilterWeight = true;           // match + location match
                        } elseif (!$isSameCountry && !$isSameState) {
                            $isFilterWeightCheck = true;      // match + location mismatch
                        }
                    }
                    // if not in range → leave both flags false (same as your current logic)
                } else {
                    // no location filter → keep current behavior
                    if ($inWeightRange) {
                        $isFilterWeight = true;
                    }
                }
            } else {
                $isFilterWeightCheck = true; // same as your current logic
            }

            // -------------------
            // Price filter block
            // -------------------
            if (isset($shippingRule['isFilterPrice']) && $shippingRule['isFilterPrice']) {
                $inPriceRange = isset($shippingRule['price_from']) && $totalPrice >= $shippingRule['price_from']
                    && isset($shippingRule['price_to']) && ($totalPrice < $shippingRule['price_to'] || $shippingRule['price_to'] === '');

                if ($hasLocationFilter) {
                    if ($inPriceRange) {
                        if ($isSameCountry || ($isSameCountry && $isSameState)) {
                            $isFilterPrice = true;
                        } elseif (!$isSameCountry && !$isSameState) {
                            $isFilterPriceCheck = true;
                        }
                    }
                } else {
                    if ($inPriceRange) {
                        $isFilterPrice = true;
                    }
                }
            } else {
                $isFilterPriceCheck = true;
            }

            // -----------------------
            // Quantity filter block
            // -----------------------
            if (isset($shippingRule['isFilterQuantity']) && $shippingRule['isFilterQuantity']) {
                $inQtyRange = isset($shippingRule['quantity_from']) && $totalQuantity >= $shippingRule['quantity_from']
                    && isset($shippingRule['quantity_to']) && ($totalQuantity < $shippingRule['quantity_to'] || $shippingRule['quantity_to'] === '');

                if ($hasLocationFilter) {
                    if ($inQtyRange) {
                        if ($isSameCountry || ($isSameCountry && $isSameState)) {
                            $isFilterQuantity = true;
                        } elseif (!$isSameCountry && !$isSameState) {
                            $isFilterQuantityCheck = true;
                        }
                    }
                } else {
                    if ($inQtyRange) {
                        $isFilterQuantity = true;
                    }
                }
            } else {
                $isFilterQuantityCheck = true;
            }

            // --- Final decision (unchanged) ---
            if (
                ($isFilterWeight || $isFilterPrice || $isFilterQuantity) ||
                ($isFilterWeightCheck && $isFilterPriceCheck && $isFilterQuantityCheck)
            ) {
                return false;
            }

            return true;
        }

        $variants = [];
        $totalWeight = 0;
        $totalQuantity = 0;
        $totalPrice = 0;
        $isFilterWeight = $isFilterPrice = $isFilterQuantity = false;
        $isFilterWeightCheck = $isFilterPriceCheck = $isFilterQuantityCheck = false;

        if (!empty($allOrigins)) {
            $variants = collect($allOrigins)->filter(function ($origin) use ($shipmentKey) {
                return $origin['locationId'] == $shipmentKey;
            })->keys()->all() ?? [];
        }

        if (!empty($variants)) {
            foreach ($variants as $variantId) {
                if (isset($items[$variantId])) {
                    $item = $items[$variantId];
                    $totalWeight += $item['lineItemWeight'] * $item['piecesOfLineItem'] ?? 0;
                    $totalPrice += $item['lineItemPrice'] * $item['piecesOfLineItem'] ?? 0;
                    $totalQuantity += $item['piecesOfLineItem'] ?? 0;
                }
            }
        }


        if (isset($shippingRule['isFilterWeight']) && $shippingRule['isFilterWeight']) {
            if (isset($shippingRule['weight_from']) && $totalWeight >= $shippingRule['weight_from'] && isset($shippingRule['weight_to']) && ($totalWeight < $shippingRule['weight_to'] || $shippingRule['weight_to'] === '')) {
                $isFilterWeight = true;
            }
        } else {
            $isFilterWeightCheck = true;
        }
        if (isset($shippingRule['isFilterPrice']) && $shippingRule['isFilterPrice']) {
            if (isset($shippingRule['price_from']) && $totalPrice >= $shippingRule['price_from'] && isset($shippingRule['price_to']) && ($totalPrice < $shippingRule['price_to'] || $shippingRule['price_to'] === '')) {
                $isFilterPrice = true;
            }
        } else {
            $isFilterPriceCheck = true;
        }
        if (isset($shippingRule['isFilterQuantity']) && $shippingRule['isFilterQuantity']) {
            if (isset($shippingRule['quantity_from']) && $totalQuantity >= $shippingRule['quantity_from'] && isset($shippingRule['quantity_to']) && ($totalQuantity < $shippingRule['quantity_to'] || $shippingRule['quantity_to'] === '')) {
                $isFilterQuantity = true;
            }
        } else {
            $isFilterQuantityCheck = true;
        }

        if (($isFilterWeight || $isFilterPrice || $isFilterQuantity) || ($isFilterWeightCheck && $isFilterPriceCheck && $isFilterQuantityCheck)) {
            return false;
        }

        return true;
    }




    // public function checkIsSurchargeRuleApply($shippingRule, $items, $shipmentKey, $allOrigins, $destination)
    // {
    //     $variants = [];
    //     $totalWeight = 0;
    //     $totalQuantity = 0;
    //     $totalPrice = 0;
    //     $isFilterWeight = $isFilterPrice = $isFilterQuantity = false;
    //     $isFilterWeightCheck = $isFilterPriceCheck = $isFilterQuantityCheck = false;

    //     if (!empty($allOrigins)) {
    //         $variants = collect($allOrigins)->filter(function ($origin) use ($shipmentKey) {
    //             return $origin['locationId'] == $shipmentKey;
    //         })->keys()->all() ?? [];
    //     }

    //     if (!empty($variants)) {
    //         foreach ($variants as $variantId) {
    //             if (isset($items[$variantId])) {
    //                 $item = $items[$variantId];
    //                 $totalWeight += $item['lineItemWeight'] * $item['piecesOfLineItem'] ?? 0;
    //                 $totalPrice += $item['lineItemPrice'] * $item['piecesOfLineItem'] ?? 0;
    //                 $totalQuantity += $item['piecesOfLineItem'] ?? 0;
    //             }

    //         }
    //     }


    //     if (isset($shippingRule['isFilterWeight']) && $shippingRule['isFilterWeight']) {
    //         if (isset($shippingRule['weight_from']) && $totalWeight >= $shippingRule['weight_from'] && isset($shippingRule['weight_to']) && ($totalWeight < $shippingRule['weight_to'] || $shippingRule['weight_to'] === '')) {
    //             $isFilterWeight = true;
    //         }
    //     } else {
    //         $isFilterWeightCheck = true;
    //     }
    //     if (isset($shippingRule['isFilterPrice']) && $shippingRule['isFilterPrice']) {
    //         if (isset($shippingRule['price_from']) && $totalPrice >= $shippingRule['price_from'] && isset($shippingRule['price_to']) && ($totalPrice < $shippingRule['price_to'] || $shippingRule['price_to'] === '')) {
    //             $isFilterPrice = true;
    //         }
    //     } else {
    //         $isFilterPriceCheck = true;
    //     }
    //     if (isset($shippingRule['isFilterQuantity']) && $shippingRule['isFilterQuantity']) {
    //         if (isset($shippingRule['quantity_from']) && $totalQuantity >= $shippingRule['quantity_from'] && isset($shippingRule['quantity_to']) && ($totalQuantity < $shippingRule['quantity_to'] || $shippingRule['quantity_to'] === '')) {
    //             $isFilterQuantity = true;
    //         }
    //     } else {
    //         $isFilterQuantityCheck = true;
    //     }

    //     if (($isFilterWeight || $isFilterPrice || $isFilterQuantity) || ($isFilterWeightCheck && $isFilterPriceCheck && $isFilterQuantityCheck)) {
    //         return false;
    //     }

    //     return true;
    // }

    public function getCountryStates(Request $request)
    {
        $shippingRuleDetail = CountryState::getCountryStatesProvinces($request->countryCode);
        return Helpers::sendJsonResponse(false, null, $shippingRuleDetail);
    }

    public function getProductsCategories(Request $request)
    {

        $store = Store::where('hash', $request['store_hash'])->first();
        if (empty($store)) {
            return [];
        }
        $headers[] = 'X-Auth-Token: ' . $store->access_token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = "https://api.bigcommerce.com/stores/" . $request['store_hash'] . "/v3/catalog/categories";
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $allCategories = json_decode($response['response'], true);

            $categories = [];
            if (isset($allCategories['data']) && !empty($allCategories['data'])) {
                foreach ($allCategories['data'] as $key => $category) {
                    $categories[] = ['key' => $category['id'], 'value' => $category['name']];
                };
            }
        }
        return Helpers::sendJsonResponse(false, null, $categories);;
    }

    public function getProductsBrands(Request $request)
    {

        $store = Store::where('hash', $request['store_hash'])->first();
        if (empty($store)) {
            return [];
        }
        $headers[] = 'X-Auth-Token: ' . $store->access_token;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $endpoint = "https://api.bigcommerce.com/stores/" . $request['store_hash'] . "/v3/catalog/brands";
        $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);
        if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
            $allBrands = json_decode($response['response'], true);

            $brands = [];
            if (isset($allBrands['data']) && !empty($allBrands['data'])) {
                foreach ($allBrands['data'] as $key => $brand) {
                    $brands[] = ['key' => $brand['id'], 'value' => $brand['name']];
                };
            }
        }
        return Helpers::sendJsonResponse(false, null, $brands);
    }

    public function getshippingRuleProductsFromDb(Request $request)
    {
        try {
            $store = Store::where('hash', $request['store_hash'])->first();
            if (empty($store)) {
                return null;
            }
            $data = $products = $response = [];
            $perPage = 50;
            $sortProd = (isset($request['sortProd']) && $request['sortProd'] === "true") ? 'desc' : 'asc';
            $search = $request['search'] ?? null;

            if ($search) {
                $headers = BigCommerceFunctions::getHeaders($request['store_hash']);
                $endpoint = BigCommerceFunctions::$initalUrl . $request['store_hash'] . "/v3/catalog/products?keyword=" . urlencode($search) . "&limit=" . $perPage . "&direction=" . $sortProd;
                $response = $this->curlRequest->enSingleCurlRequest($endpoint, [], $headers, 'GET', false);
            }

            if (isset($response['status']) && $response['status'] == true && isset($response['response'])) {
                $response = json_decode($response['response'], true);

                if (!empty($response['data'])) {
                    $data = $response['data'] ?? [];

                    if (empty($data)) {
                        return response()->json([
                            'error' => true,
                            'data' => [],
                            'message' => 'No Products Available',
                        ], 200);
                    }

                    if (count($data)) {
                        foreach ($data as $key => $product) {
                            $products[$key]['name'] = isset($product['name']) ? $product['name'] : '' ?? '';
                            $products[$key]['source_product_id'] = isset($product['id']) ? $product['id'] : '' ?? '';
                            $products[$key]['variant_id'] = isset($product['base_variant_id']) ? $product['base_variant_id'] : null ?? null;
                            $products[$key]['sku'] = isset($product['sku']) ? $product['sku'] : null ?? null;
                        }
                    }
                }
            }

            if (empty($products)) {
                return response()->json([
                    'error' => true,
                    'data' => [],
                    'message' => 'No Products Available',
                ], 200);
            }

            $resp = response()->json([
                'error' => false,
                'data' => $products,
                'message' => '',
            ], 200);
            return $resp;
        } catch (\Exception $exception) {
            Log::info('catch: ' . json_encode($exception->getMessage()));
        }
    }

    public function checkProdExistInShipment($rule, $cartItems, $originKey, $allOrigins, $destination)
    {
        // Check: Product exist in provided $originKey
        if (!empty($allOrigins)) {
            foreach ($allOrigins as $key => $origin) {
                if ($origin['locationId'] == $originKey) {

                    $products = collect($cartItems)->where('variant_id', $key)->all() ?? [];
                    // Check: apply rule to Categories
                    if ($rule['apply_rule_to'] == 1) {
                        $categoriesIds = array_column($products, 'categories_id');
                        $productsCategoriesIds = array_values(array_merge(...$categoriesIds)) ?? [];
                        $filterCategories = collect($productsCategoriesIds)->intersect(empty($rule['categories']) ? [] : $rule['categories']) ?? [];

                        if (!empty($filterCategories->toArray())) {
                            return $this->checkIsSurchargeRuleApply($rule, $products, $originKey, $allOrigins, $destination);
                        }
                        // Check: apply rule to Brands
                    } elseif ($rule['apply_rule_to'] == 2) {
                        $brandsIds = array_column($products, 'brand_id') ?? [];
                        $filterBrands = collect($brandsIds)->intersect(empty($rule['brands']) ? [] : $rule['brands']) ?? [];

                        if (!empty($filterBrands->toArray())) {
                            return $this->checkIsSurchargeRuleApply($rule, $products, $originKey, $allOrigins, $destination);
                        }
                        // Check: apply rule to Individual Products
                    } elseif ($rule['apply_rule_to'] == 3) {
                        $productIds = array_column($products, 'product_id') ?? [];
                        $productIdsArray = array_column($rule['products'], 'value');

                        foreach ($productIds as $prod) {
                            if (in_array($prod, $productIdsArray)) {
                                return $this->checkIsSurchargeRuleApply($rule, $products, $originKey, $allOrigins, $destination);
                            }
                        }
                    }

                    return true;
                }
            }
        }
        return $isProdExist;
    }

    public function checkLargeCartRuleApply($shippingItems, $storeId)
    {
        $shipmentQuantity = 0;
        $LCSShippingRuleType = '9';
        $shipmentQuantity = collect($shippingItems)->sum('piecesOfLineItem') ?? 0;

        // Get Large Cart Settings Shipping Rule
        $LCSShippingRules = ShippingRule::getStoreShippingRules($storeId, $LCSShippingRuleType);


        foreach ($LCSShippingRules as $rule) {
            // Check: rule is available and meet the condition
            if (isset($rule['max_items']) && $shipmentQuantity > (int)$rule['max_items'] && isset($rule['available']) && $rule['available']) {
                return $rule;
            }
        }
        return [];
    }
}
