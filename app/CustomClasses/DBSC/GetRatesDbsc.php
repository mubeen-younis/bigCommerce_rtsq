<?php

namespace App\CustomClasses\DBSC;

use App\Models\DBSC\DbscShippingProfile;

class GetRatesDbsc
{

    protected ?int $storeId = null;
    protected array $destination = [];
    protected array $items = [];
    protected array $groupedItemsProfile = [];
    protected ?int $genShipProfId = null;
    protected bool $isMultiShipment = false;
    protected array $rates = [];


    /**
     * Calculates the rates
     *
     * @return array|void
     */
    public function getDbscRates($request, $storeData)
    {
        $this->storeId = $storeData['store']['id'] ?? [];
        $this->destination = $request['lineItemData']['destination'] ?? [];
        $this->items = $request['lineItemData']['items'] ?? [];
        $this->genShipProfId = 1; //TODO: Will be the general id of store

        if (blank($this->storeId) || blank($this->destination) || blank($this->items)) {
            return [];
        }
        /*Will group items according to there profile*/
        $this->groupedItemsProfile = $this->setGroupItemsProfile();

        $this->rates = $this->getRates();

        dd(22, $this->groupedItemsProfile);


        dd(123, $request, $this->storeId, $this->destination);
    }

    /**
     * Groups items with there profile ids
     * @return array
     */
    public function setGroupItemsProfile(): array
    {
        $groupedItemsProfile = [];
        foreach ($this->items as $item) {
            if (blank($item['shipping_class'])) {
                $groupedItemsProfile[$this->genShipProfId][] = $item;
            } else {
                $groupedItemsProfile[$item['shipping_class']][] = $item;
            }
        }
        if (!blank($groupedItemsProfile) && count($groupedItemsProfile) > 1) {
            $this->isMultiShipment = true;
        }
        return $groupedItemsProfile;
    }

    public function getRates()
    {
        foreach ($this->groupedItemsProfile as $shippingClass => $items) {
            $profileRates = DbscShippingProfile::getProfileRates($shippingClass);
            if (blank($profileRates)) {
                return [];
            }
        }
    }


    /**
     * Calculates the Shipment rates
     *
     * @return array|void
     */
    public function shipmentRates($profileRates, $items)
    {
        $sServiceArr = [];
        $totalShipmentWeight = $this->calculateTotalShipmentWeight($items);
        $profileRates = []; // TODO : Need to get all the store rates from DB
        if (is_array($profileRates) && !empty($profileRates)) {
            foreach ($profileRates as $rate) {
                [
                    $minWeight,
                    $maxWeight,
                    $minQuote,
                    $maxQuote,
                    $isCalculateShippingByItem,
                    $ratePerMileOrKm,
                    $distanceUnit,
                    $label,
                    $distanceMethod,
                    $description,
                    $handlingFee,
                    $addressType,
                    $unknownDefaultAddress
                ] = $this->setRateVariables($rate);

                if ($this->isValidShippingWeight($totalShipmentWeight, $minWeight, $maxWeight)) {

                    // Firstly we will check the address type
                    if (!$this->checkAddressType($addressType, $unknownDefaultAddress)) {
                        continue;
                    }

                    if (!($ratePerMileOrKm > 0)) {
                        $distance['distance_meter'] = 0;
                    } else {
                        $distance = $this->calculateDistance($distanceMethod);
                    }
                    if (isset($distance['error'])) {
                        continue;
                    }

                    $shippingRate = $ratePerMileOrKm * $this->convertDistance($distance['distance_meter'], $distanceUnit);

                    $shippingRate = $this->calculateShippingByItem($shippingRate, $isCalculateShippingByItem);

                    $shippingRate = $this->addHandlingFee($shippingRate, $handlingFee);
                    $shippingRate = $this->checkShippingQuote($shippingRate, $minQuote, $maxQuote);
                    $sServiceArr[] = $rate = $this->createServiceArray($label, $description, $shippingRate);
                    $this->enableOrderWidgetDetails($this->locCode,
                        [
                            'isCalculateShippingByItem' => $isCalculateShippingByItem,
                            'rate_per_mile_or_km' => $ratePerMileOrKm,
                            'distance_method' => $distanceMethod,
                            'distance_unit' => $distanceUnit,
                            'handling_fee' => $handlingFee
                        ],
                        $rate);
                }
            }
            // save the transactions
            // update the plan count
            // log the entry for the additional usage if utilized
            $this->saveTransaction();
        } else {
            echo json_encode(array('error' => array('No rates have been added by the merchant.')));
            exit;
        }
        return $sServiceArr;
    }

