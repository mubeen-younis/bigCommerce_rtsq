<?php

namespace App\Models;

use App\Constants\Constant;
use App\Helpers\Helper;
use App\Helpers\Helpers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Psy\Util\Str;

class ShippingRule extends Model
{


    protected $guarded = [];
    protected $table = "shipping_rules";


    /**
     * @param $storeId
     * @return array
     */
    public static function getStoreShippingRules($storeId, $ruleType = ''): array
    {
        if (!empty($ruleType)) {
            $rules = optional(self::where(['store_id' => $storeId, 'rule_type' => $ruleType])->get())->toArray() ?? [];
        } else {
            $rules = optional(self::where('store_id', $storeId)->get())->toArray() ?? [];
        }

        foreach ($rules as $key => $rule) {
            $rules[$key] = self::shippingRuleMappingByRuleType($rule);
        }
        return $rules;
    }


    public static function deleteShippingRule($uuid)
    {
        $id = optional(self::where('uuid', $uuid)->first())->id;
        if (!blank($id)) {
            self::where('uuid', $uuid)->delete();
        }
    }


    public static function updateShippingRuleProduct($id)
    {
        ProductSetting::updateShippingRuleProduct($id);
    }

    /**
     * @param $shippingRuleItems
     * @return array
     */
    public static function setShippingRule($shippingRuleItems): array
    {
        if (blank($shippingRuleItems)) {
            return [];
        }
        $ruleItemsByShippingRule = [];
        foreach ($shippingRuleItems as $item) {
            $ruleItemsByShippingRule[$item['shipping_rule']][] = $item;
        }
        $rate = 0;
        $response = [];
        $title = [];
        foreach ($ruleItemsByShippingRule as $shippingRuleId => $rule) {
            $ruleDetail = self::getShippingRuleDetail($shippingRuleId);
            $response[0]['title'] = $title[] = $ruleDetail['checkout_description'];
            if ($ruleDetail['rate_x_quantity']) {
                $rate += self::getSumAftermultipItemRulewithQty($rule, $ruleDetail['rate']);
            } else {
                $rate += $ruleDetail['rate'];
            }
        }
        if (count($ruleItemsByShippingRule) > 1) {
            if (count(array_unique($title)) == 1) {
                $response[0]['title'] = $title[0] ?? "Shipping";
            } else {
                $response[0]['title'] = "Shipping";
            }
        }
        $response[0]['rate'] = $rate;
        $response[0]['code'] = "shippingRule";
        return $response;
    }


    /**
     * @param $id
     * @return array
     */
    public static function getShippingRuleDetail($id): array
    {
        return optional(self::where('id', $id)->first())->toArray() ?? [];
    }


    /**
     * @param $uuid
     * @return array
     */
    public static function getShippingRuleDetailByUuid($uuid)
    {
        return self::where('uuid', $uuid)->first();
    }


    /**
     * @param $rule
     * @param $rate
     * @return float|int
     */
    public static function getSumAftermultipItemRulewithQty($rule, $rate)
    {
        $noOfQuantityInRule = collect($rule)->sum('piecesOfLineItem');
        return $noOfQuantityInRule * $rate;
    }


