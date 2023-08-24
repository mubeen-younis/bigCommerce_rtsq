<?php


namespace App\CustomClasses\WWESMALL;


use App\Constants\Constant;
use App\CustomClasses\Functions;

class WweSmallQuoteResults
{

    public function filterWweSmallServicesFromMarkup($services)
    {
        if (!empty($services)) {
            $allowed = Constant::WWE_SMALL_SERVICES;
            $filtered = array_filter(
                $services,
                function ($key) use ($allowed) {
                    return in_array($key, $allowed);
                },
                ARRAY_FILTER_USE_KEY
            );
            return $filtered;
        }
        return $services;
    }

    public function getEnabledServicesCodes($services)
    {
        $enabledServices = [];;
        if (!empty($services)) {
            foreach ($services as $key => $service) {
                if ($service) {
                    $enabledServices[] = $this->serviceCodeOfWweSmallService($key);
                }
            }
        }
        return array_flip($enabledServices);
    }

    public function getServiceRate($amount, $serviceCode, $quoteSettings)
    {
        $markupIndex = $this->getMarkupIndexFromServiceCode($serviceCode);
        $markupValue = $quoteSettings['carrier_services'][$markupIndex] ?? '';
        if (empty($markupValue) || !is_numeric(str_replace('%', '', $markupValue))) {
            return $amount;
        }
        if (strpbrk($markupValue, '%') !== FALSE) {
            $amount = $this->getvalueFromPercent($amount, str_replace('%', '', $markupValue));
        } else {
            $amount = $amount + $markupValue;
        }
        return number_format($amount, 2);

    }

    public function addHazmatAmountsInServices($amount, $serviceCode, $quoteSettings)
    {
        // Adding hazmat fee to Ground Service
        if ($serviceCode == "GND") {
            if (isset($quoteSettings['ground_hazardous_material_fee']) && is_numeric($quoteSettings['ground_hazardous_material_fee']) && !empty($quoteSettings['ground_hazardous_material_fee'])) {
                $amount = $amount + $quoteSettings['ground_hazardous_material_fee'];
            }
            // Adding hazmat fee to Air Services
        } else {
            if (isset($quoteSettings['air_hazardous_material_fee']) && is_numeric($quoteSettings['air_hazardous_material_fee']) && !empty($quoteSettings['air_hazardous_material_fee'])) {
                $amount = $amount + $quoteSettings['air_hazardous_material_fee'];
            }
        }
        // $amount = $this->addHandlingMarkupOfHazmat($amount, $quoteSettings['handling_fee_markup']);
        return number_format($amount, 2);

    }

    public function addHandlingMarkupOfHazmat($amount, $markupValue)
    {
        $amount = (float)str_replace(',', '', $amount);
        if (strpbrk($markupValue, '%') !== FALSE) {
            $amount = $this->getvalueFromPercent($amount, str_replace('%', '', $markupValue));
        } else {
            $amount = $amount + $markupValue;
        }
        return $amount;
    }

    public function getvalueFromPercent($amount, $markupPercentage)
    {

        $markupValue = $markupPercentage / 100 * $amount;
        $amountWithMarkup = $amount + $markupValue;
        return $amountWithMarkup;

    }

    public function getServiceTitle($title, $dateAndDays, $serviceCode, $quoteSettings, $isResi = false, $showRadNotation = false)
    {
        if ($isResi && $showRadNotation) {
            $title = $title . Constant::RESI_LABEL;
        }
        $date = $dateAndDays['deliveryDate'] ?? null;
        $days = $dateAndDays['totalTransitTimeInDays'] ?? null;
        if (isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 2) {
            $title = !blank($days) ? $title . " (Intransit days: " . $days . ")" : $title;
        } elseif (isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 3) {
            $title = !blank($date) ? $title . " (Expected delivery by " . date('m-d-Y', strtotime($date)) . ")" : $title;
        }
        return $title;
    }

