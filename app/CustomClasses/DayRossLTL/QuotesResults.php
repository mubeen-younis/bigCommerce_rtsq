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
            $serviceDescription = '';
            $charges = 0;

            if (isset($formattedShipments[$key]['q']) && !$isError) {
                if (isset($value['q']['ServiceLevelCode']) && $value['q']['ServiceLevelCode'] == 'GL' && isset($value['q']['ShipmentCharges'])) {
                    $serviceDescription = $value['q']['Description'];
                    $charges = $this->formatCharges($value['q']['TotalAmount']);
                    $charges = $value['q']['TotalAmount'];

                    $shipmentCharges = $value['q']['ShipmentCharges']['ShipmentCharge'];
                    foreach ($shipmentCharges as $k => $value) {
                        if (isset($value['ChargeCode']) && $value['ChargeCode'] == 'TLGDEL' && isset($value['Description']) && $value['Description'] == 'TAILGATE DELIVERY') {
                            $formattedShipments[$key]['q']['surcharges']['liftgateFee'] = $this->formatCharges($value['Amount']);
                            $formattedShipments[$key]['q']['surcharges']['liftgateFee'] = $value['Amount'];
                        }
                    }
                    unset($formattedShipments[$key]['q']['ShipmentCharges']);
                } else {
                    $serviceDescription = 'Day & Ross';
                    $charges = $this->formatCharges($value['q']['TotalCharges']);
                    $charges = $value['q']['TotalCharges'];

                    if ($this->isLGQuotes($quoteSettings) && isset($value['q']['FuelSurCharge'])) {
                        $formattedShipments[$key]['q']['surcharges']['liftgateFee'] = $value['q']['FuelSurCharge'];
                    }
                }

                $formattedShipments[$key]['q']['serviceType'] = $formattedShipments[$key]['q']['serviceDesc'] = $serviceDescription;
                $formattedShipments[$key]['q']['totalNetCharge']['Amount'] = $charges;
            }
        }

        return $formattedShipments;
    }

    public function formatCharges($charges): int
    {
        $amount = $charges ?? 0;
        // $amount = number_format($amount, 2, '.', '');
        $amount = str_replace(',', '', $amount);
        $amount = (float) $amount;

        return $amount;
    }

    public function isMultiShipment($shipments): bool
    {
        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if (!isset($ship['severity']) || !isset($quote['q']['soapBody']['soapFault'])) {
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

    public function getShipmentDateAndDays($data): array
    {
        $date = $data['deliveryDate'] ?? null;
        $days = $data['totalTransitTimeInDays'] ?? null;
        $dateAndDays = ['deliveryDate' => $date, 'totalTransitTimeInDays' => $days];

        return $dateAndDays;
    }

    public function formatOriginQuotesArray($origin, $index, $access, $price, $title)
    {
        $originQuotes[$origin][$index]['code'] = 'dayrossltl' . $access;
        $originQuotes[$origin][$index]['rate'] = $price;
        $originQuotes[$origin][$index]['title'] = $title;

        return $originQuotes;
    }
}