    /**
     * @param $shippingRuleData
     * @return mixed
     */
    public static function saveOrUpdateShippingRule($shippingRuleData)
    {
        if (isset($shippingRuleData['uuid'])) {
            $shippingRule = self::getShippingRuleDetailByUuid($shippingRuleData['uuid']);
            if (blank($shippingRule)) {
                return [
                    'error' => true,
                    'message' => 'Shipping Rule is not found.',
                    'data' => []
                ];
            }

            $message = 'Shipping Rule is updated successfully.';
            $save = 0;
        } else {
            $shippingRule = new self();
            $shippingRule->uuid = Helpers::getUuid();
            $message = 'Shipping Rule is added successfully.';
            $save = 1;
        }

        $ruleType = isset($shippingRuleData['rule_type']) && !empty($shippingRuleData['rule_type']) ? $shippingRuleData['rule_type'] : null;

        if ($ruleType != null) {
            switch ($ruleType) {
                case 1:
                    $shippingRule->filter_name = $shippingRuleData['filter_country'] ?? '';
                    $settings = [
                        "filter_categories" => $shippingRuleData['filter_categories'] ?? [],
                        "filter_products" => $shippingRuleData['filter_products'] ?? [],
                        "filter_brands" => $shippingRuleData['filter_brands'] ?? [],
                        "apply_rule_to" => $shippingRuleData['apply_rule_to'] ?? '',
                        "isFilterCategory" => $shippingRuleData['isFilterCategory'] ?? '',
                        "isFilterBrand" => $shippingRuleData['isFilterBrand'] ?? '',
                        "isFilterProduct" => $shippingRuleData['isFilterProduct'] ?? '',
                    ];
                    $shippingRule->filter_settings = json_encode($settings) ?? '';
                    break;
                case 2:
                    $shippingRule->filter_name = $shippingRuleData['filter_provider'] ?? '';
                    $settings = [
                        "isFilterWeight" => $shippingRuleData['isFilterWeight'] ?? false,
                        "isFilterPrice" => $shippingRuleData['isFilterPrice'] ?? false,
                        "isFilterQuantity" => $shippingRuleData['isFilterQuantity'] ?? false,
                        "weightFrom" => $shippingRuleData['weight_from'] ?? '',
                        "weightTo" => $shippingRuleData['weight_to'] ?? '',
                        "priceFrom" => $shippingRuleData['price_from'] ?? '',
                        "priceTo" => $shippingRuleData['price_to'] ?? '',
                        "quantityFrom" => $shippingRuleData['quantity_from'] ?? '',
                        "quantityTo" => $shippingRuleData['quantity_to'] ?? '',
                        "filter_categories" => $shippingRuleData['filter_categories'] ?? [],
                        "filter_products" => $shippingRuleData['filter_products'] ?? [],
                        "filter_brands" => $shippingRuleData['filter_brands'] ?? [],
                        "apply_rule_to" => $shippingRuleData['apply_rule_to'] ?? '',
                        "filter_country" => $shippingRuleData['filter_country'] ?? '',
                        "filter_state_province" => $shippingRuleData['filter_state_province'] ?? '',
                        "isLocationFilter" => $shippingRuleData['isLocationFilter'] ?? '',
                        "isFilterCategory" => $shippingRuleData['isFilterCategory'] ?? '',
                        "isFilterBrand" => $shippingRuleData['isFilterBrand'] ?? '',
                        "isFilterProduct" => $shippingRuleData['isFilterProduct'] ?? '',
                        "is_all_filter_applied" => $shippingRuleData['is_all_filter_applied'] ?? '',
                    ];
                    $shippingRule->filter_settings = json_encode($settings) ?? '';
                    break;
                case 3:
                    $shippingRule->filter_name = $shippingRuleData['filter_country'] ?? '';
                    $settings = [
                        "filter_categories" => $shippingRuleData['filter_categories'] ?? [],
                        "filter_products" => $shippingRuleData['filter_products'] ?? [],
                        "filter_brands" => $shippingRuleData['filter_brands'] ?? [],
                        "apply_rule_to" => $shippingRuleData['apply_rule_to'] ?? '',
                        "filter_state_province" => $shippingRuleData['filter_state_province'] ?? '',
                        "isFilterCategory" => $shippingRuleData['isFilterCategory'] ?? '',
                        "isFilterBrand" => $shippingRuleData['isFilterBrand'] ?? '',
                        "isFilterProduct" => $shippingRuleData['isFilterProduct'] ?? '',
                    ];
                    $shippingRule->filter_settings = json_encode($settings) ?? '';
                    break;
                case 4:
                    $shippingRule->filter_name = $shippingRuleData['filter_country'] ?? '';
                    $settings = [
                        "filter_categories" => $shippingRuleData['filter_categories'] ?? [],
                        "filter_products" => $shippingRuleData['filter_products'] ?? [],
                        "filter_brands" => $shippingRuleData['filter_brands'] ?? [],
                        "apply_rule_to" => $shippingRuleData['apply_rule_to'] ?? '',
                        "filter_state_province" => $shippingRuleData['filter_state_province'] ?? '',
                        "filter_postal_code" => $shippingRuleData['filter_postal_code'] ?? '',
                        "isFilterCategory" => $shippingRuleData['isFilterCategory'] ?? '',
                        "isFilterBrand" => $shippingRuleData['isFilterBrand'] ?? '',
                        "isFilterProduct" => $shippingRuleData['isFilterProduct'] ?? '',
                    ];
                    $shippingRule->filter_settings = json_encode($settings) ?? '';
                    break;
                case 5:
                    $shippingRule->filter_name = $shippingRuleData['filter_country'] ?? '';
                    $settings = [
                        "filter_categories" => $shippingRuleData['filter_categories'] ?? [],
                        "filter_products" => $shippingRuleData['filter_products'] ?? [],
                        "filter_brands" => $shippingRuleData['filter_brands'] ?? [],
                        "apply_rule_to" => $shippingRuleData['apply_rule_to'] ?? '',
                        "warehouses" => $shippingRuleData['warehouses'] ?? '',
                        "isFilterCategory" => $shippingRuleData['isFilterCategory'] ?? '',
                        "isFilterBrand" => $shippingRuleData['isFilterBrand'] ?? '',
                        "isFilterProduct" => $shippingRuleData['isFilterProduct'] ?? '',
                    ];
                    $shippingRule->filter_settings = json_encode($settings) ?? '';
                    break;
                case 6:
                    $shippingRule->filter_name = $shippingRuleData['filter_provider'] ?? '';
                    $settings = [
                        "isFilterWeight" => $shippingRuleData['isFilterWeight'] ?? false,
                        "isFilterPrice" => $shippingRuleData['isFilterPrice'] ?? false,
                        "isFilterQuantity" => $shippingRuleData['isFilterQuantity'] ?? false,
                        "weightFrom" => $shippingRuleData['weight_from'] ?? '',
                        "weightTo" => $shippingRuleData['weight_to'] ?? '',
                        "priceFrom" => $shippingRuleData['price_from'] ?? '',
                        "priceTo" => $shippingRuleData['price_to'] ?? '',
                        "quantityFrom" => $shippingRuleData['quantity_from'] ?? '',
                        "quantityTo" => $shippingRuleData['quantity_to'] ?? '',
                        "filter_services" => $shippingRuleData['filter_services'] ?? [],
                        "service_rates" => $shippingRuleData['service_rates'] ?? '',
                        "filter_categories" => $shippingRuleData['filter_categories'] ?? [],
                        "filter_products" => $shippingRuleData['filter_products'] ?? [],
                        "filter_brands" => $shippingRuleData['filter_brands'] ?? [],
                        "apply_rule_to" => $shippingRuleData['apply_rule_to'] ?? '',
                        "filter_country" => $shippingRuleData['filter_country'] ?? '',
                        "filter_state_province" => $shippingRuleData['filter_state_province'] ?? '',
                        "filter_max_shipping_rate" => $shippingRuleData['filter_max_shipping_rate'] ?? '',
                        "isFilterMaxShippingRate" => $shippingRuleData['isFilterMaxShippingRate'] ?? '',
                        "isLocationFilter" => $shippingRuleData['isLocationFilter'] ?? '',
                        "isFilterCategory" => $shippingRuleData['isFilterCategory'] ?? '',
                        "isFilterBrand" => $shippingRuleData['isFilterBrand'] ?? '',
                        "isFilterProduct" => $shippingRuleData['isFilterProduct'] ?? '',
                        "is_all_filter_applied" => $shippingRuleData['is_all_filter_applied'] ?? '',
                    ];
                    $shippingRule->filter_settings = json_encode($settings) ?? '';
                    break;
                case 7:
                    $shippingRule->filter_name = $shippingRuleData['filter_provider'] ?? '';
                    $settings = [
                        "filter_categories" => $shippingRuleData['filter_categories'] ?? '',
                        "filter_products" => $shippingRuleData['filter_products'] ?? '',
                        "filter_brands" => $shippingRuleData['filter_brands'] ?? '',
                        "apply_rule_to" => $shippingRuleData['apply_rule_to'] ?? '',
                        "isFilterCategory" => $shippingRuleData['isFilterCategory'] ?? '',
                        "isFilterBrand" => $shippingRuleData['isFilterBrand'] ?? '',
                        "isFilterProduct" => $shippingRuleData['isFilterProduct'] ?? '',

                    ];
                    $shippingRule->filter_settings = json_encode($settings) ?? '';
                    break;
                case 8:
                    $shippingRule->filter_name = $shippingRuleData['filter_provider'] ?? '';
                    $settings = [
                        "isFilterWeight" => $shippingRuleData['isFilterWeight'] ?? false,
                        "isFilterPrice" => $shippingRuleData['isFilterPrice'] ?? false,
                        "isFilterQuantity" => $shippingRuleData['isFilterQuantity'] ?? false,
                        "weightFrom" => $shippingRuleData['weight_from'] ?? '',
                        "weightTo" => $shippingRuleData['weight_to'] ?? '',
                        "priceFrom" => $shippingRuleData['price_from'] ?? '',
                        "priceTo" => $shippingRuleData['price_to'] ?? '',
                        "quantityFrom" => $shippingRuleData['quantity_from'] ?? '',
                        "quantityTo" => $shippingRuleData['quantity_to'] ?? '',
                        "filter_categories" => $shippingRuleData['filter_categories'] ?? [],
                        "filter_products" => $shippingRuleData['filter_products'] ?? [],
                        "filter_brands" => $shippingRuleData['filter_brands'] ?? [],
                        "apply_rule_to" => $shippingRuleData['apply_rule_to'] ?? '',
                        "service_rates" => $shippingRuleData['service_rates'] ?? '',
                        "filter_country" => $shippingRuleData['filter_country'] ?? '',
                        "filter_state_province" => $shippingRuleData['filter_state_province'] ?? '',
                        "isLocationFilter" => $shippingRuleData['isLocationFilter'] ?? '',
                        "isFilterCategory" => $shippingRuleData['isFilterCategory'] ?? '',
                        "isFilterBrand" => $shippingRuleData['isFilterBrand'] ?? '',
                        "isFilterProduct" => $shippingRuleData['isFilterProduct'] ?? '',
                        "is_all_filter_applied" => $shippingRuleData['is_all_filter_applied'] ?? '',
                    ];
                    $shippingRule->filter_settings = json_encode($settings) ?? '';
                    break;
                case 9:
                    $settings = [
                        "max_items" => $shippingRuleData['max_items'] ?? null,
                        "max_package_weight" => $shippingRuleData['max_package_weight'] ?? null,
                    ];
                    $shippingRule->filter_settings = json_encode($settings) ?? '';
                    break;
                case 10:
                    $shippingRule->filter_name = json_encode($shippingRuleData['filter_country'] ?? []);
                    $settings = [
                        "filter_categories" => $shippingRuleData['filter_categories'] ?? [],
                        "filter_products" => $shippingRuleData['filter_products'] ?? [],
                        "filter_brands" => $shippingRuleData['filter_brands'] ?? [],
                        "apply_rule_to" => $shippingRuleData['apply_rule_to'] ?? '',
                        "filter_state_province" => $shippingRuleData['filter_state_province'] ?? '',
                        "filter_flat_shipping_rate" => $shippingRuleData['filter_flat_shipping_rate'] ?? '',
                        "isFilterFlatPrice" => $shippingRuleData['isFilterFlatPrice'] ?? '',
                        "isLocationFilter" => $shippingRuleData['isLocationFilter'] ?? '',
                        "isFilterCategory" => $shippingRuleData['isFilterCategory'] ?? '',
                        "isFilterBrand" => $shippingRuleData['isFilterBrand'] ?? '',
                        "isFilterProduct" => $shippingRuleData['isFilterProduct'] ?? '',
                    ];
                    $shippingRule->filter_settings = json_encode($settings) ?? '';
                    break;
            }

            $shippingRule->rule_name = $shippingRuleData['rule_name'] ?? '';
            $shippingRule->store_id = $shippingRuleData['store_id'];
            $shippingRule->apply_to = $shippingRuleData['apply_to'] ?? false;
            $shippingRule->available = $shippingRuleData['available'] ?? false;
            $shippingRule->rule_type = $ruleType ?? 0;
            $shippingRule->save();
        }

        /**
         * Note: This is important step to update params according to rule type 
         * using this function shippingRuleMappingByRuleType()
         */
        $shippingRule = self::shippingRuleMappingByRuleType($shippingRule);

        return [
            'error' => false,
            'message' => $message,
            'data' => [
                'shippingRule' => $shippingRule,
                'save' => $save
            ]
        ];
    }

