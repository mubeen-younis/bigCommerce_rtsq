<?php

namespace App\CustomClasses\FreightQuote\ChrLtl;

use App\CustomClasses\CompileQuotes;

class QuotesResults
{
    public function __construct()
    {
        $this->CompileQuotes = new CompileQuotes();
    }

    public function getApiArr()
    {

    }

    public function formateQuoteBeforeCompile($shipments)
    {
        foreach ($shipments as $shipment => $quotes) {
            if (!isset($quotes['q'])) {
                continue;
            }
            /*
             * formate if only old versions
             * check $shipments[$shipment]['q']['serviceType'] is old version
             */
            $quote = $quotes['q'];
            $key = 0;
            if (!isset($shipments[$shipment]['q']['serviceType'])) {
                unset($shipments[$shipment]['q']);
                $shipments[$shipment]['q'][$key] = $quote;
                $shipments[$shipment]['q'][$key]['serviceType'] = 'xpo';
                $shipments[$shipment]['q'][$key]['serviceDesc'] = 'Freight';
                $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = $quote['NetCharge'][0] ?? 0;
                $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = $this->netCharge($quote['NetCharge']);

                $shipments[$shipment]['q'][$key]['deliveryTimestamp'] = $quote['deliveryDate'] ?? '';
                $shipments[$shipment]['q'][$key]['transitTime'] = $quote['TransitTime'][0] ?? '';
                $shipments[$shipment]['q'][$key]['totalTransitTimeInDays'] = $quote['totalTransitTimeInDays'] ?? '';
                $shipments[$shipment]['q'][$key]['surcharges']['liftgateFee'] = $quote['AccessorialCharges']['OtherAccessorialChargesFormated']['DLG'] ?? 0;
            } else {
                unset($shipments[$shipment]['q']);
                $shipments[$shipment]['q'][$key] = $quote;
                unset($shipments[$shipment]['q'][$key]['totalNetCharge']);
                $shipments[$shipment]['q'][$key]['totalNetCharge']['Amount'] = $quote['totalNetCharge'] ?? 0;
                $shipments[$shipment]['q'][$key]['serviceType'] = 'xpo';
                $shipments[$shipment]['q'][$key]['serviceDesc'] = 'Freight';
                $shipments[$shipment]['q'][$key]['transitTime'] = $quote['transitDays'] ?? '';
                $shipments[$shipment]['q'][$key]['totalTransitTimeInDays'] = $quote['totalTransitTimeInDays'] ?? '';
            }
        }
        return $shipments;
    }

    public function netCharge($netCharge)
    {
        $amount = 0;
        foreach ($netCharge as $charge) {
            if (is_array($charge)) {
                if (isset($charge['currency']) && $charge['currency'] === 'USD') {
                    $amount = $charge[0] ?? 0;
                    break;
                }

            } else {
                $amount = $netCharge[0] ?? 0;
                break;
            }
        }
        return $amount;
    }

    public function calculatePrice($data, $uoteSettings, $lgOption = false, $notify = false, $laccess = false)
    {
        $lgCost = $lgOption ? 0 : $data['surcharges']['liftgateFee'] ?? 0;
        $nCost = $notify ? 0 : $data['surcharges']['notifyDeliveryFee'] ?? 0;
        $laCost = $laccess ? 0 : $data['surcharges']['limitedAccessDeliveryFee'] ?? 0;
        $basePrice = (float) $data['totalNetCharge']['Amount'];
        $basePrice = $basePrice - $lgCost - $nCost - $laCost;
        $basePrice = $this->CompileQuotes->calculateHandlingFee($basePrice, $uoteSettings);
        return $basePrice;
    }

    public function getAccessorialCode($isResi = false, $lgOption = false, $notify = false, $laccess = false)
    {
        $access = '';
        if ($isResi) {
            $access .= '+R';
        }
        if ($lgOption) {
            $access .= '+LG';
        }
        if ($notify) {
            $access .= '+N';
        }
        if ($laccess) {
            $access .= '+LA';
        }
        return $access;
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
        $days = $data['totalTransitTimeInDays'] ?? $data['transitDays'] ?? null;
        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];

        return $dateAndDays;
    }
}
