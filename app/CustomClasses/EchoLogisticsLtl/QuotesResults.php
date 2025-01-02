<?php

namespace App\CustomClasses\EchoLogisticsLtl;

use App\CustomClasses\CompileQuotes;

class QuotesResults
{
    public function __construct()
    {
        $this->CompileQuotes = new CompileQuotes();
    }

    public function formatQuoteBeforeCompile(array $shipments): array
    {
        $formattedShipments = [];

        foreach ($shipments as $origin => $value) {
            if (isset($value['severity']) || !isset($value['q'])) {
                continue;
            }

            $shipments = $value['q'] ?? [];

            if (isset($shipments) && !empty($shipments)) {
                foreach ($shipments as $key => $value) {
                    $formattedShipments[$origin][$key]['serviceType'] = $value['CarrierSCAC'];
                    $formattedShipments[$origin][$key]['serviceDesc'] = $value['CarrierName'];
                    $formattedShipments[$origin][$key]['CarrierGuarantee'] = $value['CarrierGuarantee'];
                    $formattedShipments[$origin][$key]['totalNetCharge']['Amount'] = $value['TotalCharge'];
                    $formattedShipments[$origin][$key]['TransitDays'] = $value['CarrierTransitDays'];
                    $formattedShipments[$origin][$key]['totalTransitTimeInDays'] = $value['totalTransitTimeInDays'];
                    $formattedShipments[$origin][$key]['deliveryDate'] = $value['deliveryDate'];

                    $accessorials = $value['Accessorials'] ?? [];
                    if (!empty($accessorials)) {
                        $lgAccessType = 'LIFTGATEDELIVERYREQUIRED';

                        foreach ($accessorials as $key => $acc) {
                            if (isset($acc['Type']) && $acc['Type'] == $lgAccessType) {
                                $formattedShipments[$origin][$key]['surcharges']['liftgateFee'] = $acc['Charge'];
                            }
                        }
                    }
                }
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
            if (!isset($ship['severity'])) {
                $numberOfShipments++;
            }
        }

        $isMultiShipment = is_countable($shipments) && $numberOfShipments > 1;
        return $isMultiShipment;
    }

    public function isLGQuotes($quoteSettings): bool
    {
        $isLG = (isset($quoteSettings['offerLiftGateDelivery']) && $quoteSettings['offerLiftGateDelivery']);

        return $isLG;
    }

    public function isRADEnabled($quoteSettings, $isResi): bool
    {
        $isRAD = (isset($quoteSettings['autoDetectedResidentialAddressesLfg']) && $quoteSettings['autoDetectedResidentialAddressesLfg']) && $isResi;

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

    public function isLGEnabled($connSettings)
    {
        return ((isset($connSettings['quote_settings']['alwaysLiftGateDelivery']) && $connSettings['quote_settings']['alwaysLiftGateDelivery']) ||
            (isset($connSettings['quote_settings']['offerLiftGateDelivery']) && $connSettings['quote_settings']['offerLiftGateDelivery'])) ? 'Y' : 'N';
    }

    public function getLGFee($accessorials)
    {
        $lgFee = 0;

        if (isset($accessorials) && !empty($accessorials)) {
            $lgAccessType = 'LIFTGATEDELIVERYREQUIRED';

            foreach ($accessorials as $acc) {
                if (isset($acc['Type']) && $acc['Type'] == $lgAccessType) {
                    $lgFee = number_format($acc['Charge'], 2, '.', '');
                    break;
                }
            }
        }

        return $lgFee;
    }

    public function getNBDFee($accessorials)
    {
        $nbdFee = 0;
        if (isset($accessorials) && !empty($accessorials)) {
            $lgAccessType = 'NOTIFYPRIORTODELIVERY';

            foreach ($accessorials as $acc) {
                if (isset($acc['Type']) && $acc['Type'] == $lgAccessType) {
                    $nbdFee = number_format($acc['Charge'], 2, '.', '');
                    break;
                }
            }
        }

        return $nbdFee;
    }

    public function getResiFee($accessorials)
    {
        $resiFee = 0;
        if (isset($accessorials) && !empty($accessorials)) {
            $lgAccessType = 'RESIDENTIALDELIVERYFEE';

            foreach ($accessorials as $acc) {
                if (isset($acc['Type']) && $acc['Type'] == $lgAccessType) {
                    $resiFee = number_format($acc['Charge'], 2, '.', '');
                    break;
                }
            }
        }

        return $resiFee;
    }

    public function getHazardousMaterialsFee($accessorials)
    {
        $hazardousMaterialsFee = 0;
        if (isset($accessorials) && !empty($accessorials)) {
            $lgAccessType = 'HAZARDOUSMATERIAL';

            foreach ($accessorials as $acc) {
                if (isset($acc['Type']) && $acc['Type'] == $lgAccessType) {
                    $hazardousMaterialsFee = number_format($acc['Charge'], 2, '.', '');
                    break;
                }
            }
        }

        return $hazardousMaterialsFee;
    }
}
