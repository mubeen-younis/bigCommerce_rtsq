<?php

namespace App\CustomClasses\DBSC;

use App\Models\DBSC\DbscShippingProfile;
use App\Models\DBSC\DbscShippingZone;

class GetRatesDbsc
{

    protected ?int $storeId = null;
    protected array $destination = [];
    protected array $items = [];
    protected ?int $zoneId = null;
    protected array $groupedItemsProfile = [];
    protected array $genShipProfSettings = [];
    protected bool $isMultiShipment = false;
    protected array $rates = [];
    protected array $ordWidgetDetails = [];


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
        $this->zoneId = DbscShippingZone::getZoneIdFromDestination($this->destination); // TODO: Static at the moment but will set dynamic later
        if (blank($this->storeId) || blank($this->destination) || blank($this->items) || blank($this->zoneId)) {
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


        /*       if (blank($shippingClass)) {
                   // If general profile settings are empty
                   if (blank($this->genShipProfSettings)) {
                       return false;
                   }
                   if ($this->genShipProfSettings['allow_all_classes']) {
                       return true;
                   }

               } else {
                   // If customer selected to have defined classes for general profile rather then all
                   if (!blank($this->genShipProfSettings)) {

                       if (!$this->genShipProfSettings['allow_all_classes']) {
                           if (blank($this->genShipProfSettings['shipping_classes'])) {
                               return false;
                           }
                           $shippingClasses = json_decode($this->genShipProfSettings['shipping_classes'], true) ?? [];
                           if (in_array($shippingClass, $shippingClasses)) {
                               return true;
                           }
                           return false;
                       }
                   }

               }
               return false;*/

    }

    /**
     * Getting Rates of Shipments
     * @return array|null
     */
    public function getRates()
    {
        $rates = [];
        foreach ($this->groupedItemsProfile as $profileId => $items) {
            $profileRates = DbscShippingProfile::getProfileRates($profileId, $this->zoneId, $this->storeId);
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
        // Getting Only one from origins json in profile rates
        // and will iterate through each origin
        $origins = json_decode($profileRates[0]['origins'], true);
        foreach ($origins as $origin) {
            foreach ($profileRates as $rate) {

                [
                    $minWeight,
                    $maxWeight,
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

                if ($this->isValidShippingWeight($totalShipmentWeight, $minWeight, $maxWeight)) {

                    // Firstly we will check the address type
                    /*   if (!$this->checkAddressType($addressType, $unknownDefaultAddress)) {
                           continue;
                       }*/
                    if (!($ratePerMileOrKm > 0)) {
                        $distance['distance_meter'] = 0;
                    } else {
                        $distance = $this->findDistance($distanceMethod, $origin);
                    }
                    if (isset($distance['error'])) {
                        continue;
                    }

                    $shippingRate = $ratePerMileOrKm * $this->convertDistance($distance['distance_meter'], $distanceUnit);
                    $shippingRate = $this->calculateShippingByItem($shippingRate, $ratingMethod, $itemsCount);

                    // $shippingRate = $this->addHandlingFee($shippingRate, $handlingFee);
                    $shippingRate = $this->checkShippingQuote($shippingRate, $minQuote, $maxQuote);
                    $sServiceArr[] = $rate = $this->createServiceArray($label, $description, $shippingRate);
                    $this->setOrderWidgetDetails($rate, [
                        'isCalculateShippingByItem' => $ratingMethod,
                        'rate_per_mile_or_km' => $ratePerMileOrKm,
                        'distance_method' => $distanceMethod,
                        'distance_unit' => $distanceUnit,
                    ]);
                }
                dd(33);
            }
        }
        // save the transactions
        // update the plan count
        // log the entry for the additional usage if utilized
        return $sServiceArr;
    }

    /**
     * Calculate total shipment weight
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


    public function findDistance($distanceMethod, $origin)
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
    public function createServiceArray($label, $description, $shippingRate)
    {
        return array(
            'title' => $label . $description,
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