    public static function updateAvaiableShippingRuleStatus($shippingRuleData)
    {
        if (isset($shippingRuleData['uuid'])) {
            $shippingRule = self::getShippingRuleDetailByUuid($shippingRuleData['uuid']);
            if (blank($shippingRule)) {
                return [
                    'error' => true,
                    'message' => 'Shipping rule not found.',
                    'data' => []
                ];
            }
            $message = 'updated successfully.';
            $save = 0;
        }
        $shippingRule->available = !$shippingRuleData['available'] ?? false;
        $shippingRule->save();

        $shippingRule = self::shippingRuleMappingByRuleType($shippingRule);

        return [
            'error' => false,
            'message' => $message,
            'data' => [
                'shippingRule' => $shippingRule,
                'save' => $save
            ]
        ];
    }

    public static function shippingRuleOrderWidget($data, $order)
    {
        $lineItem = json_decode($data['lineitems'])->lineItemData;
        $origins = $lineItem->origin;
        $items = $lineItem->items;
        $count = 0;
        $insertedIds = $insertedNames = [];

        $code = '';
        foreach ($origins as $key => $origin) {
            $item = $items->$key;
            $city = $origin->senderCity ? $origin->senderCity . ',' : '';
            $state = $origin->senderState ?? '';
            $zip = $origin->locationId != '' ? $origin->locationId : $origin->senderZip;
            $senderZip = $origin->senderZip ?? '';
            $orderWidget[$zip]['sbs'] = [];
            $orderWidget[$zip]['locationtype'] = $item->dropship_enabled == 'N' ? 'Warehouse' : 'Dropship';
            $orderWidget[$zip]['address'] = $city . ' ' . $state . ' ' . $senderZip;
            $orderWidget[$zip]['totalBoxes'] = $totalBoxes ?? 0;
            $sRate = $order['shipping_rate'];

            $shipping_name = explode('(', $order['shipping_name']);
            $sName = $shipping_name[0] ?? '';
            $sName = str_replace(Constant::RESI_LABEL, '', $sName);
            $sName = str_replace(Constant::LIFT_LABEL, '', $sName);
            $sName = str_replace(Constant::RESI_LIFT_LABEL, '', $sName);
            $sMethod = isset($shipping_name[1]) ? '(' . $shipping_name[1] : '';

            $orderWidget[$zip]['shipping_method'] = $sName . $sMethod;
            $orderWidget[$zip]['shipping_rate'] = '$' . number_format((float)$sRate, 2,);
            if ($item->shipMultiplePackage) {
                if ((!in_array($item->lineItemName, $insertedNames))) {
                    $insertedNames[] = $item->lineItemName;
                    $orderWidget[$zip]['items'][] = $item->originalPiecesOfLineItem . ' X ' . $item->lineItemName;
                }
            } else {
                if ((!in_array($item->id, $insertedIds))) {
                    $insertedIds[] = $item->id;
                    $orderWidget[$zip]['items'][] = $item->originalPiecesOfLineItem . ' X ' . $item->lineItemName;
                }
            }
            $orderWidget[$zip]['accessories'] = [];
            $count++;
        }
        return $orderWidget;
    }