    public function serviceCodeOfWweSmallService($service)
    {
        switch ($service) {
            case "ups_ground":
                return "GND";
                break;
            case "ups_3_day_select":
                return "3DS";
                break;
            case "ups_2nd_day_air":
                return "2DA";
                break;
            case "ups_2nd_day_air_am":
                return "2DM";
                break;
            case "ups_2nd_day_air_saver":
                return "2DAS";
                break;
            case "ups_next_day_air":
                return "1DA";
                break;
            case "ups_next_day_air_saver":
                return "1DP";
                break;
            case "ups_next_day_air_early":
                return "1DM";
                break;
            case "ups_worldwide_express":
                return "01";
                break;
            case "ups_worldwide_expedited":
                return "05";
                break;
            case "ups_worldwide_saver":
                return "28";
                break;
            case "ups_worldwide_express_plus":
                return "21";
                break;
            case "ups_standard":
                return "03";
                break;
            default:
                return "";
        }
    }

    public function checkGroundTransit($quote, $quoteSettings)
    {
        // Check limited to carrier transit days
        if ($quoteSettings['ground_metric'] == 1) {
            //  2>3
            if ($quote['TransitTimeInDays'] > $quoteSettings['number_of_transit_days']) {
                return true;
            }
            // Check by calendar days
        } else {
            if ($quote['CalenderDaysInTransit'] > $quoteSettings['number_of_transit_days']) {
                return true;
            }
        }
        return false;
    }

    public function getMarkupIndexFromServiceCode($serviceCode)
    {
        switch ($serviceCode) {
            case "GND":
                return "ups_ground_markup";
                break;
            case "3DS":
                return "ups_3_day_select_markup";
                break;
            case "2DA":
                return "ups_2nd_day_air_markup";
                break;
            case "2DM":
                return "ups_2nd_day_air_am_markup";
                break;
            case "2DAS":
                return "ups_2nd_day_air_saver_markup";
                break;
            case "1DA":
                return "ups_next_day_air_markup";
                break;
            case "1DP":
                return "ups_next_day_air_saver_markup";
                break;
            case "1DM":
                return "ups_next_day_air_early_markup";
                break;
            case "01":
                return "ups_worldwide_express_markup";
                break;
            case "03":
                return "ups_standard_markup";
                break;
            case "05":
                return "ups_worldwide_expedited_markup";
                break;
            case "21":
                return "ups_worldwide_express_plus_markup";
                break;
            case "28":
                return "ups_worldwide_saver_markup";
                break;
            default:
                return "";
        }
    }

    public function compileCompareQuotes($shipment, $connectionSettings)
    {
        $originQuotes  = [];

        foreach ($shipment as $origin => $quote) {
            
            if (isset($quote['severity'])) {
                return $quote['Message'];
            }
            
            $lowestAmount = 0;
            
            if (isset($quote['q'])) {
                foreach ($quote['q'] as $key => $data) {
                    
                    // Adding Markup in services if enabled
                    $price = $this->getServiceRate($data['totalNetCharge']['Amount'], $data['serviceType'], []);
                    $quoteSettings = [];

                    $price = $this->addHandlingMarkupOfHazmat($price, $quoteSettings['handling_fee_markup'] ?? 0);
                    $price = (float) str_replace(',', '', $price);
                    
                    $title = $data['serviceDesc'];
                    $dateTime = $this->getEstimatedDateTime($data);
                    $originQuotes[$key]['date'] = $dateTime;
                    $originQuotes[$key]['rate'] = $price;
                    $originQuotes[$key]['title'] = $title;
                    $sortedArray[$key] = $price;
                }
            }
        }
        array_multisort($sortedArray, SORT_ASC, $originQuotes);

        if (!empty($originQuotes)) {
            return $originQuotes;
        }

        return [];
    }

    public function getEstimatedDateTime($data)
    {
        $dateTime = '';
        try {
            if(isset($data['deliveryTimestamp']) && !empty($data['deliveryTimestamp'])){
                $time = date('h:i A', strtotime($data['deliveryTimestamp']));
                $date = date('l, F d, Y', strtotime($data['deliveryTimestamp']));
                $dateTime = 'Delivery By ' . $date;
            }

            return $dateTime;
        } catch (\Exception $exception) {
            return $dateTime;
        }

    }

}
