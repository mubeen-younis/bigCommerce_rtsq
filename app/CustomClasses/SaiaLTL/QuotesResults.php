<?php

namespace App\CustomClasses\SaiaLTL;

class QuotesResults
{
    public function isSuppressedRatesShipment($shipments): bool
    {
        $isSuppressedRates = false;

        foreach ($shipments as $origin => $quote) {
            if (isset($quote['severity']) || isset($quote['q']['error'])) {
                continue;
            }

            $insPickupAndLocDel = $quote['InstorPickupLocalDelivery'] ?? [];
            if (isset($insPickupAndLocDel) && !blank($insPickupAndLocDel)) {
                if (isset($insPickupAndLocDel['suppress']) && $insPickupAndLocDel['suppress'] == 1) {
                    $isSuppressedRates = true;
                    break;
                }
            }
        }

        return $isSuppressedRates;
    }

    public function formatQuotesBeforeCompilation($shipments): array
    {
        $formattedShipments = $shipments ?? [];

        foreach ($formattedShipments as $key => $value) {
            $quote = $value ?? [];
            $isError = isset($quote['severity']);

            if (!blank($quote) && isset($quote['q']) && !$isError) {
                unset($formattedShipments[$key]['debug']);
                $formattedShipments[$key]['q']['serviceType'] = $formattedShipments[$key]['q']['serviceDesc'] = 'Saia Ltl';

                $charges = $formattedShipments[$key]['q']['totalNetCharge'];
                unset($formattedShipments[$key]['q']['totalNetCharge']);
                $formattedShipments[$key]['q']['totalNetCharge']['Amount'] = $charges;
            }
        }

        return $formattedShipments;
    }

    public function formatCharges($charges): int
    {
        $amount = $charges ?? 0;
        $amount = str_replace(',', '', $amount);
        $amount = (float) $amount;

        return $amount;
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
        $isLG = (isset($quoteSettings['alwaysLiftGateDelivery']) && $quoteSettings['alwaysLiftGateDelivery']) ||
            (isset($quoteSettings['offerLiftGateDelivery']) && $quoteSettings['offerLiftGateDelivery']);

        if (!$isLG) {
            $isLG = $this->isRADEnabled($quoteSettings, $isResi);
        }

        return $isLG;
    }

    public function isRADEnabled($quoteSettings, $isResi): bool
    {
        $isRAD = ((isset($quoteSettings['autoDetectedResidentialAddresses']) && $quoteSettings['autoDetectedResidentialAddresses']) &&
            (isset($quoteSettings['autoDetectedResidentialAddressesLfg']) && $quoteSettings['autoDetectedResidentialAddressesLfg'])) && $isResi;

        return $isRAD;
    }

    public function getShipmentDateAndDays($data): array
    {
        $date = $data['deliveryTimestamp'] ?? null;
        $days = $data['totalTransitTimeInDays'] ?? null;
        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];

        return $dateAndDays;
    }
}
