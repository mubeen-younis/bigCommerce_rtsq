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

    public function formateQuoteBeforeCompile($shipments): array
    {
        $formattedShipments = [];
        foreach ($shipments as $shipment => $quotes) {
            if (!isset($quotes['q'])) {
                continue;
            }

            $quotesArr = $quotes['q'];
            $quotesDataIndex = $quotesArr['pageRoot'];
            $lgStatus = $quotes['liftGateStatus'] ?? '';
            $radStatus = $quotes['residentialStatus'] ?? '';

            $formattedShipments[$shipment]['q'] = array(
                'serviceType' => $quotesDataIndex['bodyMain']['rateQuote']['delivery']['requestedServiceType']['value'] ?? '',
                'serviceDesc' => $quotesDataIndex['pageHead']['pageTitle'] ?? '',
                'lineItems' => $quotesDataIndex['bodyMain']['rateQuote']['lineItem'],
                'liftGateStatus' => $lgStatus,
                'residentialStatus' => $radStatus,
                'deliveryDate' => $quotesArr['deliveryDate'] ?? '',
                'totalTransitTimeInDays' => $quotesArr['totalTransitTimeInDays'] ?? 0,
                'totalNetCharge' => array('Amount' => $quotesDataIndex['bodyMain']['rateQuote']['ratedCharges']['totalCharges'] ?? 0),
            );

            if (isset($quotes['quotesWithoutLiftGate']) && isset($lgStatus) && $lgStatus != 'n') {
                $chargesWithoutLG = $quotes['quotesWithoutLiftGate']['pageRoot']['bodyMain']['rateQuote']['ratedCharges']['totalCharges'] ?? 0;
                $totalCharges = $quotesDataIndex['bodyMain']['rateQuote']['ratedCharges']['totalCharges'];
                $lgFee = $totalCharges - $chargesWithoutLG;

                $formattedShipments[$shipment]['q']['surcharges']['liftgateFee'] = $lgFee;
            }
        }

        return $formattedShipments;
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
