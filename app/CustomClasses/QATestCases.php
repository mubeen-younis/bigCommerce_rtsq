<?php

namespace App\CustomClasses;

class QATestCases
{
    public static function verifyRates($carrier, $ws_rates)
    {
        $carriers = [
            'fedexLTL'      => 'testFedexFreight',
            'estes'         => 'testEstesFreight',
            'odfl4me'       => 'testODFLFreight',
            'rnl'           => 'testRnLFreight',
            'saia'          => 'testSAIAFreight',
            'xpoLogistics'  => 'testXPOFreight',
        ];

        if (array_key_exists($carrier, $carriers)) {
            call_user_func([self::class, $carriers[$carrier]], $ws_rates);
        }
    }

    private static function extractCharges($data, $specificRates, $accessorialKeys)
    {
        $net_freight_charge = [];
        $accessorial_charges = array_fill_keys($accessorialKeys, 0);

        foreach ($data as $record) {
            // Match specific service rates
            $service = $record['ratserviceLevel']['rattext'] ?? '';
            foreach ($specificRates as $rate) {
                if (strpos($service, $rate) !== false) {
                    $net_freight_charge[$rate] = $record['ratpricing']['rattotalPrice'] ?? 0;
                }
            }

            // Extract accessorial charges
            $accessorials = $record['rataccessorialInfo']['rataccessorial'] ?? [];
            if (is_array($accessorials)) {
                foreach ($accessorials as $accessorial) {
                    $description = $accessorial['ratdescription'] ?? null;
                    if ($description && isset($accessorial_charges[$description])) {
                        $accessorial_charges[$description] = $accessorial['ratcharge'] ?? 0;
                    }
                }
            }
        }

        return [$net_freight_charge, $accessorial_charges];
    }

    private static function prepareQuote($net_freight_charge, $accessorial_charges, $accessorialKeys)
    {
        return [
            'net_freight_charge'       => round($net_freight_charge['LTL Standard Transit'] ?? 0, 2),
            'residential_delivery'     => round($accessorial_charges[$accessorialKeys[0]] ?? 0, 2),
            'liftgate_delivery'        => round($accessorial_charges[$accessorialKeys[1]] ?? 0, 2),
            'appointment_delivery'     => round($accessorial_charges[$accessorialKeys[2]] ?? 0, 2),
            'notify_before_delivery'   => round($accessorial_charges[$accessorialKeys[3]] ?? 0, 2),
        ];
    }

    private static function processRates($ws_rates, $specificRates, $accessorialKeys)
    {
        global $ws_quotes;
        $location = 0;

        foreach ($ws_rates as $origin => $quotes) {
            $data = $quotes['q'] ?? [];
            list($net_freight_charge, $accessorial_charges) = self::extractCharges($data, $specificRates, $accessorialKeys);

            $ws_quotes[$location] = self::prepareQuote($net_freight_charge, $accessorial_charges, $accessorialKeys);
            $location++;
        }
    }

    public static function testFedexFreight($ws_rates)
    {
        $specificRates = ['LTL Standard Transit', 'Guaranteed LTL Standard Transit'];
        $accessorialKeys = [
            'Residential Delivery',
            'Lift-Gate Service (Delivery)',
            'RESIDENTIAL APPT/SIGNATURE DELV',
            'Notify Request',
        ];

        self::processRates($ws_rates, $specificRates, $accessorialKeys);
    }

    public static function testEstesFreight($ws_rates)
    {
        self::testFedexFreight($ws_rates); // Reuse the same logic for Estes as it shares similar structure
    }

    public static function testODFLFreight($ws_rates)
    {
        self::testFedexFreight($ws_rates); // ODFL uses the same structure
    }

    public static function testXPOFreight($ws_rates)
    {
        global $ws_quotes;
        $location = 0;

        foreach ($ws_rates as $origin => $quotes) {
            $data = $quotes['q'] ?? [];

            $ws_quotes[$location] = [
                'net_freight_charge'       => round($data['totalNetCharge'] ?? 0, 2),
                'residential_delivery'     => round($data['surcharges']['residentialFee'] ?? 0, 2),
                'liftgate_delivery'        => round($data['surcharges']['liftgateFee'] ?? 0, 2),
                'notify_before_delivery'   => round($data['surcharges']['notifyDeliveryFee'] ?? 0, 2),
            ];

            $location++;
        }
    }

    public static function testSAIAFreight($ws_rates)
    {
        self::testXPOFreight($ws_rates); // Reuse XPO logic for SAIA
    }
}
