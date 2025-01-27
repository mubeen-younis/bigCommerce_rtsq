<?php

namespace App\CustomClasses\DayLightLtl;

use App\CustomClasses\Functions;

class QuotesResults
{
    public function getApiArr($connSettings, $resp = []): array
    {
        $accessorial = [];

        if ($resp['alwaysResi'] || $resp['residential'] != 'N') {
            array_push($accessorial, 'Residential Delivery');
        }

        if ($resp['liftGate'] == 'Y') {
            array_push($accessorial, 'Lift Gate Delivery');
        }

        if ($resp['limitedAccess'] == 'Y') {
            array_push($accessorial, 'Limited Access or Constr Site Dlvry');
        }

        $weightThreshold = $connSettings['quote_settings']['weight_threshold'] ?? Functions::$defaultThresholdLimit;

        $apiArr = [
            'userName' => $connSettings['creds']['username'] ?? '',
            'password' => $connSettings['creds']['password'] ?? '',
            'accountNumber' => $connSettings['creds']['account_number'] ?? '',

            'thresholdWeightLimit' => $weightThreshold,
            'handlingUnitWeight' => $connSettings['quote_settings']['weight_of_handling_unit'] ?? 0,
            'maxWeightPerHandlingUnit' => $connSettings['quote_settings']['max_weight_per_handling_unit'] ?? 0,

            /* Accessorial array */
            'accessorial' => $accessorial,
        ];

        return $apiArr;
    }

    public function formatQuotesBeforeCompilation($shipments): array
    {
        $formattedShipments = $shipments ?? [];

        foreach ($formattedShipments as $key => $value) {
            $quote = $value ?? [];
            $isError = isset($quote['severity']);

            if (!blank($quote) && isset($quote['q']) && !$isError) {
                unset($formattedShipments[$key]['debug']);
                $formattedShipments[$key]['q']['serviceType'] = $formattedShipments[$key]['q']['serviceDesc'] = 'Standard';

                $charges = $formattedShipments[$key]['q']['totalNetCharge'];
                unset($formattedShipments[$key]['q']['totalNetCharge']);
                $formattedShipments[$key]['q']['totalNetCharge']['Amount'] = $charges;
            }
        }

        return $formattedShipments;
    }

    public function isMultiShipment($shipments): bool
    {
        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }

        $isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        return $isMultiShipment;
    }

    public function isLGQuotes($quoteSettings, $isResi): bool
    {
        $isLG = (isset($quoteSettings['offerLiftGateDelivery']) && $quoteSettings['offerLiftGateDelivery']);

        if (!$isLG) {
            $isLG = $this->isRADEnabled($quoteSettings, $isResi);
        }

        return $isLG;
    }

    public function isRADEnabled($quoteSettings, $isResi): bool
    {
        $isRAD = (isset($quoteSettings['autoDetectedResidentialAddressesLfg']) && $quoteSettings['autoDetectedResidentialAddressesLfg']) && $isResi;

        return $isRAD;
    }

    public function getShipmentDateAndDays($data): array
    {
        $date = $data['deliveryTimestamp'] ?? null;
        $days = $data['totalTransitTimeInDays'] ?? $data['transitDays'] ?? null;
        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];

        return $dateAndDays;
    }
}
