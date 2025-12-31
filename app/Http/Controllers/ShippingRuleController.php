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

    const RESTRICT_COUNTRY = 1;
    const HIDE_METHOD = 2;
    const RESTRICT_STATE = 3;
    const RESTRICT_POSTAL_CODE = 4;
    const RESTRICT_ORIGIN_LOCATION = 5;
    const OVERRIDE_RULE = 6;
    const HIDE_DELIVERY_ESTIMATES = 7;
    const SURCHARGE_RULE = 8;
    const LARGE_CART_SETTINGS = '9';
    const FLAT_RATE = 10;

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

    public function applyHideMethodRule($storeId, $lineItemData, $connectionSettings, $destination, $formData, $storeData, $addressStatus)
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
                                $is_true = $this->hideMethods($rule, $cartItems, $destination, $formData, $storeData, $addressStatus);
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

    public function overrideRates($storeId, $lineItemData, $connectionSettings, $quote = [], $carrierName, $originKey = '', $allOrigins = [], $destination, $formData, $storeData, $addressStatus)
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
                    $ruleSettings = json_decode($rule['filter_settings'], true);
                    $stateProvince = isset($settings['filter_state_province']) && !empty($settings['filter_state_province']) ? $settings['filter_state_province'] : [];
                    $filterCountry = isset($settings['filter_country']) ? $settings['filter_country'] : [];
                    $statesCode = CountryState::getStateCode($statesProvinces, $stateProvince);
                    $hasLocationFilter = ($filterCountry != '' || !empty($stateProvince));
                    $isSameCountry = $destination['country'] == $filterCountry ?? false;
                    $isSameState = in_array($destination['state'], $statesCode) ?? false;
                    $maxShippingRateFilter = isset($ruleSettings['filter_max_shipping_rate']) ? (int)$ruleSettings['filter_max_shipping_rate'] : '';

                    $providerSlug = isset($rule['filter_provider']) ? $rule['filter_provider'] : '';
                    $carrierId = isset($connectionSettings[$providerSlug]) ? $connectionSettings[$providerSlug]['creds']['installed_carrier_id'] : null;
                    Log::info('ovovooooo check carrier ID carrierId' . json_encode([
                        $carrierId
                    ]));

                    Log::info('ovovooooo check connectionSettings' . json_encode([
                        $connectionSettings
                    ]));
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
                    if ($rule['rule_type'] == 6 && $carrierName == $carrIndexName) {
                        switch ($rule['apply_to']) {
                            case 0: //Apply Shipments level
                                $isRuletrue = $this->checkIsOverrideRuleApply($rule, $cartItems, $originKey, $allOrigins, $destination, $maxShippingRateFilter, $quote, $formData, $storeData, $addressStatus);
                                break;
                            case 1: //Apply Cart level
                                $isRuletrue = $this->overrideHideMethods($rule, $cartItems, $destination, $maxShippingRateFilter, $quote, $formData, $storeData, $addressStatus);
                                break;
                            case 2: //Apply Products level
                                $isRuletrue = $this->checkProdExistInShipment($rule, $cartItems, $originKey, $allOrigins, $destination, $maxShippingRateFilter, $formData, $storeData, $addressStatus);
                                break;
                            default:
                                break;
                        }
                        Log::info('ovovooooo check carrier type' . json_encode([
                            $carrierType
                        ]));
                        Log::info('ovovooooo check for small carriers isOverrideRates' . json_encode([
                            $isOverrideRates
                        ]));
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
                                if (in_array($serviceDesc, (array) $rule['filter_services'])) {

                                    $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                    $quote['NegotiatedRates']['Amount'] = $rule['service_rates'];
                                    $quote['shipping_amount']['amount'] = $rule['service_rates'];
                                    $isOverrideRates = true;
                                } else if ($providerSlug == 'unishippers-small') {
                                    $serviceTitle = $this->unishippers->getServiceTitleFromServiceType($quote['serviceType']);
                                    if (in_array($serviceTitle, (array) $rule['filter_services'])) {

                                        $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                        $quote['NegotiatedRates']['Amount'] = $rule['service_rates'];
                                        $isOverrideRates = true;
                                    }
                                } else if ($providerSlug == 'purolator-small') {
                                    $serviceDesc = preg_replace('/(?<=[a-zA-Z])(?=\d)|(?<=\d)(?=[a-zA-Z])|(?<=[a-z])(?=[A-Z])/', ' ', $quote['serviceType']);
                                    $serviceDesc = str_replace('Am', 'AM', $serviceDesc);
                                    $serviceDesc = str_replace('U.S.10', 'US 10', $serviceDesc);
                                    $serviceDesc = str_replace('U.S.9', 'US 9', $serviceDesc);
                                    $serviceDesc = str_replace('.', '', $serviceDesc);

                                    if (in_array($serviceDesc, (array) $rule['filter_services'])) {

                                        $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                        $isOverrideRates = true;
                                    }
                                } else if ($providerSlug == 'usps-small') {
                                    $serviceType = 'USPS ' . $quote['serviceType'];
                                    $filterServices = str_replace('*', '', $rule['filter_services']);
                                    if (in_array($serviceType, (array) $filterServices)) {

                                        $quote['totalNetCharge']['Amount'] = $rule['service_rates'];
                                        $isOverrideRates = true;
                                    }
                                }
                                Log::info('ovovooooo check for small carriers isOverrideRates' . json_encode([
                                    $isOverrideRates
                                ]));
                            } else if ($carrierType == 1) {
                                // Update LTL carriers WS rate with override rate shipping rule
                                $quote = $this->overrideAccessorialsfee($quote, $rule, $destination, $maxShippingRateFilter);
                                $isOverrideRates = true;
                                Log::info('ovovooooooo to check LTL carriers isOverrideRates' . json_encode([
                                    $isOverrideRates
                                ]));
                            }
                        }
                    }
                }
            }
        }
        return ['data' => $quote, 'isOverrideRates' => $isOverrideRates];
    }

    public function surchargeRates($storeId, $lineItemData, $connectionSettings, $quote = [], $carrierName, $originKey = '', $allOrigins = [], $destination, $formData, $storeData, $addressStatus)
    {
        $isRuletrue = false;
        $carrierType = 0;
        $surchargeServiceRate = 0;
        $isSurchargeRates = false;
        $shippingRules = ShippingRule::getStoreShippingRules($storeId);
        if (!empty($shippingRules)) {
            $cartItems = !empty($lineItemData) ? $lineItemData : [];
            foreach ($shippingRules as $key => $rule) {
                $ruleSettings = json_decode($rule['filter_settings'], true);
                if (isset($rule['available']) && $rule['available'] && $rule['rule_type'] == self::SURCHARGE_RULE) {
                    $maxShippingRateFilter = isset($ruleSettings['filter_max_shipping_rate']) ? (int)$ruleSettings['filter_max_shipping_rate'] : '';
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
                                    $isRuletrue = $this->checkIsSurchargeRuleApply($rule, $cartItems, $originKey, $allOrigins, $destination, $maxShippingRateFilter, $formData, $storeData, $addressStatus);
                                    $isRuletrue = $isRuletrue['istrue'];
                                    break;
                                case 1:
                                    $isRuletrue = $this->hideMethods($rule, $cartItems, $destination, $formData, $storeData, $addressStatus);
                                    break;
                                case 2:
                                    $isRuletrue = $this->checkProdExistInShipment($rule, $cartItems, $originKey, $allOrigins, $destination, $maxShippingRateFilter, $formData, $storeData, $addressStatus);
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
                        if ($rule['rule_type'] == self::SURCHARGE_RULE && $carrierId !== null && $carrierName == $carrIndexName) {
                            switch ($rule['apply_to']) {
                                case 0:
                                    $isRuletrue = $this->checkIsSurchargeRuleApply($rule, $cartItems, $originKey, $allOrigins, $destination, $maxShippingRateFilter, $formData, $storeData, $addressStatus);
                                    $isRuletrue = $isRuletrue['istrue'];
                                    if (!$isRuletrue && $carrierType == 1) {
                                        $quote = $this->surchargeRatesAccessorialsfee($quote, $rule);
                                        $isSurchargeRates = true;
                                    }
                                    break;
                                case 1:
                                    $isRuletrue = $this->hideMethods($rule, $cartItems, $destination, $formData, $storeData, $addressStatus);
                                    if (!$isRuletrue && $carrierType == 1) {
                                        $quote = $this->surchargeRatesAccessorialsfee($quote, $rule);
                                        $isSurchargeRates = true;
                                    }
                                    break;
                                case 2:
                                    $isRuletrue = $this->checkProdExistInShipment($rule, $cartItems, $originKey, $allOrigins, $destination, $maxShippingRateFilter, $formData, $storeData, $addressStatus);
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


    public function  overrideAccessorialsfee($quote, $rule, $destination, int $maxShippingRateFilter)
    {
        $updateCount = 0;
        $serviceIndex = Functions::$accessorialServices;
        $statesProvinces = CountryState::getCountryStatesProvinces($destination['country']);
        $settings = json_decode($rule['filter_settings'], true);
        $stateProvince = isset($settings['filter_state_province']) && !empty($settings['filter_state_province']) ? $settings['filter_state_province'] : [];
        $filterCountry = isset($settings['filter_country']) ? $settings['filter_country'] : [];
        $statesCode = CountryState::getStateCode($statesProvinces, $stateProvince);
        $hasLocationFilter = ($filterCountry != '' || !empty($stateProvince));
        $isSameCountry = $destination['country'] == $filterCountry ?? false;
        $isSameState = in_array($destination['state'], $statesCode) ?? false;
        $orginalShippingRate = $quote['totalNetCharge']['Amount'];
        $orginalShippingRateExceeds = false;

        if ($maxShippingRateFilter < $orginalShippingRate) {
            $orginalShippingRateExceeds = true;
        }

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
        $totalUnselectedSurcharges = 0;
        if (!empty($quote['surcharges']) && is_array($quote['surcharges'])) {
            foreach ($quote['surcharges'] as $surchargeKey => $val) {
                if (!in_array($surchargeKey, $selectedSurchargeKeys)) {  // exclude selected
                    if (!empty($val) && $val != 0) {
                        $totalUnselectedSurcharges += (float) $val;
                    }
                }
            }
        }

        // Only proceed if location conditions are met
        if (isset($rule['service_rates']) && $rule['service_rates'] >= 0) {

            // Handle individual accessorial services (non-transportation)
            $totalAccessorialCharges = 0;
            foreach ($serviceIndex as $key => $index) {
                if (in_array($key, $filterServices) && isset($quote['surcharges'][$index])) {
                    // Subtract old surcharge from total
                    $quote['totalNetCharge']['Amount'] -= (float)($quote['surcharges'][$index] ?? 0);

                    // Set new surcharge (same rate for each selected service)
                    $quote['surcharges'][$index] = $rule['service_rates'];

                    // Add to total accessorial charges
                    $totalAccessorialCharges += (float)$rule['service_rates'];
                }
            }
            Log::info('ovovooooo accessorial fee totalAccessorialCharges' . json_encode([
                $totalAccessorialCharges
            ]));
            // Handle the base transportation service if selected
            if (in_array('transportation', $filterServices)) {
                // Set base transportation charge to service_rates
                // Then add all surcharges (both newly set and unselected)
                $quote['totalNetCharge']['Amount'] = $rule['service_rates'] + $totalAccessorialCharges + $totalUnselectedSurcharges;
            } else {
                // No transportation selected, just add the accessorial charges back
                $quote['totalNetCharge']['Amount'] += $totalAccessorialCharges;
            }
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

    public function hideMethods($shippingRule, $items, $destination, $formData, $storeData, $addressStatus)
    {
        $origins = isset($formData['lineItemData']['origin']) ? $formData['lineItemData']['origin'] : [];
        $cartItems = isset($formData['lineItemData']['items']) ? $formData['lineItemData']['items'] : [];
        // --- Location filters ---
        $statesProvinces = CountryState::getCountryStatesProvinces($destination['country']);
        $settings = json_decode($shippingRule['filter_settings'], true);
        $isAllFilterApplied = isset($settings['is_all_filter_applied']) ? $settings['is_all_filter_applied'] : '';
        $stateProvince = (isset($settings['filter_state_province']) && !empty($settings['filter_state_province'])) ? $settings['filter_state_province'] : [];
        $filterCountry = isset($settings['filter_country']) ? $settings['filter_country'] : [];
        $statesCode = CountryState::getStateCode($statesProvinces, $stateProvince);
        $postalCodes = isset($shippingRule['filter_postal_code']) ? $shippingRule['filter_postal_code'] : [];
        $isSamePostalCode = CountryState::isSamePostalCode($destination['zip'], $postalCodes) ?? false;
        $isFilterCategory = isset($settings['isFilterCategory']) ? $settings['isFilterCategory'] : false;
        $isFilterBrand = isset($settings['isFilterBrand']) ? $settings['isFilterBrand'] : false;
        $isFilterProduct = isset($settings['isFilterProduct']) ? $settings['isFilterProduct'] : false;

        $isAddressType = isset($settings['isAddressType']) ? $settings['isAddressType'] : false;
        $selectedAddressType = isset($settings['selected_address_type']) ? $settings['selected_address_type'] : false;

        $hasLocationFilter = ($filterCountry !== '' || !empty($stateProvince));
        $isSameCountry = (!empty($filterCountry) && in_array($destination['country'], $filterCountry));
        $isSameState   = (!empty($statesCode) && in_array($destination['state'], $statesCode));


        $isFilterWeight = $isFilterPrice = $isFilterQuantity = $isSameLocation = $categoriesResult = $brandsResult = $productsResult = $addressTypeResult = false;
        $isFilterWeightCheck = $isFilterPriceCheck = $isFilterQuantityCheck = $isSameLocationCheck = $categoriesResultCheck = $productsResultCheck = $brandsResultCheck = $addressTypeResultCheck = false;
        if (isset($shippingRule['isFilterWeight']) && $shippingRule['isFilterWeight']) {
            $weight = collect($items)->map(function ($item) {
                return $item['lineItemWeight'] * $item['piecesOfLineItem'] ?? 0;
            }) ?? 0;
            $totalWeight = collect($weight)->sum();
            if (isset($shippingRule['weight_from']) && $totalWeight >= $shippingRule['weight_from'] && isset($shippingRule['weight_to']) && ($totalWeight < $shippingRule['weight_to'] || $shippingRule['weight_to'] === '')) {
                $isFilterWeight = true;
            } else {
                $isFilterWeight = 2;
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
            } else {
                $isFilterPrice = 2;
            }
        } else {
            $isFilterPriceCheck = true;
        }
        if (isset($shippingRule['isFilterQuantity']) && $shippingRule['isFilterQuantity']) {
            $totalQuantity = collect($items)->sum('piecesOfLineItem') ?? 0;
            if (isset($shippingRule['quantity_from']) && $totalQuantity >= $shippingRule['quantity_from'] && isset($shippingRule['quantity_to']) && ($totalQuantity < $shippingRule['quantity_to'] || $shippingRule['quantity_to'] === '')) {
                $isFilterQuantity = true;
            } else {
                $isFilterQuantity = 2;
            }
        } else {
            $isFilterQuantityCheck = true;
        }
        $isLocationFilter = $settings['isLocationFilter'];
        if ($isLocationFilter && (!empty($filterCountry) || !empty($postalCodes))) {
            if (($isSameCountry && empty($statesCode)) || ($isSameCountry && $isSameState && empty($postalCodes)) || ($isSameCountry && $isSameState && $isSamePostalCode) || !empty($postalCodes) && $isSamePostalCode && empty($filterCountry) && empty($stateProvince)) {
                $isSameLocation = true;
            } else {
                $isSameLocation = 2;
            }
        } else {
            $isSameLocationCheck = true;
        }
        // Category, Brand and Product Check
        if ($isFilterCategory === true) {
            $categoriesResult = $this->applyRuleOnCategories($shippingRule, $cartItems, $origins, $destination, $statesProvinces);
            $categoriesResult = $categoriesResult['istrue'];
            if ($categoriesResult === false) {
                $categoriesResult = 2;
            }
        } else {
            $categoriesResultCheck = true;
        }
        if ($isFilterBrand === true) {
            $brandsResult = $this->applyRuleOnBrands($shippingRule, $cartItems, $origins, $destination, $statesProvinces);
            $brandsResult = $brandsResult['istrue'];
            if ($brandsResult === false) {
                $brandsResult = 2;
            }
        } else {
            $brandsResultCheck = true;
        }
        if ($isFilterProduct === true) {
            $productsResult = $this->applyRuleOnProducts($shippingRule, $cartItems, $origins, $destination, $statesProvinces);
            $productsResult = $productsResult['istrue'];
            if ($productsResult === false) {
                $productsResult = 2;
            }
        } else {
            $productsResultCheck = true;
        }

        // Is Address Type Filter
        if ($isAddressType === true) {
            if (($addressStatus == 'c' && $selectedAddressType == 1) || ($addressStatus == 'r' && $selectedAddressType == 2) || $selectedAddressType == 0) {
                $addressTypeResult = true;
            } else {
                $addressTypeResult = 2;
            }
        } else {
            $addressTypeResultCheck = true;
        }

        // All filter applied check
        if ($isAllFilterApplied == 1) {
            // If any of the filters are NOT applied
            if (
                $isFilterWeight === 2 || $isFilterPrice === 2 || $isFilterQuantity === 2 || $isSameLocation === 2
                || $categoriesResult === 2 || $brandsResult === 2 || $productsResult === 2 || $addressTypeResult === 2
            ) {
                return true;
            }
        }

        if (($isFilterWeight === true || $isFilterPrice === true || $isFilterQuantity === true || $isSameLocation === true || $categoriesResult === true || $brandsResult === true
            || $productsResult === true || $addressTypeResult === true) || ($isFilterWeightCheck && $isFilterPriceCheck && $isFilterQuantityCheck && $isSameLocationCheck
            && $categoriesResultCheck && $brandsResultCheck && $productsResultCheck && $addressTypeResultCheck)) {
            return false;
        }
        return true;
    }

    public function overrideHideMethods($shippingRule, $items, $destination, $maxShippingRateFilter, $quote, $formData, $storeData, $addressStatus)
    {
        $origins = isset($formData['lineItemData']['origin']) ? $formData['lineItemData']['origin'] : [];
        $cartItems = isset($formData['lineItemData']['items']) ? $formData['lineItemData']['items'] : [];
        $statesProvinces = CountryState::getCountryStatesProvinces($destination['country']);

        // --- Location filters ---
        $statesProvinces = CountryState::getCountryStatesProvinces($destination['country']);
        $settings = json_decode($shippingRule['filter_settings'], true);
        $isFilterMaxShippingRate = $settings['isFilterMaxShippingRate'];
        $isAllFilterApplied = isset($settings['is_all_filter_applied']) ? $settings['is_all_filter_applied'] : '';
        $stateProvince = (isset($settings['filter_state_province']) && !empty($settings['filter_state_province'])) ? $settings['filter_state_province'] : [];
        $filterCountry = isset($settings['filter_country']) ? $settings['filter_country'] : [];
        $statesCode = CountryState::getStateCode($statesProvinces, $stateProvince);

        $isFilterCategory = isset($settings['isFilterCategory']) ? $settings['isFilterCategory'] : false;
        $isFilterBrand = isset($settings['isFilterBrand']) ? $settings['isFilterBrand'] : false;
        $isFilterProduct = isset($settings['isFilterProduct']) ? $settings['isFilterProduct'] : false;

        $isAddressType = isset($settings['isAddressType']) ? $settings['isAddressType'] : false;
        $selectedAddressType = isset($settings['selected_address_type']) ? $settings['selected_address_type'] : false;

        $hasLocationFilter = ($filterCountry !== '' || !empty($stateProvince));
        $isSameCountry = (!empty($filterCountry) && in_array($destination['country'], $filterCountry));
        $isSameState   = (!empty($statesCode) && in_array($destination['state'], $statesCode));


        $isFilterWeight = $isFilterPrice = $isFilterQuantity = $isSameLocation = $categoriesResult = $brandsResult = $productsResult = $addressTypeResult = false;
        $isFilterWeightCheck = $isFilterPriceCheck = $isFilterQuantityCheck = $isSameLocationCheck = $categoriesResultCheck = $productsResultCheck = $brandsResultCheck = $addressTypeResultCheck = false;
        if (isset($shippingRule['isFilterWeight']) && $shippingRule['isFilterWeight']) {
            $weight = collect($items)->map(function ($item) {
                return $item['lineItemWeight'] * $item['piecesOfLineItem'] ?? 0;
            }) ?? 0;
            $totalWeight = collect($weight)->sum();
            if (isset($shippingRule['weight_from']) && $totalWeight >= $shippingRule['weight_from'] && isset($shippingRule['weight_to']) && ($totalWeight < $shippingRule['weight_to'] || $shippingRule['weight_to'] === '')) {
                $isFilterWeight = true;
            } else {
                $isFilterWeight = 2;
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
            } else {
                $isFilterPrice = 2;
            }
        } else {
            $isFilterPriceCheck = true;
        }
        if (isset($shippingRule['isFilterQuantity']) && $shippingRule['isFilterQuantity']) {
            $totalQuantity = collect($items)->sum('piecesOfLineItem') ?? 0;
            if (isset($shippingRule['quantity_from']) && $totalQuantity >= $shippingRule['quantity_from'] && isset($shippingRule['quantity_to']) && ($totalQuantity < $shippingRule['quantity_to'] || $shippingRule['quantity_to'] === '')) {
                $isFilterQuantity = true;
            } else {
                $isFilterQuantity = 2;
            }
        } else {
            $isFilterQuantityCheck = true;
        }
        $isLocationFilter = $settings['isLocationFilter'];
        if ($isLocationFilter && !empty($filterCountry)) {
            if (($isSameCountry && empty($stateProvince)) || ($isSameCountry && $isSameState && !empty($stateProvince))) {
                $isSameLocation = true;
            } else {
                $isSameLocation = 2;
            }
        } else {
            $isSameLocationCheck = true;
        }

        // Max shiiping rate filter check
        $orginalShippingRate = $quote['totalNetCharge']['Amount'];
        $orginalShippingRateExceeds = false;
        if ($maxShippingRateFilter < $orginalShippingRate) {
            $orginalShippingRateExceeds = true;
        } else {
            $orginalShippingRateExceeds = 2;
        }


        // Category, Brand and Product Check
        if ($isFilterCategory === true) {
            $categoriesResult = $this->applyRuleOnCategories($shippingRule, $cartItems, $origins, $destination, $statesProvinces);
            $categoriesResult = $categoriesResult['istrue'];
            if ($categoriesResult === false) {
                $categoriesResult = 2;
            }
        } else {
            $categoriesResultCheck = true;
        }
        if ($isFilterBrand === true) {
            $brandsResult = $this->applyRuleOnBrands($shippingRule, $cartItems, $origins, $destination, $statesProvinces);
            $brandsResult = $brandsResult['istrue'];
            if ($brandsResult === false) {
                $brandsResult = 2;
            }
        } else {
            $brandsResultCheck = true;
        }
        if ($isFilterProduct === true) {
            $productsResult = $this->applyRuleOnProducts($shippingRule, $cartItems, $origins, $destination, $statesProvinces);
            $productsResult = $productsResult['istrue'];
            if ($productsResult === false) {
                $productsResult = 2;
            }
        } else {
            $productsResultCheck = true;
        }

        // Is Address Type Filter
        if ($isAddressType === true) {
            if (($addressStatus == 'c' && $selectedAddressType == 1) || ($addressStatus == 'r' && $selectedAddressType == 2) || $selectedAddressType == 0) {
                $addressTypeResult = true;
            } else {
                $addressTypeResult = 2;
            }
        } else {
            $addressTypeResultCheck = true;
        }

        // All filter applied check
        if ($isAllFilterApplied == 1) {
            // If any of the filters are NOT applied
            if (
                $isFilterWeight === 2 || $isFilterPrice === 2 || $isFilterQuantity === 2 || $isSameLocation === 2
                || $categoriesResult === 2 || $brandsResult === 2 || $productsResult === 2 || $orginalShippingRateExceeds === 2
                || $addressTypeResult === 2
            ) {
                return true;
            }
        }

        if (($isFilterMaxShippingRate == false && $isFilterWeight === true || $isFilterPrice === true || $isFilterQuantity === true || $isSameLocation === true
                || $categoriesResult === true || $brandsResult === true || $productsResult === true || $addressTypeResult === true) || ($isFilterMaxShippingRate == false && $isFilterWeightCheck && $isFilterPriceCheck
                && $isFilterQuantityCheck && $isSameLocationCheck && $categoriesResultCheck && $brandsResultCheck && $productsResultCheck && $addressTypeResultCheck)
            || ($isFilterMaxShippingRate == true && $orginalShippingRateExceeds)
        ) {
            return false;
        }
        return true;
    }



    public function checkIsOverrideRuleApply($shippingRule, $items, $shipmentKey, $allOrigins, $destination, $maxShippingRateFilter, $quote, $formData, $storeData, $addressStatus)
    {
        $variants = [];
        $totalWeight = 0;
        $totalQuantity = 0;
        $totalPrice = 0;
        $isFilterWeight = $isFilterPrice = $isFilterQuantity = $isSameLocation = $categoriesResult = $brandsResult = $productsResult = $addressTypeResult = false;
        $isFilterWeightCheck = $isFilterPriceCheck = $isFilterQuantityCheck = $isSameLocationCheck = $categoriesResultCheck = $productsResultCheck = $brandsResultCheck = $addressTypeResultCheck = false;

        $origins = isset($formData['lineItemData']['origin']) ? $formData['lineItemData']['origin'] : [];
        $cartItems = isset($formData['lineItemData']['items']) ? $formData['lineItemData']['items'] : [];
        $statesProvinces = CountryState::getCountryStatesProvinces($destination['country']);

        // --- Location filters ---
        $statesProvinces = CountryState::getCountryStatesProvinces($destination['country']);
        $settings = json_decode($shippingRule['filter_settings'], true);
        $isAllFilterApplied = isset($settings['is_all_filter_applied']) ? $settings['is_all_filter_applied'] : '';
        $isFilterMaxShippingRate = $settings['isFilterMaxShippingRate'];
        $stateProvince = (isset($settings['filter_state_province']) && !empty($settings['filter_state_province'])) ? $settings['filter_state_province'] : [];
        $filterCountry = isset($settings['filter_country']) ? $settings['filter_country'] : [];
        $statesCode = CountryState::getStateCode($statesProvinces, $stateProvince);

        $isFilterCategory = isset($settings['isFilterCategory']) ? $settings['isFilterCategory'] : false;
        $isFilterBrand = isset($settings['isFilterBrand']) ? $settings['isFilterBrand'] : false;
        $isFilterProduct = isset($settings['isFilterProduct']) ? $settings['isFilterProduct'] : false;

        $isAddressType = isset($settings['isAddressType']) ? $settings['isAddressType'] : false;
        $selectedAddressType = isset($settings['selected_address_type']) ? $settings['selected_address_type'] : false;

        $hasLocationFilter = ($filterCountry !== '' || !empty($stateProvince));
        $isSameCountry = (!empty($filterCountry) && in_array($destination['country'], $filterCountry));
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


        if (isset($shippingRule['isFilterWeight']) && $shippingRule['isFilterWeight']) {
            $weight = collect($items)->map(function ($item) {
                return $item['lineItemWeight'] * $item['piecesOfLineItem'] ?? 0;
            }) ?? 0;
            // $totalWeight = collect($weight)->sum();
            if (isset($shippingRule['weight_from']) && $totalWeight >= $shippingRule['weight_from'] && isset($shippingRule['weight_to']) && ($totalWeight < $shippingRule['weight_to'] || $shippingRule['weight_to'] === '')) {
                $isFilterWeight = true;
            } else {
                $isFilterWeight = 2;
            }
        } else {
            $isFilterWeightCheck = true;
        }
        if (isset($shippingRule['isFilterPrice']) && $shippingRule['isFilterPrice']) {
            $price = collect($items)->map(function ($item) {
                return $item['lineItemPrice'] * $item['piecesOfLineItem'] ?? 0;
            }) ?? 0;
            // $totalPrice = collect($price)->sum() ?? 0;
            if (isset($shippingRule['price_from']) && $totalPrice >= $shippingRule['price_from'] && isset($shippingRule['price_to']) && ($totalPrice < $shippingRule['price_to'] || $shippingRule['price_to'] === '')) {
                $isFilterPrice = true;
            } else {
                $isFilterPrice = 2;
            }
        } else {
            $isFilterPriceCheck = true;
        }
        if (isset($shippingRule['isFilterQuantity']) && $shippingRule['isFilterQuantity']) {
            // $totalQuantity = collect($items)->sum('piecesOfLineItem') ?? 0;
            if (isset($shippingRule['quantity_from']) && $totalQuantity >= $shippingRule['quantity_from'] && isset($shippingRule['quantity_to']) && ($totalQuantity < $shippingRule['quantity_to'] || $shippingRule['quantity_to'] === '')) {
                $isFilterQuantity = true;
            } else {
                $isFilterQuantity = 2;
            }
        } else {
            $isFilterQuantityCheck = true;
        }
        // -----------------------

        $isLocationFilter = $settings['isLocationFilter'];
        if ($isLocationFilter == true && !empty($filterCountry)) {
            if (($isSameCountry && empty($stateProvince)) || ($isSameCountry && $isSameState && !empty($stateProvince))) {
                $isSameLocation = true;
            } else {
                $isSameLocation = 2;
            }
        } else {
            $isSameLocationCheck = true;
        }

        // Max shiiping rate filter check
        $orginalShippingRate = $quote['totalNetCharge']['Amount'];
        $orginalShippingRateExceeds = false;

        if ($maxShippingRateFilter < $orginalShippingRate) {
            $orginalShippingRateExceeds = true;
        }

        // Category, Brand and Product Check
        if ($isFilterCategory === true) {
            $categoriesResult = $this->applyRuleOnCategories($shippingRule, $cartItems, $origins, $destination, $statesProvinces);
            $categoriesResult = $categoriesResult['istrue'];
            if ($categoriesResult === false) {
                $categoriesResult = 2;
            }
        } else {
            $categoriesResultCheck = true;
        }
        if ($isFilterBrand === true) {
            $brandsResult = $this->applyRuleOnBrands($shippingRule, $cartItems, $origins, $destination, $statesProvinces);
            $brandsResult = $brandsResult['istrue'];
            if ($brandsResult === false) {
                $brandsResult = 2;
            }
        } else {
            $brandsResultCheck = true;
        }

        if ($isFilterProduct === true) {
            $productsResult = $this->applyRuleOnProducts($shippingRule, $cartItems, $origins, $destination, $statesProvinces);
            $productsResult = $productsResult['istrue'];
            if ($productsResult === false) {
                $productsResult = 2;
            }
        } else {
            $productsResultCheck = true;
        }

        // Is Address Type Filter
        if ($isAddressType === true) {
            if (($addressStatus == 'c' && $selectedAddressType == 1) || ($addressStatus == 'r' && $selectedAddressType == 2) || $selectedAddressType == 0) {
                $addressTypeResult = true;
            } else {
                $addressTypeResult = 2;
            }
        } else {
            $addressTypeResultCheck = true;
        }

        // If all filters are required but any one is not applied, return true
        if ($isAllFilterApplied == 1) {
            // If any of the filters are NOT applied
            if (
                $isFilterWeight === 2 || $isFilterPrice === 2 || $isFilterQuantity === 2 || $isSameLocation === 2
                || $categoriesResult === 2 || $brandsResult === 2 || $productsResult === 2 || $addressTypeResult === 2
            ) {
                return true;
            }
        }
        // --- Final decision (unchanged) ---
        if (
            ($isFilterMaxShippingRate == false && $isFilterWeight === true || $isFilterPrice === true || $isFilterQuantity === true || $isSameLocation === true
                || $categoriesResult === true || $brandsResult === true || $productsResult === true || $addressTypeResult === true) || ($isFilterMaxShippingRate == false && $isFilterWeightCheck && $isFilterPriceCheck
                && $isFilterQuantityCheck && $isSameLocationCheck && $categoriesResultCheck && $brandsResultCheck && $productsResultCheck && $addressTypeResultCheck)
            || ($isFilterMaxShippingRate == true && $orginalShippingRateExceeds)
        ) {
            return false;
        }
        return true;
    }


    public function checkIsSurchargeRuleApply($shippingRule, $items, $shipmentKey, $allOrigins, $destination, $maxShippingRateFilter, $formData, $storeData, $addressStatus)
    {
        $origins = isset($formData['lineItemData']['origin']) ? $formData['lineItemData']['origin'] : [];
        $cartItems = isset($formData['lineItemData']['items']) ? $formData['lineItemData']['items'] : [];

        if ($shippingRule['rule_type'] == self::FLAT_RATE) {
            $cartItems = [];
            $allCartItems = isset($formData['lineItemData']['items']) ? $formData['lineItemData']['items'] : [];
            foreach ($allCartItems as $key => $item) {
                if (isset($item['locationId']) && $item['locationId'] == $shipmentKey) {
                    $cartItems[$key] = $item;
                }
            }
        }

        $statesProvinces = CountryState::getCountryStatesProvinces($destination['country']);

        $settings = json_decode($shippingRule['filter_settings'], true);
        $isAllFilterApplied = isset($settings['is_all_filter_applied']) ? $settings['is_all_filter_applied'] : '';

        $variants = [];
        $totalWeight = 0;
        $totalQuantity = 0;
        $totalPrice = 0;
        $isFilterWeight = $isFilterPrice = $isFilterQuantity = $isSameLocation = $categoriesResult = $brandsResult = $productsResult = $addressTypeResult = false;
        $isFilterWeightCheck = $isFilterPriceCheck = $isFilterQuantityCheck = $isSameLocationCheck = $categoriesResultCheck = $productsResultCheck = $brandsResultCheck = $addressTypeResultCheck = false;

        $statesProvinces = CountryState::getCountryStatesProvinces($destination['country']);
        $stateProvince = (isset($settings['filter_state_province']) && !empty($settings['filter_state_province'])) ? $settings['filter_state_province'] : [];
        $filterCountry = isset($settings['filter_country']) ? $settings['filter_country'] : [];
        $statesCode = CountryState::getStateCode($statesProvinces, $stateProvince);

        $isFilterCategory = isset($settings['isFilterCategory']) ? $settings['isFilterCategory'] : false;
        $isFilterBrand = isset($settings['isFilterBrand']) ? $settings['isFilterBrand'] : false;
        $isFilterProduct = isset($settings['isFilterProduct']) ? $settings['isFilterProduct'] : false;

        $isAddressType = isset($settings['isAddressType']) ? $settings['isAddressType'] : false;
        $selectedAddressType = isset($settings['selected_address_type']) ? $settings['selected_address_type'] : false;

        $hasLocationFilter = ($filterCountry !== '' || !empty($stateProvince));
        $isSameCountry = (!empty($filterCountry) && in_array($destination['country'], $filterCountry));
        $isSameState   = (!empty($statesCode) && in_array($destination['state'], $statesCode));
        $products = [];


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
        if (isset($shippingRule['isFilterWeight']) && $shippingRule['isFilterWeight']) {
            $weight = collect($items)->map(function ($item) {
                return $item['lineItemWeight'] * $item['piecesOfLineItem'] ?? 0;
            }) ?? 0;
            // $totalWeight = collect($weight)->sum();
            if (isset($shippingRule['weight_from']) && $totalWeight >= $shippingRule['weight_from'] && isset($shippingRule['weight_to']) && ($totalWeight < $shippingRule['weight_to'] || $shippingRule['weight_to'] === '')) {
                $isFilterWeight = true;
            } else {
                $isFilterWeight = 2;
            }
        } else {
            $isFilterWeightCheck = true;
        }
        if (isset($shippingRule['isFilterPrice']) && $shippingRule['isFilterPrice']) {
            $price = collect($items)->map(function ($item) {
                return $item['lineItemPrice'] * $item['piecesOfLineItem'] ?? 0;
            }) ?? 0;
            // $totalPrice = collect($price)->sum() ?? 0;
            if (isset($shippingRule['price_from']) && $totalPrice >= $shippingRule['price_from'] && isset($shippingRule['price_to']) && ($totalPrice < $shippingRule['price_to'] || $shippingRule['price_to'] === '')) {
                $isFilterPrice = true;
            } else {
                $isFilterPrice = 2;
            }
        } else {
            $isFilterPriceCheck = true;
        }
        if (isset($shippingRule['isFilterQuantity']) && $shippingRule['isFilterQuantity']) {
            if (isset($shippingRule['quantity_from']) && $totalQuantity >= $shippingRule['quantity_from'] && isset($shippingRule['quantity_to']) && ($totalQuantity < $shippingRule['quantity_to'] || $shippingRule['quantity_to'] === '')) {
                $isFilterQuantity = true;
            } else {
                $isFilterQuantity = 2;
            }
        } else {
            $isFilterQuantityCheck = true;
        }

        $isLocationFilter = isset($settings['isLocationFilter']) ? $settings['isLocationFilter'] : false;
        // $isLocationFilter = $settings['isLocationFilter'];
        if ($isLocationFilter && (!empty($filterCountry) || !empty($postalCodes))) {
            if (($isSameCountry && empty($statesCode)) || ($isSameCountry && $isSameState && empty($postalCodes)) || ($isSameCountry && $isSameState && $isSamePostalCode) || !empty($postalCodes) && $isSamePostalCode && empty($filterCountry) && empty($stateProvince)) {
                $isSameLocation = true;
            } else {
                $isSameLocation = 2;
            }
        } else {
            $isSameLocationCheck = true;
        }

        // Category, Brand and Product Check
        if ($isFilterCategory === true) {
            $categoriesResult = $this->applyRuleOnCategories($shippingRule, $cartItems, $origins, $destination, $statesProvinces);
            $products = $categoriesResult['filterProducts'];
            $categoriesResult = $categoriesResult['istrue'];
            if ($categoriesResult === false) {
                $categoriesResult = 2;
            }
        } else {
            $categoriesResultCheck = true;
        }
        if ($isFilterBrand === true) {
            $brandsResult = $this->applyRuleOnBrands($shippingRule, $cartItems, $origins, $destination, $statesProvinces);
            $products = $brandsResult['filterProducts'];
            $brandsResult = $brandsResult['istrue'];
            if ($brandsResult === false) {
                $brandsResult = 2;
            }
        } else {
            $brandsResultCheck = true;
        }
        if ($isFilterProduct === true) {
            $productsResult = $this->applyRuleOnProducts($shippingRule, $cartItems, $origins, $destination, $statesProvinces);
            $products = $productsResult['filterProducts'];
            $productsResult = $productsResult['istrue'];
            if ($productsResult === false) {
                $productsResult = 2;
            }
        } else {
            $productsResultCheck = true;
        }
        // Is Address Type Filter
        if ($isAddressType === true) {
            if (($addressStatus == 'c' && $selectedAddressType == 1) || ($addressStatus == 'r' && $selectedAddressType == 2) || $selectedAddressType == 0) {
                $addressTypeResult = true;
            } else {
                $addressTypeResult = 2;
            }
        } else {
            $addressTypeResultCheck = true;
        }

        // Condition for isAllFilterApplied
        if ($isAllFilterApplied == 1) {
            if (
                $isFilterWeight === 2 || $isFilterPrice === 2 || $isFilterQuantity === 2 || $isSameLocation === 2
                || $categoriesResult === 2 || $brandsResult === 2 || $productsResult === 2 || $addressTypeResult === 2
            ) {
                return [
                    'istrue' => true,
                    'products' => [],
                ];
            }
        }
        if (($isFilterWeight === true || $isFilterPrice === true || $isFilterQuantity === true || $isSameLocation === true || $categoriesResult === true || $brandsResult === true
            || $productsResult === true || $addressTypeResult === true) || ($isFilterWeightCheck && $isFilterPriceCheck && $isFilterQuantityCheck && $isSameLocationCheck
            && $categoriesResultCheck && $brandsResultCheck && $productsResultCheck && $addressTypeResultCheck)) {
            if ($shippingRule['rule_type'] == self::FLAT_RATE) {
                $products = [];
                foreach ($items as $key => $item) {
                    if (isset($item['locationId']) && $item['locationId'] == $shipmentKey) {
                        $products[$key] = $item;
                    }
                }
                // $products = $items;
            }
            return [
                'istrue' => false,
                'products' => $products,
            ];
        }
        return [
            'istrue' => true,
            'products' => [],
        ];
    }


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

    public function checkProdExistInShipment($rule, $cartItems, $originKey, $allOrigins, $destination, $maxShippingRateFilter, $formData, $storeData, $addressStatus)
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
                            $isRuletrue = $this->checkIsSurchargeRuleApply($rule, $products, $originKey, $allOrigins, $destination, $maxShippingRateFilter, $formData, $storeData, $addressStatus);
                            return $isRuletrue['istrue'];
                        }
                        // Check: apply rule to Brands
                    } elseif ($rule['apply_rule_to'] == 2) {
                        $brandsIds = array_column($products, 'brand_id') ?? [];
                        $filterBrands = collect($brandsIds)->intersect(empty($rule['brands']) ? [] : $rule['brands']) ?? [];

                        if (!empty($filterBrands->toArray())) {
                            $isRuletrue = $this->checkIsSurchargeRuleApply($rule, $products, $originKey, $allOrigins, $destination, $maxShippingRateFilter, $formData, $storeData, $addressStatus);
                            return $isRuletrue['istrue'];
                        }
                        // Check: apply rule to Individual Products
                    } elseif ($rule['apply_rule_to'] == 3) {
                        $productIds = array_column($products, 'product_id') ?? [];
                        $productIdsArray = array_column($rule['products'], 'value');

                        foreach ($productIds as $prod) {
                            if (in_array($prod, $productIdsArray)) {
                                $isRuletrue = $this->checkIsSurchargeRuleApply($rule, $products, $originKey, $allOrigins, $destination, $maxShippingRateFilter, $formData, $storeData, $addressStatus);
                                return $isRuletrue['istrue'];
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
        $LCSShippingRuleType = self::LARGE_CART_SETTINGS;
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


    // Same code from the GetRatesController

    /**
     * Apply shipping rule on categories
     * @param $rule
     * @param $cartItems
     * @param $origins
     * @param $destination
     * @param $statesProvinces
     * @param $formatReq - Pass by reference to allow modifications
     * @param $connectionSettings - Pass by reference to allow modifications
     * @return bool
     */
    public function applyRuleOnCategories($rule, $cartItems, $origins, $destination, $statesProvinces, &$formatReq = null, &$connectionSettings = null)
    {
        $restrictedCategories = isset($rule['categories']) ? $rule['categories'] : [];
        $stateProvince = isset($rule['filter_state_province']) && !empty($rule['filter_state_province']) ? $rule['filter_state_province'] : [];

        if (!empty($restrictedCategories)) {
            $statesCode = CountryState::getStateCode($statesProvinces, $stateProvince);
            $categoriesIds = array_column($cartItems, 'categories_id');
            $flattenedCategoriesIds = array_values(array_merge(...$categoriesIds)) ?? [];
            $istrue = false;

            $filterCategories = collect($restrictedCategories)->intersect($flattenedCategoriesIds) ?? [];
            foreach ($filterCategories as $categoryId) {
                $categoriesProducts = collect($cartItems)->filter(function ($item) use ($categoryId) {
                    return in_array($categoryId, $item['categories_id']);
                })->toArray() ?? [];
            }

            if (!empty($categoriesProducts)) {
                return [
                    'istrue' => true,
                    'filterProducts' => $filterCategories,
                ];
            }
        }
        return [
            'istrue' => false,
            'filterProducts' => [],
        ];
    }

    /**
     * Apply shipping rule on brands
     * @param $rule
     * @param $cartItems
     * @param $origins
     * @param $destination
     * @param $statesProvinces
     * @param $formatReq - Pass by reference to allow modifications
     * @param $connectionSettings - Pass by reference to allow modifications
     * @return bool
     */
    public function applyRuleOnBrands($rule, $cartItems, $origins, $destination, $statesProvinces, &$formatReq = null, &$connectionSettings = null)
    {
        $restrictedBrands = isset($rule['brands']) ? $rule['brands'] : [];
        $stateProvince = isset($rule['filter_state_province']) && !empty($rule['filter_state_province']) ? $rule['filter_state_province'] : [];
        $istrue = false;
        if (!empty($restrictedBrands)) {
            $statesCode = CountryState::getStateCode($statesProvinces, $stateProvince);

            foreach ($restrictedBrands as $brandId) {
                $matched = collect($cartItems)
                    ->where('brand_id', $brandId)
                    ->all() ?? [];

                if (!empty($matched)) {
                    $filterBrands[] = $matched;
                }
            }

            if (!empty($filterBrands)) {
                return [
                    'istrue' => true,
                    'filterProducts' => $filterBrands,
                ];
            }
        }
        return [
            'istrue' => false,
            'filterProducts' => [],
        ];
    }

    /**
     * Apply shipping rule on products
     * @param $rule
     * @param $cartItems
     * @param $origins
     * @param $destination
     * @param $statesProvinces
     * @param $formatReq - Pass by reference to allow modifications
     * @param $connectionSettings - Pass by reference to allow modifications
     * @return bool
     */
    public function applyRuleOnProducts($rule, $cartItems, $origins, $destination, $statesProvinces, &$formatReq = null, &$connectionSettings = null)
    {
        $settings = isset($rule['filter_settings']) ? json_decode($rule['filter_settings'], true) : [];
        $restrictedProducts = isset($settings['filter_products']) ? $settings['filter_products'] : [];
        $stateProvince = isset($rule['filter_state_province']) && !empty($rule['filter_state_province']) ? $rule['filter_state_province'] : [];
        $istrue = false;

        if (!empty($restrictedProducts)) {
            $statesCode = CountryState::getStateCode($statesProvinces, $stateProvince);
            $filterProducts = [];

            foreach ($restrictedProducts as $productId) {
                $matched = collect($cartItems)
                    ->where('product_id', $productId['key'])
                    ->all();

                if (!empty($matched)) {
                    $filterProducts[] = $matched;
                }
            }

            if (!empty($filterProducts)) {

                return [
                    'istrue' => true,
                    'filterProducts' => $filterProducts,
                ];
            }
        }
        return [
            'istrue' => false,
            'filterProducts' => [],
        ];
    }

    /**
     * Check if shipping rule restriction applies
     * @param $rule
     * @param $origins
     * @param $destination
     * @param $statesCode
     * @param $products
     * @param $formatReq - Pass by reference to allow modifications
     * @param $connectionSettings - Pass by reference to allow modifications
     * @return bool
     */
    public function checkRuleRestriction($rule, $origins, $destination, $statesCode, $products, &$formatReq = null, &$connectionSettings = null)
    {
        $filterCountry = isset($rule['filter_country']) ? $rule['filter_country'] : [];
        $postalCodes = isset($rule['filter_postal_code']) ? $rule['filter_postal_code'] : [];
        $warehouses = isset($rule['warehouses']) ? $rule['warehouses'] : [];
        $isSameOrigin = false;
        $ruleType = !empty($rule['rule_type']) ? (int)$rule['rule_type'] : null;
        $applyTo = !empty($rule['apply_to']) ? (int)$rule['apply_to'] : null;
        $provider = isset($rule['filter_provider']) ? $rule['filter_provider'] : '';

        if ($ruleType == self::HIDE_DELIVERY_ESTIMATES) {
            $this->applyHideDeliveryEstimatesRuleLocal($rule, $connectionSettings);
            return false;
        }

        if ($ruleType == self::HIDE_METHOD && $applyTo == 2) {
            if ($connectionSettings !== null) {
                foreach ($connectionSettings as $key => $carrier) {
                    if ($key == $provider) {
                        unset($connectionSettings[$key]);
                    }
                }
            }
            return false;
        }

        $isSameCountry = $destination['country'] == $filterCountry ?? false;
        $isSameState = in_array($destination['state'], $statesCode) ?? false;
        $isSamePostalCode = CountryState::isSamePostalCode($destination['zip'], $postalCodes) ?? false;
        if (!empty($origins)) {
            foreach ($origins as $origin) {
                $isSameOrigin = in_array($origin['senderZip'], $warehouses) ?? false;
            }
        }

        Log::info('Shipping rule applied: ' . json_encode($rule));

        if ($isSameCountry && $isSameState && $isSamePostalCode && $ruleType == self::RESTRICT_POSTAL_CODE) {
            return false;
        } elseif ($isSameCountry && $isSameState && ($ruleType == self::RESTRICT_STATE || $ruleType == self::FLAT_RATE)) {
            // Apply Flat Rate Shipping Rule for country and state
            if ($ruleType == self::FLAT_RATE && !empty($products)) {
                $this->applyFlatRatesShippingRuleLocal($products, $rule, $formatReq);
            }
            return false;
        } elseif ($isSameCountry && ($ruleType == self::RESTRICT_COUNTRY || $ruleType == self::FLAT_RATE && empty($rule['filter_state_province']))) {
            // Apply Flat Rate Shipping Rule for only country
            if ($ruleType == self::FLAT_RATE && !empty($products)) {
                $this->applyFlatRatesShippingRuleLocal($products, $rule, $formatReq);
            }
            return false;
        } else {
            return true;
        }
    }

    /**
     * Apply flat rates shipping rule
     * @param $products
     * @param $rule
     * @param $formatReq - Pass by reference to allow modifications
     */
    public function applyFlatRatesShippingRuleLocal($products, $rule, &$formatReq = null)
    {
        if ($formatReq === null) {
            return;
        }

        foreach ($products as $key => $product) {
            // check to assign cheapest flat rate rule
            $flatRate = isset($formatReq['lineItemData']['items'][$key]['flatRate']) ? $formatReq['lineItemData']['items'][$key]['flatRate'] : null;
            if ($rule['filter_flat_shipping_rate'] <= $flatRate) {

                $formatReq['lineItemData']['items'][$key]['isFreeShipping'] = true;
                $formatReq['lineItemData']['items'][$key]['flatRateUuid'] = $rule['uuid'];
                $formatReq['lineItemData']['items'][$key]['flatRateRule'] = $rule['id'];
                $formatReq['lineItemData']['items'][$key]['flatRate'] = $rule['filter_flat_shipping_rate'];
            } elseif ($flatRate === null) {

                $formatReq['lineItemData']['items'][$key]['isFreeShipping'] = true;
                $formatReq['lineItemData']['items'][$key]['flatRateUuid'] = $rule['uuid'];
                $formatReq['lineItemData']['items'][$key]['flatRateRule'] = $rule['id'];
                $formatReq['lineItemData']['items'][$key]['flatRate'] = $rule['filter_flat_shipping_rate'];
            }
        }
    }

    /**
     * Apply hide delivery estimates rule
     * @param $rule
     * @param $connectionSettings - Pass by reference to allow modifications
     */
    public function applyHideDeliveryEstimatesRuleLocal($rule, &$connectionSettings = null)
    {
        if ($connectionSettings === null) {
            return;
        }

        if (isset($rule['filter_provider']) && $rule['filter_provider'] != null && isset($connectionSettings[$rule['filter_provider']]['quote_settings'])) {
            $quoteSettings = $connectionSettings[$rule['filter_provider']]['quote_settings'];
            $quoteSettings['delivery_estimate_options'] = 1;
            $connectionSettings[$rule['filter_provider']]['quote_settings'] = $quoteSettings;
        }
    }
}