    /**
     * params mapping according to rule type
     */
    public static function shippingRuleMappingByRuleType($shippingRule)
    {
        $ruleType = isset($shippingRule['rule_type']) && !empty($shippingRule['rule_type']) ? $shippingRule['rule_type'] : null;
        switch ($ruleType) {
            case 1:
                $shippingRule = self::updateRestrictCountryParams($shippingRule);
                break;
            case 2:
                $shippingRule = self::updateHideMethodsParams($shippingRule);
                break;
            case 3:
                $shippingRule = self::updateRestrictStatesParams($shippingRule);
                break;
            case 4:
                $shippingRule = self::updateRestrictfilterPostalCodeParams($shippingRule);
                break;
            case 5:
                $shippingRule = self::updateRestrictOriginLocationsParams($shippingRule);
                break;
            case 6:
                $shippingRule = self::updateOverrideRatesParams($shippingRule);
                break;
            case 7:
                $shippingRule = self::updateHideEstimateDeliveryParams($shippingRule);
                break;
            case 8:
                $shippingRule = self::updateSurchargeRatesParams($shippingRule);
                break;
            case 9:
                $shippingRule = self::updateLargeCartSettingsParams($shippingRule);
                break;
            case 10:
                $shippingRule = self::updateFlatShippingPriceParams($shippingRule);
                break;
            default:
                break;
        }

        return $shippingRule ?? [];
    }

