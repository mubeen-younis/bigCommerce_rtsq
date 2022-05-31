<?php

namespace App\CustomClasses\UspsSmall;

use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;

class QuotesResults
{
    public function __construct()
    {
        $this->CompileQuotes = new CompileQuotes();
        $this->storeBoxes = [];
        $this->uspsBoxes = [];
        $this->uspsActiveServices = [];
        $this->uspsPackagingEligible = false;
        $this->packagingRequest = [];
        $this->finalBoxesForWs = [];
    }

    public function getServiceRate($data, $serviceDesc, $quoteSettings)
    {
        $amount = $data['totalNetCharge']['Amount'];
        $markupIndex = strtolower(str_replace(' ', '_', $serviceDesc) . '_markup');
        $markupValue = $quoteSettings['carrier_services'][$markupIndex] ?? '';

        if (empty($markupValue) || !is_numeric(str_replace('%', '', $markupValue))) {
            return $amount;
        }
        if (strpbrk($markupValue, '%') !== false) {
            $amount = $this->getvalueFromPercent($amount, str_replace('%', '', $markupValue));
        } else {
            $amount = $amount + $markupValue;
        }

        return number_format($amount, 2);
    }

    public function addHazmatAmountsInServices($amount, $serviceCode, $quoteSettings)
    {
        // Adding hazmat fee to Ground Service
        if ($serviceCode == "03") {
            if (isset($quoteSettings['ground_hazardous_material_fee']) && is_numeric($quoteSettings['ground_hazardous_material_fee']) && !empty($quoteSettings['ground_hazardous_material_fee'])) {
                $amount = $amount + $quoteSettings['ground_hazardous_material_fee'];
            }
        }
        // Adding hazmat fee to Air Services
        else {
            if (isset($quoteSettings['air_hazardous_material_fee']) && is_numeric($quoteSettings['air_hazardous_material_fee']) && !empty($quoteSettings['air_hazardous_material_fee'])) {
                $amount = $amount + $quoteSettings['air_hazardous_material_fee'];
            }
        }

        return number_format($amount, 2);
    }

    public function addHandlingMarkupOfHazmat($amount, $markupValue)
    {
        $amount = (float) str_replace(',', '', $amount);
        if (strpbrk($markupValue, '%') !== false) {
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

    public function getServiceTitle($title, $data, $serviceCode, $quoteSettings, $isResi = false)
    {
        $title = $isResi ? $title . Constant::RESI_LABEL : $title;

        if (isset($data['totalTransitTimeInDays']) && $data['totalTransitTimeInDays'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 2) {
            $title = $title . ' (Estimated number of days until delivery is ' . $data['totalTransitTimeInDays'] . ')';
        } else if (isset($data['deliveryTimestamp']) && $data['deliveryTimestamp'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 3) {
            $title = $title . ' (Estimated delivery date is ' . date('m-d-Y', strtotime($data['deliveryTimestamp'])) . ')';
        }

        return $title;
    }

    public function checkGroundTransit($quote, $quoteSettings)
    {
        // Check limited to carrier transit days
        if ($quoteSettings['ground_metric'] == 1) {
            if (isset($quote['transitTimeInDays']) && isset($quoteSettings['number_of_transit_days']) && $quote['transitTimeInDays'] > $quoteSettings['number_of_transit_days']) {
                return true;
            }
        }
        // Check by calendar days
        else {
            if (isset($quote['calenderDaysInTransit']) && isset($quoteSettings['number_of_transit_days']) && $quote['calenderDaysInTransit'] > $quoteSettings['number_of_transit_days']) {
                return true;
            }
        }

        return false;
    }

    public function getUspsActiveServices($carrierServices): array
    {
        $domesticServices = [
            'usps_first_class_mail' => 'First Class Mail',
            'usps_priority_mail_express' => 'Priority Mail Express',
            'usps_priority_mail' => 'Priority Mail',
            'usps_priority_mail_flat_rate' => 'Priority Mail Flat Rate',
            'usps_retail_ground' => 'Retail Ground',
        ];
        $internationalServices = [
            'usps_priority_mail_international_express' => 'Priority Mail International Express',
            'usps_priority_mail_international' => 'Priority Mail International',
            'usps_priority_mail_international_flat_rate_box' => 'Priority Mail International Flat Rate Box',
            'usps_first_class_package_international_service' => 'First-Class Package International Service',
        ];
        $activeServices = [
            'domestic' => [],
            'international' => [],
        ];

        foreach ($domesticServices as $service => $value) {
            if (isset($carrierServices[$service]) && $carrierServices[$service]) {
                $activeServices['domestic'][] = $value;
            }
        }

        foreach ($internationalServices as $service => $value) {
            if (isset($carrierServices[$service]) && $carrierServices[$service]) {
                $activeServices['international'][] = $value;
            }
        }

        return $activeServices;
    }
}
