<?php

namespace App\CustomClasses\DayRossLTL;

use App\CustomClasses\CompileQuotes;
use App\CustomClasses\Functions;
use App\Models\TerminalLocation;

class QuotesResults
{
    public $services;

    public function __construct()
    {
        $this->CompileQuotes = new CompileQuotes();
        $this->services = $this->getServices();
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
                } elseif (isset($value['q']['Division']) && $value['q']['Division'] == 'Sameday') {
                    $serviceDescription = $value['q']['Description'] ?? '';
                    $resp = $this->compileSameDayApiQuotes($quoteSettings, $value);

                    if (empty($resp)) {
                        $formattedShipments[$key]['q']['soapBody']['soapFault'] = 'No quotes found';
                        continue;
                    } 

                    $charges = $resp['charges'];
                    $formattedShipments[$key]['q']['surcharges']['twoManFee'] = $resp['twoManFee'];
                    $formattedShipments[$key]['q']['surcharges']['appointmentFee'] = $resp['appointmentFee'];
                    $formattedShipments[$key]['q']['surcharges']['liftgateFee'] = $resp['liftGateFee'];

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
        $amount = str_replace(',', '', $amount);
        $amount = (float) $amount;

        return $amount;
    }

    public function isMultiShipment($shipments): bool
    {
        $numberOfShipments = 0;
        foreach ($shipments as $ship) {
            if (!isset($ship['severity']) && !isset($ship['q']['soapBody']['soapFault'])) {
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

    private function compileSameDayApiQuotes($quoteSettings, $value)
    {
        $shipmentCharges = $value['q']['ShipmentCharges']['ShipmentCharge'] ?? [];
        $standardActiveServicesCodes = $this->setAndGetActiveServices($quoteSettings);
        $premiumFreightServicesCodes = $this->getPremiumFreightServices();
        $allServicesArr = array_merge($standardActiveServicesCodes, $premiumFreightServicesCodes);

        $serviceCode = $value['q']['ServiceLevelCode'] ?? '';
        $charges = 0;

        if (in_array($serviceCode, $allServicesArr)) {
            $charges = $value['q']['TotalAmount'];
        } else {
            return [];
        }

        $twoManDeliveryFee = $appointmentDeliveryFee = $lgFee = 0;

        if (!empty($shipmentCharges)) {
            foreach ($shipmentCharges as $value) {
                if (isset($value['ChargeCode']) && $value['ChargeCode'] == '2-MAN') {
                    $twoManDeliveryFee = $value['Amount'];
                }

                if (isset($value['ChargeCode']) && $value['ChargeCode'] == 'APPT') {
                    $appointmentDeliveryFee = $value['Amount'];
                }

                if (isset($value['ChargeCode']) && $value['ChargeCode'] == 'TLGDEL') {
                    $lgFee = $value['Amount'];
                }
            }
        }

        $resp = [
            'charges' => $charges,
            'twoManFee' => $twoManDeliveryFee,
            'appointmentFee' => $appointmentDeliveryFee,
            'liftGateFee' => $lgFee,
        ];

        return $resp;
    }

    public static function getEnabledPremiumFreightService($quoteSettings)
    {
        $qsServices = $quoteSettings ?? [];

        if (empty($qsServices)) {
            return '';
        }

        $services = ['deliver_to_threshold', 'deliver_to_room_of_choice', 'deliver_and_packaging_removal', 'deliver_to_threshold_two_man', 'deliver_to_room_of_choice_two_man', 'deliver_and_packaging_removal_two_man'];
        $enabledSrvcName = '';

        foreach ($services as $srvc) {
            if (isset($qsServices[$srvc]) && $qsServices[$srvc]) {
                $enabledSrvcName = self::getServiceCode($srvc);
                break;
            }
        }

        return $enabledSrvcName;
    }

    private static function getServiceCode($srvcIndex)
    {
        switch ($srvcIndex) {
            case 'deliver_to_threshold':
                return 'H1';
            case 'deliver_to_room_of_choice':
                return 'H2';
            case 'deliver_and_packaging_removal':
                return 'H3';
            case 'deliver_to_threshold_two_man':
                return 'H4';
            case 'deliver_to_room_of_choice_two_man':
                return 'H5';
            case 'deliver_and_packaging_removal_two_man':
                return 'H6';
            default:
                return '';
        }
    }

    public static function isTwoManDeliveryEnabled($connSettings)
    {
        if (isset($connSettings['quote_settings']['always_two_man_delivery']) && $connSettings['quote_settings']['always_two_man_delivery'] || (isset($connSettings['quote_settings']['offer_two_man_delivery']) && $connSettings['quote_settings']['offer_two_man_delivery'])) {
            return true;
        }

        return false;
    }

    public static function isAppointmentManDeliveryEnabled($connSettings)
    {
        if (isset($connSettings['quote_settings']['always_appointment_delivery']) && $connSettings['quote_settings']['always_appointment_delivery'] || (isset($connSettings['quote_settings']['offer_appointment_delivery']) && $connSettings['quote_settings']['offer_appointment_delivery'])) {
            return true;
        }

        return false;
    }

    private function getServices()
    {
        $services = ['ground_service', 'am_service', 'urgent_pac', 'us_next_pm', 'us_2nd_day', 'us_ground'];

        return $services;
    }

    private function getPremiumFreightServices()
    {
        return ['H1', 'H2', 'H3', 'H4', 'H5', 'H6'];
    }

    public function getActiveServices($quoteSettings)
    {
        // Domestic (CA to CA)
        $activeServices = array();
        if (isset($quoteSettings['service']['AM_SERVICE']) && $quoteSettings['service']['AM_SERVICE'] == 1) {
            $activeServices[] = 'AM';
        }

        if (isset($quoteSettings['service']['GROUND_SERVICE']) && $quoteSettings['service']['GROUND_SERVICE'] == 1) {
            $activeServices[] = 'EG';
        }

        if (isset($quoteSettings['service']['URGENT_PAC']) && $quoteSettings['service']['URGENT_PAC'] == 1) {
            $activeServices[] = 'UP';
        }

        // International (CA to US)
        if (isset($quoteSettings['service']['US_NEXT_PM']) && $quoteSettings['service']['US_NEXT_PM'] == 1) {
            $activeServices[] = 'AD';
        }

        if (isset($quoteSettings['service']['US_2ND_DAY']) && $quoteSettings['service']['US_2ND_DAY'] == 1) {
            $activeServices[] = 'A2';
        }

        if (isset($quoteSettings['service']['US_GROUND']) && $quoteSettings['service']['US_GROUND'] == 1) {
            $activeServices[] = 'AG';
        }

        return $activeServices;
    }

    private function setAndGetActiveServices($quoteSettings = [])
    {
        if (empty($quoteSettings)) {
            return [];
        }

        $activeServices = [];

        foreach ($this->services as $srvc) {
            if (isset($quoteSettings['carrier_services'][$srvc]) && $quoteSettings['carrier_services'][$srvc]) {
                $activeServices[] = $this->getStandardServiceCode($srvc);
            }
        }

        return $activeServices;
    }

    private function getStandardServiceCode($srvcIndex)
    {
        $srvcCode = '';

        switch ($srvcIndex) {
            case 'ground_service':
                $srvcCode = 'EG';
                break;
            case 'am_service':
                $srvcCode = 'AM';
                break;
            case 'urgent_pac':
                $srvcCode = 'UP';
                break;
            case 'us_next_pm':
                $srvcCode = 'AD';
                break;
            case 'us_2nd_day':
                $srvcCode = 'A2';
                break;
            case 'us_ground':
                $srvcCode = 'AG';
                break;
        }

        return $srvcCode;
    }
}
