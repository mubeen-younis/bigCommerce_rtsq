<?php

namespace App\CustomClasses\DayRossLTL;

use App\CustomClasses\CompileQuotes;
use App\CustomClasses\Functions;
use App\Models\TerminalLocation;

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
            if (!isset($ship['severity']) && !isset($quote['q']['soapBody']['soapFault'])) {
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

    public function HatQuoteCompile($shipments, $quoteSettings)
    {
        $hatShipments = [];
        if (isset($shipments['holdAtTerminalResponse']) && !empty($shipments['holdAtTerminalResponse'])) {
            foreach ($shipments['holdAtTerminalResponse'] as $shipment => $quotes) {

                foreach ($quotes as $key => $quote) {
                    $isStandardService = isset($quote['ratserviceLevel']) && isset($quote['ratserviceLevel']['rattext']) && $quote['ratserviceLevel']['rattext'] == 'LTL Standard Transit';

                    if (!$isStandardService) {
                        continue;
                    }

                    $hatResp[] = $quote;
                    $srvcTitle = $quoteSettings['label_as'] ?? Functions::$simpleLTLTitle ?? $quote['ratserviceLevel']['rattext'] ?? '';
                    $terminalInfo = $shipments['holdAtTerminalResponse']['terminalInfo'];
                    $hatCompiledQuotes = $this->formatHATQuotes($hatResp, $srvcTitle, $quoteSettings, $terminalInfo);
                    if (!empty($hatCompiledQuotes)) {
                        $hatShipments = $hatCompiledQuotes;
                    }
                }
            }
        }
        return $hatShipments;
    }

    private function formatHATQuotes($hatQuotes = [], $srvcTitle = '', $quoteSettings, $terminalInfo = [])
    {
        if (empty($hatQuotes)) {
            return [];
        }

        $compiledQuotes = [];
        foreach ($hatQuotes as $quote) {
            $compiledQuotes['serviceType'] = 'estesltl+HAT+';
            $title = $srvcTitle ?? '';
            $address['streetLine'] = $terminalInfo['address']['tranline1'] ?? '';
            $address['city'] = $terminalInfo['address']['trancity'] ?? '';
            $address['state'] = $terminalInfo['address']['transtateProvince'] ?? '';
            $address['zipCode'] = $terminalInfo['address']['tranpostalCode'] ?? '';
            $address['countryCode'] = $terminalInfo['address']['trancountryCode'] ?? '';
            $distance = $terminalInfo['distance']['text'] ?? '0 mi';
            $phoneNumber = $terminalInfo['phoneNumber']['trancountry'] . $terminalInfo['phoneNumber']['tranareaCode'] . $terminalInfo['phoneNumber']['transubscriber'] ?? '';

            $compiledQuotes['serviceDesc'] = Functions::getHATTitle($title, $address, $distance, $phoneNumber);
            $compiledQuotes['totalNetCharge']['Amount'] = Functions::getHATPrice($quote['ratpricing']['rattotalPrice'], $quoteSettings['hold_at_terminal_price'] ?? 0);
            $compiledQuotes['deliveryTimestamp'] = $quote['ratdelivery']['ratdate'] ?? '';
            $compiledQuotes['totalTransitTimeInDays'] = $quote['ratdelivery']['totalTransitTimeInDays'] ?? '';
            $compiledQuotes['transitTime'] = $quote['ratdelivery']['rattime'] ?? '';
        }

        return $compiledQuotes;
    }

    public function arrangeHATFreight($finalQuotes, $HATQuotes)
    {
        if (empty($HATQuotes)) {
            return $finalQuotes;
        }

        $newQuotes = [];
        foreach ($HATQuotes as $data) {

            if (empty($data)) {
                return $finalQuotes;
            }
            $newQuotes[] = [
                'code' => $data['serviceType'],
                'title' => $data['serviceDesc'],
                'rate' => $data['totalNetCharge']['Amount'],
            ];
        }

        return array_merge($finalQuotes, $newQuotes);
    }

    public function getAndformatHATQuotes($quoteSettings, $shipments = [])
    {
        if (empty($quoteSettings) || blank($shipments) || !isset($quoteSettings['hold_at_terminal']) || $quoteSettings['hold_at_terminal'] == false) {
            return [];
        }

        $terminals = TerminalLocation::getTerminalLocations();
        if (blank($terminals)) {
            return [];
        }

        $hatQuotes = [];

        foreach ($shipments as $ship) {
            $isError = isset($ship['q']['soapBody']['soapFault']);
            if ($isError) {
                continue;
            }

            $code = $ship['q']['DestinationTerminal'] ?? 'CLG';
            $charges = $ship['q']['TotalAmount'] ?? $ship['q']['TotalCharges'] ?? 0.00;

            foreach ($terminals as $terminal) {
                if ($terminal['terminal_code'] == $code) {
                    $terminal['serviceType'] = 'dayrossltl+HAT+' . $code;
                    $title = !empty($quoteSettings['label_as']) ? $quoteSettings['label_as'] : 'Day & Ross';
                    $address['city'] = $terminal['city'] ?? '';
                    $address['state'] = $terminal['state'] ?? '';
                    $address['zipCode'] = $terminal['zip'] ?? '';
                    $distance = '';
                    $phoneNumber = $terminal['phoneNo'] ?? '';

                    $terminal['serviceDesc'] = Functions::getHATTitle($title, $address, $distance, $phoneNumber);
                    $terminal['totalNetCharge']['Amount'] = Functions::getHATPrice($charges, $quoteSettings['hold_at_terminal_price'] ?? 0);

                    $hatQuotes[] = $terminal;
                }
            }
        }

        return $hatQuotes;
    }
}