    public static function updateHideEstimateDeliveryParams($shippingRule)
    {
        $shippingRule['filter_provider'] = $shippingRule['filter_name'];
        $settings = json_decode($shippingRule['filter_settings'], true);
        $shippingRule['products'] = $settings['filter_products'] ?? [];
        $shippingRule['categories'] = $settings['filter_categories'] ?? [];
        $shippingRule['brands'] = $settings['filter_brands'] ?? [];
        $shippingRule['apply_rule_to'] = $settings['apply_rule_to'] ?? 1;
        $shippingRule['isFilterCategory'] = $settings['isFilterCategory'] ?? 1;
        $shippingRule['isFilterBrand'] = $settings['isFilterBrand'] ?? 1;
        $shippingRule['isFilterProduct'] = $settings['isFilterProduct'] ?? 1;

        return $shippingRule;
    }

    public static function updateOverrideRatesParams($shippingRule)
    {
        return self::updateHideMethodsParams($shippingRule, true, false);
    }

    public static function updateSurchargeRatesParams($shippingRule)
    {
        return self::updateHideMethodsParams($shippingRule, false, true);
    }

    public static function updateRestrictCountryParams($shippingRule)
    {
        $shippingRule['filter_country'] = $shippingRule['filter_name'];
        $settings = json_decode($shippingRule['filter_settings'], true);
        $shippingRule['products'] = $settings['filter_products'] ?? [];
        $shippingRule['categories'] = $settings['filter_categories'] ?? [];
        $shippingRule['brands'] = $settings['filter_brands'] ?? [];
        $shippingRule['apply_rule_to'] = $settings['apply_rule_to'] ?? 1;
        $shippingRule['isFilterCategory'] = $settings['isFilterCategory'] ?? 1;
        $shippingRule['isFilterBrand'] = $settings['isFilterBrand'] ?? 1;
        $shippingRule['isFilterProduct'] = $settings['isFilterProduct'] ?? 1;

        return $shippingRule;
    }

