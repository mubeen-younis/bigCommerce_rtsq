<?php

namespace App\CustomClasses\YrcLTL;

use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;

class QuotesResults
{
    public function __construct()
    {
        $this->CompileQuotes = new CompileQuotes();
    }

    public function getServiceTitle($title, $data, $serviceCode, $quoteSettings, $isResi = false)
    {
        if (isset($data['totalTransitTimeInDays']) && $data['totalTransitTimeInDays'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 2) {
            $title = $title . ' (Estimated number of days until delivery is ' . $data['totalTransitTimeInDays'] . ')';
        } else if (isset($data['deliveryTimestamp']) && $data['deliveryTimestamp'] !== '' && isset($quoteSettings['delivery_estimate_options']) && $quoteSettings['delivery_estimate_options'] == 3) {
            $title = $title . ' (Delivery by ' . date('m-d-y h:i A', strtotime($data['deliveryTimestamp'])) . ')';
        }

        $resiTitle = '';
        if ($isResi) {
            $resiTitle = Constant::RESI_LABEL;
        }

        return $title . $resiTitle;
    }

    public function isSuppressedRatesShipment($shipments)
    {
        $isSuppressedRates = false;

        foreach ($shipments as $origin => $quote) {
            if (isset($quote['severity']) && isset($quote['q']['error'])) {
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

    public function formateQuoteBeforeCompile($shipments, $connSettings): array
    {
        $formattedShipments = $shipments ?? [];
        if ($this->isSuppressedRatesShipment($shipments)) {
            return $shipments;
        }

        foreach ($shipments as $shipment => $quotes) {
            if (!isset($quotes['q']) || isset($quotes['q']['error'])) {
                continue;
            }

            $quotesArr = $quotes['q'];
            $lgStatus = $quotes['liftGateStatus'] ?? '';
            $radStatus = $quotes['residentialStatus'] ?? '';
            $lgFee = 0;

            if ($connSettings['yrc_rates'] == 1) {
                $quotesDataIndex = $quotesArr['pageRoot'];
                $rateQuote = $quotesDataIndex['bodyMain']['rateQuote'];

                $formattedShipments[$shipment]['q'] = $this->formatShipments($quotesArr, $rateQuote['delivery']['requestedServiceType']['value'], $quotesDataIndex['pageHead']['pageTitle'], $rateQuote['lineItem'], $lgStatus, $radStatus, $rateQuote['ratedCharges']['totalCharges']);

                if (isset($quotes['quotesWithoutLiftGate']) && isset($lgStatus) && $lgStatus != 'n') {
                    $chargesWithoutLG = $quotes['quotesWithoutLiftGate']['pageRoot']['bodyMain']['rateQuote']['ratedCharges']['totalCharges'] ?? 0;
                    $totalCharges = $quotesDataIndex['bodyMain']['rateQuote']['ratedCharges']['totalCharges'];
                    $lgFee = number_format(($totalCharges - $chargesWithoutLG) / 100, 2);

                    $formattedShipments[$shipment]['q']['surcharges']['liftgateFee'] = $lgFee;
                }
            } else {
                $items = $quotesArr['LineItem'] ?? [];
                $lineItems = [];
                $isLG = false;

                foreach ($items as $key => $value) {
                    if ($value['@attributes']['Type'] == 'Commodity') {
                        $lineItems[] = $value;
                        $lineItems[$key]['hazardous'] = $value['Hazardous'] ?? '';
                    }

                    if (isset($value['Description']) && $value['Description'] == 'LIFTGATE SERVICE DESTINATION' && isset($value['Code']) && $value['Code'] == 'LFTD') {
                        $lgFee = number_format($value['Charges'] / 100, 2) ?? 0;
                        $isLG = true;
                    }

                    if (isset($value['Description']) && $value['Description'] == 'LIMITED ACCESS DELIVERY' && isset($value['Code']) && $value['Code'] == 'LTDD') {
                        $limitedAccessDeliveryFee = number_format($value['Charges'] / 100, 2) ?? 0;
                    }
                }

                $formattedShipments[$shipment]['q'] = $this->formatShipments($quotesArr,
                    $quotesArr['Delivery']['RequestedServiceType'], 'YRC', $lineItems, $lgStatus, $radStatus, $quotesArr['RatedCharges']['TotalCharges'], $limitedAccessDeliveryFee ?? 0);

                if (isset($lgStatus) && $lgStatus != 'n' && $isLG) {
                    $formattedShipments[$shipment]['q']['surcharges']['liftgateFee'] = $lgFee;
                }
            }

            $formattedShipments[$shipment]['q']['InstorPickupLocalDelivery'] = $quotes['InstorPickupLocalDelivery'] ?? [];
        }

        return $formattedShipments;
    }

    private function formatShipments($quotesArr, $srvcType, $srvcDesc, $lineItems, $lgStatus, $radStatus, $charges, $limitedAccessDeliveryFee = 0): array
    {
        return array(
            'serviceType' => $srvcType ?? '',
            'serviceDesc' => $srvcDesc ?? '',
            'lineItems' => $lineItems,
            'liftGateStatus' => $lgStatus,
            'residentialStatus' => $radStatus,
            'deliveryDate' => $quotesArr['deliveryDate'] ?? '',
            'totalTransitTimeInDays' => $quotesArr['totalTransitTimeInDays'] ?? 0,
            'totalNetCharge' => array('Amount' => number_format($charges / 100, 2) ?? 0),
            'limitedAccessDeliveryFee' => $limitedAccessDeliveryFee ?? 0,
        );
    }

    public function quoteSettingsData()
    {
        $fields = [
            'labelAs' => 'labelAs',
            'dlrvyEstimates' => 'dlrvyEstimates',
            'residentialDlvry' => 'residentialDlvry',
            'liftGate' => 'liftGate',
            'OfferLiftgateAsAnOption' => 'OfferLiftgateAsAnOption',
            'RADforLiftgate' => 'RADforLiftgate',
            'hndlngFee' => 'hndlngFee',
            'symbolicHndlngFee' => 'symbolicHndlngFee',
        ];

        foreach ($fields as $key => $field) {
            $this->$key = $this->configSettings[$field] ?? '';
        }

        $this->resiLabel = Constant::RESI_LABEL;
        $this->lgLabel = Constant::LIFT_LABEL;
        $this->resiLgLabel = Constant::RESI_LIFT_LABEL;
    }

    public function getCompiledQuotes($services, $arraySorting, $isMulitshipment)
    {
        if (empty($arraySorting) || empty($services)) {
            return [];
        }

        asort($arraySorting['simple']);
        $options = $isMulitshipment ? 1 : 2;
        $sliced = array_slice($arraySorting['simple'], 0, $options, true);
        $resp = array_intersect_key($services, $sliced);

        return $resp;
    }
}
