<?php

namespace App\CustomClasses\DBSC;

use App\Models\DBSC\DbscShippingOrigin;
use App\Models\DBSC\DbscShippingProfile;
use App\Models\DBSC\DbscShippingZone;
use Illuminate\Support\Facades\Log;

class GetRatesDbsc
{
    /*
     * @Author : Saif*/

    /**
     * Using php 8.0 constructor property promotion
     *
     * @param int|null $storeId
     * @param array $destination
     * @param array $items
     * @param array $groupedItemsProfile
     * @param array $genShipProfSettings
     * @param bool $isMultiShipment
     * @param array $rates
     * @param array $ordWidgetDetails
     */
    public function __construct(public ?int  $storeId,
                                public array $destination,
                                public array $items,
                                public array $groupedItemsProfile,
                                public array $genShipProfSettings,
                                public bool  $isMultiShipment,
                                public array $rates,
                                public array $ordWidgetDetails
    )
    {

    }


    /**
     * Calculates the DBSC rates
     *
     * @return array|void
     */
    public function getDbscRates($request, $storeData)
    {
        $this->storeId = $storeData['store']['id'] ?? [];
        $this->destination = $request['lineItemData']['destination'] ?? [];
        $this->items = $request['lineItemData']['items'] ?? [];
        if (blank($this->storeId) || blank($this->destination) || blank($this->items)) {
            return [];
        }

        $this->genShipProfSettings = DbscShippingProfile::getGeneralProfileSettings($this->storeId);
        /*Will group items according to there profile*/
        $this->groupedItemsProfile = $this->setGroupItemsProfile();
        if (blank($this->groupedItemsProfile)) {
            return [];
        }
        $this->rates = $this->getRates();
        if (!blank($this->rates)) {
            return ['rates' => $this->rates, 'ord_wid' => $this->ordWidgetDetails];
        }
        return [];


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
                if (!$this->generalProfileCanTakeRate()) {
                    return [];
                }
                $groupedItemsProfile[$this->genShipProfSettings['id']][] = $item;

            } else {
                $shippingClassProfileId = DbscShippingProfile::getProfileIdOfShippingClass($item['shipping_class'], $this->storeId);
                if (blank($shippingClassProfileId)) {

                    if (!$this->generalProfileCanTakeRate($item['shipping_class'])) {
                        return [];
                    }

                    $groupedItemsProfile[$this->genShipProfSettings['id']][] = $item;
                } else {

                    $groupedItemsProfile[$shippingClassProfileId][] = $item;

                }
            }
        }
        if (!blank($groupedItemsProfile) && count($groupedItemsProfile) > 1) {
            $this->isMultiShipment = true;
        }

        return $groupedItemsProfile;
    }


    /**
     * Checks general profile can take rate
     * @param $shippingClass
     * @return bool
     */
    public function generalProfileCanTakeRate($shippingClass = null)
    {
        // If general profile settings are empty
        if (blank($this->genShipProfSettings)) {
            return false;
        }
        if ($this->genShipProfSettings['allow_all_classes']) {
            return true;
        }

        if (!blank($shippingClass)) {
            if (blank($this->genShipProfSettings['shipping_classes'])) {
                return false;
            }
            $shippingClasses = json_decode($this->genShipProfSettings['shipping_classes'], true) ?? [];
            if (in_array($shippingClass, $shippingClasses)) {
                return true;
            }
        }
        return false;

    }

    /**
     * Getting Rates of Shipments
     * @return array|null
     */
    public function getRates()
    {
        $rates = [];
        foreach ($this->groupedItemsProfile as $profileId => $items) {
            $zoneId = DbscShippingZone::getZoneIdFromDestinationAndProfile($this->destination, $profileId);
            if (blank($zoneId)) {
                Log::info('No zone found ' . $this->storeId);
                return [];
            }
            $profileRates = DbscShippingProfile::getProfileRates($profileId, $zoneId, $this->storeId);

            if (blank($profileRates)) {
                return [];
            }
            $shipmentRates = $this->getShipmentRates($profileRates, $items);
            if (blank($shipmentRates)) {
                return [];
            }
            $rates = !blank($rates) ? array_merge($rates, $shipmentRates) : $shipmentRates;
        }
        return $rates;
    }


    /**
     * Calculates the Shipment rates
     *
     * @return array|void
     */
    public function getShipmentRates($profileRates, $items)
    {
        $sServiceArr = [];
        [$itemsCount, $totalShipmentWeight] = $this->calculateTotalShipmentWeightAndItemsCount($items);
        [$itemsCount, $totalShipmentLength] = $this->calculateTotalShipmentLengthAndItemsCount($items);
        // Getting Only one from origins json in profile rates
        // and will iterate through each origin
        $origins = DbscShippingOrigin::getOriginsFromOriginId($profileRates[0]['dbsc_origin_id']);
        $selectedOrigin = (new GetDistance())->getNearest($origins, $this->destination);
        if (blank($selectedOrigin)) {
            Log::info('Issue on fetching origin');
            return [];
        }
        foreach ($profileRates as $rate) {
            [
                $minWeight,
                $maxWeight,
                $minLength,
                $maxLength,
                $minQuote,
                $maxQuote,
                $ratingMethod,
                $ratePerMileOrKm,
                $distanceUnit,
                $label,
                $distanceMethod,
                $description,
                $distancePreference,
                $andOR
            ] = $this->setRateVariables($rate);
                $isValidLength = $this->isValidShippingLength($totalShipmentLength, $minLength, $maxLength);
                $isValidWeight   = $this->isValidShippingWeight($totalShipmentWeight, $minWeight, $maxWeight);

            if ($andOR == "And" && ($isValidWeight && $isValidLength) || $andOR == "Or" && ($isValidWeight || $isValidLength)){

                // Firstly we will check the address type
                /*   if (!$this->checkAddressType($addressType, $unknownDefaultAddress)) {
                       continue;
                   }*/
                if (!($ratePerMileOrKm > 0)) {
                    $distance['distance_m'] = 0;
                } else {
                    $distance = $this->findDistance($distanceMethod, $selectedOrigin);
                }
                if (isset($distance['error'])) {
                    continue;
                }
                $convertedDistance = ($this->convertDistance($distance['distance_m'], $distanceUnit));
                $shippingRate = $ratePerMileOrKm * $convertedDistance;
                $shippingRate = $this->calculateShippingByItem($shippingRate, $ratingMethod, $itemsCount);

                if ($distancePreference == "3"){
                    $label = $label . ' ' . $description;
                }else if ($distancePreference == "2"){
                    $label = $label . ' ' . $convertedDistance .' '. $distanceUnit;
                }else {
                    $label = $label . '';
                }
                // $shippingRate = $this->addHandlingFee($shippingRate, $handlingFee);
                $shippingRate = $this->checkShippingQuote($shippingRate, $minQuote, $maxQuote);
                $sServiceArr[] = $rate = $this->createServiceArray($label, $shippingRate);
                $this->setOrderWidgetDetails($rate, [
                    'rating_method' => $ratingMethod,
                    'rate_per_mile_or_km' => $ratePerMileOrKm,
                    'distance_method' => $distanceMethod,
                    'distance_unit' => $distanceUnit,
                ]);
            }
        }
        return $sServiceArr;
    }

    /**
     * Calculate total shipment weight and items count
     *
     * @param $items
     * @return float
     */
    public function calculateTotalShipmentWeightAndItemsCount($items): array
    {
        $totalShipmentWeight = 0;
        $itemsCount = 0;
        foreach ($items as $item) {
            $itemsCount += $item['piecesOfLineItem'];
            $totalShipmentWeight += $item['lineItemWeight'] * $item['piecesOfLineItem'];
        }
        return [$itemsCount, round($totalShipmentWeight, 2)];
    }

    public function calculateTotalShipmentLengthAndItemsCount($items): array
    {
        $totalShipmentLength = 0;
        $itemsCount = 0;
        foreach ($items as $item) {
            $itemsCount += $item['piecesOfLineItem'];
            $totalShipmentLength += $item['lineItemLength'] * $item['piecesOfLineItem'];
        }
        return [$itemsCount, round($totalShipmentLength, 2)];
    }

    /**
     * Set rate variables
     *
     * @param $rate
     * @return array
     */
    public function setRateVariables($rateSettings)
    {
        $rateSettings = (object)$rateSettings;
        $minWeight = (isset($rateSettings->minimum_weight) && !empty($rateSettings->minimum_weight)) ? round($rateSettings->minimum_weight, 2) : 0;
        $maxWeight = (isset($rateSettings->maximum_weight) && !empty($rateSettings->maximum_weight)) ? round($rateSettings->maximum_weight, 2) : 0;
        
        $minLength = (isset($rateSettings->minimum_length) && !empty($rateSettings->minimum_length)) ? round($rateSettings->minimum_length, 2) : 0;
        $maxLength = (isset($rateSettings->maximum_length) && !empty($rateSettings->maximum_length)) ? round($rateSettings->maximum_length, 2) : 0;
        ////////////////////

        $minQuote = (isset($rateSettings->minimum_shipping_quote) && !empty($rateSettings->minimum_shipping_quote)) ? $rateSettings->minimum_shipping_quote : 0;
        $maxQuote = (isset($rateSettings->maximum_shipping_quote) && !empty($rateSettings->maximum_shipping_quote)) ? $rateSettings->maximum_shipping_quote : 0;

        $ratingMethod = $rateSettings->rating_method ?? 1;

        $ratePerMileOrKm = (isset($rateSettings->rate) && !empty($rateSettings->rate)) ? $rateSettings->rate : 0;
        $distanceUnit = $rateSettings->distance_unit ?? 'mile';

        $displayAs = $rateSettings->display_as;
        $label = !blank($displayAs) ? $displayAs : 'Freight';
        $description = $rateSettings->description;

        $distanceMethod = $rateSettings->distance_measured_by ?? 'Route';
        // For what to display on checkout
        $distancePreference = isset($rateSettings->distance_display_preferences) && !empty($rateSettings->distance_display_preferences) ? $rateSettings->distance_display_preferences : "1";


        $andOr = (isset($rateSettings->and_or) && !empty($rateSettings->and_or)) ? $rateSettings->and_or : 'and';
        return [
            $minWeight,
            $maxWeight,
            $minLength,
            $maxLength,
            $minQuote,
            $maxQuote,
            $ratingMethod,
            $ratePerMileOrKm,
            $distanceUnit,
            $label,
            $distanceMethod,
            $description,
            $distancePreference,
            $andOr
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

    public function isValidShippingLength($totalShipmentLength, $minLength, $maxLength)
    {
        return (($totalShipmentLength >= $minLength) && (($maxLength == 0) || ($totalShipmentLength <= $maxLength)));
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
     * Gets Distance
     * @param $distanceMethod
     * @param $origin
     * @return array|string[]
     */
    public function findDistance($distanceMethod, $origin): array|string|bool
    {
        return (new GetDistance())->findDistance($distanceMethod, $origin, $this->destination, $this->storeId);
    }

    /**
     * Distance unit conversion
     *
     * @param $distanceInMeter
     * @param $convertingUnit
     * @return float|int
     */
    public function convertDistance($distanceInMeter, $convertingUnit)
    {
        switch ($convertingUnit) {
            case 'mile':
                $convertedDistance = ($distanceInMeter * 0.000621371);
                break;
            default:
                $convertedDistance = ($distanceInMeter / 1000);
                break;
        }

        return $convertedDistance;
    }

    /**
     * Calculate shipping by items.
     *
     * @param $shipping_rate
     * @param $isCalculateShippingByItem
     * @return float|int|mixed
     */
    public function calculateShippingByItem($shippingRate, $isCalculateShippingByItem, $itemsCount)
    {
        if ($isCalculateShippingByItem == 2) {
            $shippingRate = $shippingRate * $itemsCount;
        } elseif ($isCalculateShippingByItem == 3) {
            // TODO: Need to ask
            $shippingRate = $shippingRate * $itemsCount;
        }
        return $shippingRate;
    }

    /**
     *
     * @param $shippingRate
     * @param $minQuote
     * @param $maxQuote
     * @return mixed
     */
    public function checkShippingQuote($shippingRate, $minQuote, $maxQuote)
    {
        if ($shippingRate < $minQuote) {
            $shippingRate = $minQuote;
        } elseif (($shippingRate > $maxQuote && $maxQuote > 0)) {
            $shippingRate = $maxQuote;
        }
        return $shippingRate;
    }


    /**
     * Create service array
     *
     * @param $label
     * @param $description
     * @param $shippingRate
     * @return array
     */
    public function createServiceArray($label, $shippingRate)
    {
        return array(
            'title' => $label,
            'code' => 'dbsc' . rand(1, 100),
            'total_price' => round($shippingRate, 2),
        );
    }


    /**
     * Setting Order Widget Details
     * @param $rateDetails
     * @param $extras
     * @return void
     */
    public function setOrderWidgetDetails($rateDetails, $extras)
    {
        $this->ordWidgetDetails[] = ['rate_details' => $rateDetails, 'extras' => $extras];
    }

}