    public static function updateHideMethodsParams($shippingRule, $isOverrideRates = false, $isSurchargeRates = false)
    {
        $shippingRule['filter_provider'] = $shippingRule['filter_name'] ?? '';
        $settings = json_decode($shippingRule['filter_settings'], true);
        $shippingRule['isFilterWeight'] = $settings['isFilterWeight'] ?? false;
        $shippingRule['isFilterPrice'] = $settings['isFilterPrice'] ?? false;
        $shippingRule['isFilterQuantity'] = $settings['isFilterQuantity'] ?? false;
        $shippingRule['weight_from'] = $settings['weightFrom'] ?? null;
        $shippingRule['weight_to'] = $settings['weightTo'] ?? null;
        $shippingRule['price_from'] = $settings['priceFrom'] ?? null;
        $shippingRule['price_to'] = $settings['priceTo'] ?? null;
        $shippingRule['quantity_from'] = $settings['quantityFrom'] ?? null;
        $shippingRule['quantity_to'] = $settings['quantityTo'] ?? null;
        $shippingRule['products'] = $settings['filter_products'] ?? [];
        $shippingRule['categories'] = $settings['filter_categories'] ?? [];
        $shippingRule['brands'] = $settings['filter_brands'] ?? [];
        $shippingRule['apply_rule_to'] = $settings['apply_rule_to'] ?? 1;
        $shippingRule['filter_country'] = $settings['filter_country'] ?? 1;
        $shippingRule['filter_state_province'] = $settings['filter_state_province'] ?? 1;
        $shippingRule['isLocationFilter'] = $settings['isLocationFilter'] ?? 1;
        $shippingRule['isFilterCategory'] = $settings['isFilterCategory'] ?? 1;
        $shippingRule['isFilterBrand'] = $settings['isFilterBrand'] ?? 1;
        $shippingRule['isFilterProduct'] = $settings['isFilterProduct'] ?? 1;
        $shippingRule['is_all_filter_applied'] = $settings['is_all_filter_applied'] ?? 1;


        if ($isOverrideRates) {
            $shippingRule['filter_services'] = $settings['filter_services'] ?? [];
            $shippingRule['service_rates'] = $settings['service_rates'] ?? null;
            $shippingRule['products'] = $settings['filter_products'] ?? [];
            $shippingRule['categories'] = $settings['filter_categories'] ?? [];
            $shippingRule['brands'] = $settings['filter_brands'] ?? [];
            $shippingRule['filter_country'] = $settings['filter_country'] ?? 1;
            $shippingRule['filter_state_province'] = $settings['filter_state_province'] ?? 1;
            $shippingRule['isLocationFilter'] = $settings['isLocationFilter'] ?? 1;
            $shippingRule['isFilterMaxShippingRate'] = $settings['isFilterMaxShippingRate'] ?? 1;
            $shippingRule['isFilterCategory'] = $settings['isFilterCategory'] ?? 1;
            $shippingRule['isFilterBrand'] = $settings['isFilterBrand'] ?? 1;
            $shippingRule['isFilterProduct'] = $settings['isFilterProduct'] ?? 1;
            $shippingRule['is_all_filter_applied'] = $settings['is_all_filter_applied'] ?? 1;

            $shippingRule['weight_from'] = $settings['weightFrom'] ?? null;
            $shippingRule['weight_to'] = $settings['weightTo'] ?? null;
            $shippingRule['price_from'] = $settings['priceFrom'] ?? null;
            $shippingRule['price_to'] = $settings['priceTo'] ?? null;
            $shippingRule['quantity_from'] = $settings['quantityFrom'] ?? null;
            $shippingRule['quantity_to'] = $settings['quantityTo'] ?? null;
        }
        if ($isSurchargeRates) {
            $shippingRule['filter_provider'] = '';
            $shippingRule['filter_name'] = '';
            $shippingRule['service_rates'] = $settings['service_rates'] ?? null;
            $shippingRule['filter_country'] = $settings['filter_country'] ?? 1;
            $shippingRule['filter_state_province'] = $settings['filter_state_province'] ?? 1;
            $shippingRule['isLocationFilter'] = $settings['isLocationFilter'] ?? 1;
            $shippingRule['isFilterCategory'] = $settings['isFilterCategory'] ?? 1;
            $shippingRule['isFilterBrand'] = $settings['isFilterBrand'] ?? 1;
            $shippingRule['isFilterProduct'] = $settings['isFilterProduct'] ?? 1;
            $shippingRule['is_all_filter_applied'] = $settings['is_all_filter_applied'] ?? 1;
        }

        return $shippingRule;
    }