    /**
     * Calculate total shipment weight
     *
     * @param $items
     * @return float
     */
    public function calculateTotalShipmentWeight($items)
    {
        $totalShipmentWeight = 0;
        foreach ($items as $item) {
            $itemArr = (array)$item;
            $itemArr['weight'] = $this->gramsToLbsConverter($itemArr['grams']);
            $itemArr['total_weight'] = $itemArr['weight'] * $itemArr['quantity'];
            $totalShipmentWeight = $totalShipmentWeight + $itemArr['total_weight'];
        }
        return round($totalShipmentWeight, 2);
    }


    /**
     * Set rate variables
     *
     * @param $rate
     * @return array
     */
    public function setRateVariables($rate)
    {
        $rateSettings = is_string($rate['rate_settings']) ? json_decode($rate['rate_settings']) : (object)$rate['rate_settings'];

        $minWeight = (isset($rateSettings->min_weight) && !empty($rateSettings->min_weight)) ? round($rateSettings->min_weight, 2) : 0;
        $maxWeight = (isset($rateSettings->max_weight) && !empty($rateSettings->max_weight)) ? round($rateSettings->max_weight, 2) : 0;

        $minQuote = (isset($rateSettings->min_quote) && !empty($rateSettings->min_quote)) ? bcmul($rateSettings->min_quote, 100) : 0;
        $maxQuote = (isset($rateSettings->max_quote) && !empty($rateSettings->max_quote)) ? bcmul($rateSettings->max_quote, 100) : 0;

        $isCalculateShippingByItem = ($rateSettings->calculate_shipping == 'calculate_shipping_for_each_item');

        $ratePerMileOrKm = (isset($rateSettings->rate_price) && !empty($rateSettings->rate_price)) ? bcmul($rateSettings->rate_price, 100) : 0;
        $distanceUnit = $rateSettings->distance_unit;

        $displayAs = $rateSettings->display_as;
        $label = (isset($displayAs) && !empty($displayAs)) ? $displayAs : 'Freight';
        $distanceMethod = $rateSettings->distance_method;

        $addressType = isset($rateSettings->address_type) && !empty($rateSettings->address_type) ? $rateSettings->address_type : "commercial_residential";
        $description = $rateSettings->rate_description;


        $unknownDefaultAddress = isset($rateSettings->default_unknown_address) && !empty($rateSettings->default_unknown_address) ? $rateSettings->default_unknown_address : "commercial";


        $handlingFee = (isset($rateSettings->handling_fee) && !empty($rateSettings->handling_fee)) ? $rateSettings->handling_fee : '';

        return [
            $minWeight,
            $maxWeight,
            $minQuote,
            $maxQuote,
            $isCalculateShippingByItem,
            $ratePerMileOrKm,
            $distanceUnit,
            $label,
            $distanceMethod,
            $description,
            $handlingFee,
            $addressType,
            $unknownDefaultAddress
        ];
    }


    /**
     * Verifies that the shipping weight is valid.
     *
     * @param $totalShipmentWeight
     * @param $minWeight
     * @param $maxWeight
     * @return bool
     */
    public function isValidShippingWeight($totalShipmentWeight, $minWeight, $maxWeight)
    {
        return (($totalShipmentWeight >= $minWeight) && (($maxWeight == 0) || ($totalShipmentWeight <= $maxWeight)));
    }


    /**
     * CheckIfAddressType Matches with Smarty Address
     *
     *
     * @return boolean
     */
    public function checkAddressType($addressType, $unknownDefaultAddress)
    {
        if ($addressType == 'commercial_residential') {
            // no need to check smarty because address is commercial or residential both
            return true;
        } elseif ($addressType == 'residential') {
            // first we will check if smarty address is really residential
            // if detected is residential we return true
            $type = $this->getSmartyAddress();
            if ($type == 'r') {
                return true;
            } elseif ($type == 'n') {
                // Now if type is n than customer will tell what will smarty will return and if it is residential default than
                // we will return true;
                if ($unknownDefaultAddress == 'residential') {
                    return true;
                }
            }
        } else {
            $type = $this->getSmartyAddress();
            if ($type == 'c') {
                return true;
            } elseif ($type == 'n') {
                // Now if type is n than customer will tell what will smarty will return and if it is residential default than
                // we will return true;
                if ($unknownDefaultAddress == 'commercial') {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Distance unit conversion
     *
     * @param $distance_in_meter
     * @param $converting_unit
     * @return float|int
     */
    public function convertDistance($distance_in_meter, $converting_unit)
    {
        switch ($converting_unit) {
            case 'km':
                $converted_distance = ($distance_in_meter / 1000);
                break;
            default:
                $converted_distance = ($distance_in_meter * 0.000621371);
                break;
        }

        return $converted_distance;
    }

    /**
     * Calculate shipping by items.
     *
     * @param $shipping_rate
     * @param $isCalculateShippingByItem
     * @return float|int|mixed
     */
    public function calculateShippingByItem($shipping_rate, $isCalculateShippingByItem)
    {
        if ($isCalculateShippingByItem) {
            $shipping_rate = $shipping_rate * $this->itemsCount;
        }
        return $shipping_rate;
    }

}
