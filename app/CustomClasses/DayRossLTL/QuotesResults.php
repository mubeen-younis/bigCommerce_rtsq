<?php

namespace App\CustomClasses\DayRossLTL;

use App\CustomClasses\CompileQuotes;

class QuotesResults
{
    public function __construct()
    {
        $this->CompileQuotes = new CompileQuotes();
    }

    public function formateQuoteBeforeCompile($shipments, $quoteSettings): array
    {
        $formattedShipments = $shipments ?? [];

        foreach ($formattedShipments as $key => $value) {
            $isError = isset($formattedShipments[$key]['q']['soapBody']['soapFault']);

            if (isset($formattedShipments[$key]['q']) && !$isError) {
                $formattedShipments[$key]['q']['serviceType'] = $formattedShipments[$key]['q']['serviceDesc'] = 'Day & Ross';
                $charges = $value['q']['TotalCharges'] ?? 0;
                $charges = number_format($charges, 2, '.', '');
                // $charges = floatval($charges) / 10;
                $charges = floatval($charges);
                $formattedShipments[$key]['q']['totalNetCharge']['Amount'] = $charges;

                if ($this->isLGQuotes($quoteSettings) && isset($value['q']['FuelSurCharge'])) {
                    $formattedShipments[$key]['q']['surcharges']['liftgateFee'] = $value['q']['FuelSurCharge'];
                }
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

    public function isLGQuotes($quoteSettings): bool
    {
        $isLG = (isset($quoteSettings['alwaysLiftGateDelivery']) && $quoteSettings['alwaysLiftGateDelivery']) ||
            (isset($quoteSettings['offerLiftGateDelivery']) && $quoteSettings['offerLiftGateDelivery']);

        return $isLG;
    }

    public function isRADEnabled($quoteSettings, $isResi): bool
    {
        $isRAD = ((isset($quoteSettings['autoDetectedResidentialAddresses']) && $quoteSettings['autoDetectedResidentialAddresses']) &&
            (isset($quoteSettings['autoDetectedResidentialAddressesLfg']) && $quoteSettings['autoDetectedResidentialAddressesLfg'])) && $isResi;

        return $isRAD;
    }
}