    public static function updateRestrictStatesParams($shippingRule)
    {
        $shippingRule['filter_country'] = $shippingRule['filter_name'];
        $settings = json_decode($shippingRule['filter_settings'], true);
        $shippingRule['products'] = $settings['filter_products'] ?? [];
        $shippingRule['categories'] = $settings['filter_categories'] ?? [];
        $shippingRule['brands'] = $settings['filter_brands'] ?? [];
        $shippingRule['filter_state_province'] = $settings['filter_state_province'];
        $shippingRule['apply_rule_to'] = $settings['apply_rule_to'] ?? 1;
        $shippingRule['isFilterCategory'] = $settings['isFilterCategory'] ?? 1;
        $shippingRule['isFilterBrand'] = $settings['isFilterBrand'] ?? 1;
        $shippingRule['isFilterProduct'] = $settings['isFilterProduct'] ?? 1;

        return $shippingRule;
    }

    public static function updateFlatShippingPriceParams($shippingRule)
    {
        $shippingRule['filter_country'] = $shippingRule['filter_name'];
        $settings = json_decode($shippingRule['filter_settings'], true);
        $shippingRule['products'] = $settings['filter_products'] ?? [];
        $shippingRule['categories'] = $settings['filter_categories'] ?? [];
        $shippingRule['brands'] = $settings['filter_brands'] ?? [];
        $shippingRule['filter_state_province'] = $settings['filter_state_province'];
        $shippingRule['filter_flat_shipping_rate'] = $settings['filter_flat_shipping_rate'] ?? '';
        $shippingRule['isFilterFlatPrice'] = $settings['isFilterFlatPrice'] ?? false;
        $shippingRule['apply_rule_to'] = $settings['apply_rule_to'] ?? 1;
        $shippingRule['isFilterCategory'] = $settings['isFilterCategory'] ?? 1;
        $shippingRule['isFilterBrand'] = $settings['isFilterBrand'] ?? 1;
        $shippingRule['isFilterProduct'] = $settings['isFilterProduct'] ?? 1;

        return $shippingRule;
    }

    public static function updateRestrictfilterPostalCodeParams($shippingRule)
    {
        $shippingRule['filter_country'] = $shippingRule['filter_name'];
        $settings = json_decode($shippingRule['filter_settings'], true);
        $shippingRule['products'] = $settings['filter_products'] ?? [];
        $shippingRule['categories'] = $settings['filter_categories'] ?? [];
        $shippingRule['brands'] = $settings['filter_brands'] ?? [];
        $shippingRule['filter_state_province'] = $settings['filter_state_province'];
        $shippingRule['filter_postal_code'] = $settings['filter_postal_code'];
        $shippingRule['apply_rule_to'] = $settings['apply_rule_to'] ?? 1;
        $shippingRule['isFilterCategory'] = $settings['isFilterCategory'] ?? 1;
        $shippingRule['isFilterBrand'] = $settings['isFilterBrand'] ?? 1;
        $shippingRule['isFilterProduct'] = $settings['isFilterProduct'] ?? 1;

        return $shippingRule;
    }

    public static function updateRestrictOriginLocationsParams($shippingRule)
    {
        $settings = json_decode($shippingRule['filter_settings'], true);
        $shippingRule['products'] = $settings['filter_products'] ?? [];
        $shippingRule['categories'] = $settings['filter_categories'] ?? [];
        $shippingRule['brands'] = $settings['filter_brands'] ?? [];
        $shippingRule['apply_rule_to'] = $settings['apply_rule_to'] ?? 1;
        $shippingRule['warehouses'] = $settings['warehouses'] ?? [];
        $shippingRule['isFilterCategory'] = $settings['isFilterCategory'] ?? 1;
        $shippingRule['isFilterBrand'] = $settings['isFilterBrand'] ?? 1;
        $shippingRule['isFilterProduct'] = $settings['isFilterProduct'] ?? 1;

        return $shippingRule;
    }

    public static function updateLargeCartSettingsParams($shippingRule)
    {
        $settings = json_decode($shippingRule['filter_settings'], true);
        $shippingRule['max_items'] = $settings['max_items'] ?? [];
        $shippingRule['max_package_weight'] = $settings['max_package_weight'] ?? [];

        return $shippingRule;
    }

    public static function setFlatRates($flatRateitems, $origins)
    {
        if (blank($flatRateitems)) {
            return [];
        }

        $groupItemsByFlatRateRule = [];
        foreach ($flatRateitems as $item) {
            $key = $item['variant_id'];
            $groupItemsByFlatRateRule[$origins[$key]['locationId']][] = $item;
        }

        $response = [];
        foreach ($groupItemsByFlatRateRule as $flatRateRuleId => $rules) {
            $rate = 0;
            $title = $filterItems = [];
            foreach ($rules as $rule) {
                $ruleDetail = self::getFlatRateRuleDetail($rule['flatRateUuid']);
                $ruleDetailSettings = json_decode($ruleDetail['filter_settings'], true) ?? [];

                if (in_array($rule['product_id'], $filterItems) && !($ruleDetailSettings['isFilterFlatPrice'])) {
                    continue;
                }
                $filterItems[] = $rule['product_id'];

                $response[$flatRateRuleId]['title'] = $title[] = $ruleDetail['rule_name'];

                if ($ruleDetailSettings['isFilterFlatPrice']) {
                    $rate += self::getSumAftermultiplyItemwithQty($rule, $ruleDetailSettings['filter_flat_shipping_rate']);
                } else {
                    $rate += $ruleDetailSettings['filter_flat_shipping_rate'];
                }

                if (count($groupItemsByFlatRateRule) > 1 || count($rules) > 1) {
                    if (count(array_unique($title)) == 1) {
                        $response[$flatRateRuleId]['title'] = $title[0] ?? "Shipping";
                    } else {
                        $response[$flatRateRuleId]['title'] = "Shipping";
                    }
                }
            }

            $response[$flatRateRuleId]['rate'] = $rate;
            $response[$flatRateRuleId]['code'] = "flatRateRule";
        }

        return $response;
    }

    public static function getFlatRateRuleDetail($uuid): array
    {
        return optional(self::where('uuid', $uuid)->first())->toArray() ?? [];
    }

    public static function getSumAftermultiplyItemwithQty($rule, $rate)
    {
        $noOfQuantity = $rule['piecesOfLineItem'];
        return $noOfQuantity * $rate;
    }

    public static function flatRateRuleOrderWidget($data, $order, $flatRateOrigin)
    {
        $flatRateOrigin = (object) $flatRateOrigin;
        $lineItem = json_decode($data['lineitems'])->lineItemData;
        $origins = $flatRateOrigin;
        // $origins = $lineItem->origin;
        $items = $lineItem->items;

        $count = 0;
        $insertedIds = $insertedNames = [];
        $flatRateResp = !blank($data['flat_rate_resp']) ? json_decode($data['flat_rate_resp']) : [];
        $code = '';
        foreach ($origins as $key => $origin) {
            $item = $items->$key;
            $city = $origin->senderCity ? $origin->senderCity . ',' : '';
            $state = $origin->senderState ?? '';
            $zip = $origin->locationId != '' ? $origin->locationId : $origin->senderZip;
            $senderZip = $origin->senderZip ?? '';
            $orderWidget[$zip]['sbs'] = [];
            $orderWidget[$zip]['locationtype'] = $item->dropship_enabled == 'N' ? 'Warehouse' : 'Dropship';
            $orderWidget[$zip]['address'] = $city . ' ' . $state . ' ' . $senderZip;
            $orderWidget[$zip]['totalBoxes'] = $totalBoxes ?? 0;
            $sRate = $flatRateResp->$zip->rate;

            $shipping_name = explode('(', $flatRateResp->$zip->title);
            $sName = $shipping_name[0] ?? '';
            $sName = str_replace(Constant::RESI_LABEL, '', $sName);
            $sName = str_replace(Constant::LIFT_LABEL, '', $sName);
            $sName = str_replace(Constant::RESI_LIFT_LABEL, '', $sName);
            $sMethod = isset($shipping_name[1]) ? '(' . $shipping_name[1] : '';

            $orderWidget[$zip]['shipping_method'] = $sName . $sMethod;
            $orderWidget[$zip]['shipping_rate'] = '$' . number_format((float)$sRate, 2,);
            if ($item->shipMultiplePackage) {
                if ((!in_array($item->lineItemName, $insertedNames))) {
                    $insertedNames[] = $item->lineItemName;
                    $orderWidget[$zip]['freeShippingItems'][] = $item->originalPiecesOfLineItem . ' X ' . $item->lineItemName;
                }
            } else {
                if ((!in_array($item->variant_id, $insertedIds))) {
                    $insertedIds[] = $item->variant_id;
                    $orderWidget[$zip]['freeShippingItems'][] = $item->originalPiecesOfLineItem . ' X ' . $item->lineItemName;
                }
            }
            $orderWidget[$zip]['accessories'] = [];
            $count++;
        }
        return $orderWidget;
    }
}
