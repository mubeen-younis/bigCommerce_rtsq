<?php

namespace App\CustomClasses;

class QATestCases
{
    public static function verifyRates($carrier, $ws_rates) {

            $qa = new QATestCases();
         
            switch ($carrier) {
                case 'fedexLTL':
                    $qa->testFedexFreight($ws_rates);
                    break;
                case 'estes':
                    $qa->testEstesFreight($ws_rates);
                    break;
                case 'odfl4me':
                    $qa->testODFLFreight($ws_rates);
                    break;
                case 'rnl':
                    $qa->testRnLFreight($ws_rates);
                    break;
                case 'saia':
                    $qa->testSAIAFreight($ws_rates);
                    break;
                case 'xpoLogistics':
                    $qa->testXPOFreight($ws_rates);
                    break;
                default:
                    # code...
                    break;
            }
       
    }

    public function testFedexFreight($ws_rates) {

        global $ws_quotes;
        $location = 0;
        // Initialize variables to store the results
        $net_freight_charge = [];

        foreach ($ws_rates as $origin => $quotes) {
           $data = $quotes['q'] ?? [];
            $accessorial_charges = [
                    'Residential Delivery' => 0,
                    'Lift-Gate Service (Delivery)' => 0,
                    'RESIDENTIAL APPT/SIGNATURE DELV' => 0,
                    'Notify Request' => 0,
            ];
            // Loop through each record
            foreach ($data as $record) {
                // Check and store the total price for specific rattext values
                $service = $record['ratserviceLevel']['rattext'];
                if (strpos($service, 'LTL Standard Transit') !== false || strpos($service, 'Guaranteed LTL Standard Transit') !== false) {
                    $net_freight_charge[$service] = $record['ratpricing']['rattotalPrice'];
                }

                /*
                Explanation of Changes:

                Validate Data Type:
                is_array() ensures that rataccessorial is an array before looping through it.

                Null Coalescing Operator (??):
                Checks if keys like 'ratdescription' or 'ratcharge' exist, defaulting to null or 0 if they don’t.
                Check Individual Elements:

                Ensure each item in the loop ($accessorial) is also an array to prevent accessing offsets on strings.
                */
                // Extract charges for specific accessorial description
                if (isset($record['rataccessorialInfo']['rataccessorial']) && is_array($record['rataccessorialInfo']['rataccessorial'])) {
                    foreach ($record['rataccessorialInfo']['rataccessorial'] as $accessorial) {
                        if (is_array($accessorial)) { // Ensure $accessorial is an array
                            $description = $accessorial['ratdescription'] ?? null; // Use null coalescing to avoid undefined index issues
                            if ($description && array_key_exists($description, $accessorial_charges)) {
                                $accessorial_charges[$description] = $accessorial['ratcharge'] ?? 0;
                            }
                        }
                    }
                }
            }

            $net_freight_charge = $net_freight_charge['LTL Standard Transit'] ?? 0;
            $residential_delivery_fee = $accessorial_charges['Residential Delivery'] ?? 0;
            $lift_gate_delivery_fee = $accessorial_charges['Lift-Gate Service (Delivery)'] ?? 0;
            $appointment_delivery_fee = $accessorial_charges['RESIDENTIAL APPT/SIGNATURE DELV'] ?? 0;
            $notify_before_delivery_fee = $accessorial_charges['Notify Request'] ?? 0;

              // Prepare quote details
            $ws_quotes[$location] = [
                'net_freight_charge' => round($net_freight_charge, 2),
                'residential_delivery' => round($residential_delivery_fee, 2),
                'liftgate_delivery' => round($lift_gate_delivery_fee, 2),
                'appointment_delivery' => round($appointment_delivery_fee, 2),
                'notify_before_delivery' => round($notify_before_delivery_fee, 2)
            ];

            // Increment location for next iteration
            $location = $location + 1;
         }
    }

    public function testEstesFreight($ws_rates) {

        global $ws_quotes;
        $location = 0;
        // Initialize variables to store the results
        $net_freight_charge = [];

        foreach ($ws_rates as $origin => $quotes) {
           $data = $quotes['q'] ?? [];
            $accessorial_charges = [
                    'Residential Delivery' => 0,
                    'Lift-Gate Service (Delivery)' => 0,
                    'RESIDENTIAL APPT/SIGNATURE DELV' => 0,
                    'Notify Request' => 0,
            ];
            // Loop through each record
            foreach ($data as $record) {
                // Check and store the total price for specific rattext values
                $service = $record['ratserviceLevel']['rattext'];
                if (strpos($service, 'LTL Standard Transit') !== false || strpos($service, 'Guaranteed LTL Standard Transit') !== false) {
                    $net_freight_charge[$service] = $record['ratpricing']['rattotalPrice'];
                }

                /*
                Explanation of Changes:

                Validate Data Type:
                is_array() ensures that rataccessorial is an array before looping through it.

                Null Coalescing Operator (??):
                Checks if keys like 'ratdescription' or 'ratcharge' exist, defaulting to null or 0 if they don’t.
                Check Individual Elements:

                Ensure each item in the loop ($accessorial) is also an array to prevent accessing offsets on strings.
                */
                // Extract charges for specific accessorial description
                if (isset($record['rataccessorialInfo']['rataccessorial']) && is_array($record['rataccessorialInfo']['rataccessorial'])) {
                    foreach ($record['rataccessorialInfo']['rataccessorial'] as $accessorial) {
                        if (is_array($accessorial)) { // Ensure $accessorial is an array
                            $description = $accessorial['ratdescription'] ?? null; // Use null coalescing to avoid undefined index issues
                            if ($description && array_key_exists($description, $accessorial_charges)) {
                                $accessorial_charges[$description] = $accessorial['ratcharge'] ?? 0;
                            }
                        }
                    }
                }
            }

            $net_freight_charge = $net_freight_charge['LTL Standard Transit'] ?? 0;
            $residential_delivery_fee = $accessorial_charges['Residential Delivery'] ?? 0;
            $lift_gate_delivery_fee = $accessorial_charges['Lift-Gate Service (Delivery)'] ?? 0;
            $appointment_delivery_fee = $accessorial_charges['RESIDENTIAL APPT/SIGNATURE DELV'] ?? 0;
            $notify_before_delivery_fee = $accessorial_charges['Notify Request'] ?? 0;

              // Prepare quote details
            $ws_quotes[$location] = [
                'net_freight_charge' => round($net_freight_charge, 2),
                'residential_delivery' => round($residential_delivery_fee, 2),
                'liftgate_delivery' => round($lift_gate_delivery_fee, 2),
                'appointment_delivery' => round($appointment_delivery_fee, 2),
                'notify_before_delivery' => round($notify_before_delivery_fee, 2)
            ];

            // Increment location for next iteration
            $location = $location + 1;
         }

         // print_r($ws_quotes);
         // exit;
    }

    public function testODFLFreight($ws_rates) {

        global $ws_quotes;

        $location = 0;
        // Initialize variables to store the results
        $net_freight_charge = [];

        foreach ($ws_rates as $origin => $quotes) {
           $data = $quotes['q'] ?? [];
            $accessorial_charges = [
                    'Residential Delivery' => 0,
                    'Lift-Gate Service (Delivery)' => 0,
                    'RESIDENTIAL APPT/SIGNATURE DELV' => 0,
                    'Notify Request' => 0,
            ];
            // Loop through each record
            foreach ($data as $record) {
                // Check and store the total price for specific rattext values
                $service = $record['ratserviceLevel']['rattext'];
                if (strpos($service, 'LTL Standard Transit') !== false || strpos($service, 'Guaranteed LTL Standard Transit') !== false) {
                    $net_freight_charge[$service] = $record['ratpricing']['rattotalPrice'];
                }

                /*
                Explanation of Changes:

                Validate Data Type:
                is_array() ensures that rataccessorial is an array before looping through it.

                Null Coalescing Operator (??):
                Checks if keys like 'ratdescription' or 'ratcharge' exist, defaulting to null or 0 if they don’t.
                Check Individual Elements:

                Ensure each item in the loop ($accessorial) is also an array to prevent accessing offsets on strings.
                */
                // Extract charges for specific accessorial description
                if (isset($record['rataccessorialInfo']['rataccessorial']) && is_array($record['rataccessorialInfo']['rataccessorial'])) {
                    foreach ($record['rataccessorialInfo']['rataccessorial'] as $accessorial) {
                        if (is_array($accessorial)) { // Ensure $accessorial is an array
                            $description = $accessorial['ratdescription'] ?? null; // Use null coalescing to avoid undefined index issues
                            if ($description && array_key_exists($description, $accessorial_charges)) {
                                $accessorial_charges[$description] = $accessorial['ratcharge'] ?? 0;
                            }
                        }
                    }
                }
            }

            $net_freight_charge = $net_freight_charge['LTL Standard Transit'] ?? 0;
            $residential_delivery_fee = $accessorial_charges['Residential Delivery'] ?? 0;
            $lift_gate_delivery_fee = $accessorial_charges['Lift-Gate Service (Delivery)'] ?? 0;
            $appointment_delivery_fee = $accessorial_charges['RESIDENTIAL APPT/SIGNATURE DELV'] ?? 0;
            $notify_before_delivery_fee = $accessorial_charges['Notify Request'] ?? 0;

              // Prepare quote details
            $ws_quotes[$location] = [
                'net_freight_charge' => round($net_freight_charge, 2),
                'residential_delivery' => round($residential_delivery_fee, 2),
                'liftgate_delivery' => round($lift_gate_delivery_fee, 2),
                'appointment_delivery' => round($appointment_delivery_fee, 2),
                'notify_before_delivery' => round($notify_before_delivery_fee, 2)
            ];

            // Increment location for next iteration
            $location = $location + 1;
         }

         // print_r($ws_quotes);
         // exit;
    }

    public function testXPOFreight($ws_rates) {

        global $ws_quotes;
        $location = 0;

        foreach ($shipments as $origin => $quotes) {

            $data[$location] = $quotes['q'];

            // Loop through each record in the data
            foreach ($data as $item) {

                // Get totalNetCharge, residentialFee, and notifyDeliveryFee
                $netFreightCharge = $item['totalNetCharge'] ?? 0;
                $residentialDeliveryAmount = $item['surcharges']['residentialFee'] ?? 0;
                $liftgateDeliveryAmount = $item['surcharges']['liftgateFee'] ?? 0;
                $notificationDeliveryAmount = $item['surcharges']['notifyDeliveryFee'] ?? 0;
            }

            $ws_quotes[$location] = [
                'net_freight_charge' => $netFreightCharge,
                'residential_delivery' => $residentialDeliveryAmount,
                'liftgate_delivery' => $liftgateDeliveryAmount,
                'notify_before_delivery' => $notificationDeliveryAmount
            ];

            $location = $location + 1;

        }
    }

    public function testSAIAFreight($ws_rates) {
        
        global $ws_quotes;
        $location = 0;

        foreach ($shipments as $origin => $quotes) {

           $data = $quotes['q'] ?? [];
            
           // Extract values
           $net_freight_charge = $data['totalNetCharge'] ?? 0;
           $surcharges = $data['surcharges'] ?? 0;

           $residential_fee = $surcharges['residentialFee'] ?? 0;
           $liftgate_fee = $surcharges['liftgateFee'] ?? 0;
           $notify_before_delivery_fee = $surcharges['notifyBeforeDeliveryFee'] ?? 0;
           $inside_delivery_fee = $surcharges['insideDeliveryFee'] ?? 0;
           $limited_access_fee = $surcharges['limitedAccessDeliveryFee'] ?? 0;

           // Prepare quote details
            $ws_quotes[$location] = [
                'net_freight_charge' => round($net_freight_charge, 2),
                'residential_delivery' => round($residential_fee, 2),
                'liftgate_delivery' => round($liftgate_fee, 2),
                'notify_before_delivery' => round($notify_before_delivery_fee, 2),
                'inside_delivery' => round($inside_delivery_fee, 2),
                'limited_access' => round($limited_access_fee, 2),
            ];

            // Increment location for next iteration
            $location = $location + 1;

        }
    }

    public function testRnLFreight($ws_rates) {

        global $ws_quotes;
        $location = 0;

        foreach ($ws_rates as $origin => $quotes) {
            $data = $quotes['q'];
           
            // Initialize result arrays
            $netCharges = [];
            $amounts = [];

            // Extract NetCharge from ServiceLevels for specific names
            $serviceLevelNames = [
                'Standard Service',
                'Guaranteed Service',
                'Guaranteed AM Service',
                'Guaranteed HW Service',
            ];

            /*
            Explanation of Changes:
            Removing the $ Symbol:

            Used trim($value, '$') to remove the $ prefix from the strings.
            Removing Commas:

            Used str_replace(',', '', $value) to remove commas in the values (e.g., $1,332.58 becomes 1332.58).
            Converting to Numbers:

            Used floatval() to convert the cleaned-up strings into numeric values.
            Rounding to Two Decimals:

            Applied round($value, 2) to ensure the result has up to 2 decimal places.
            */
            if (!empty($data['ServiceLevels'])) {
                foreach ($data['ServiceLevels'] as $serviceLevel) {
                    if (in_array($serviceLevel['Name'], $serviceLevelNames)) {
                        $netCharges[$serviceLevel['Name']] = round(floatval(str_replace(',', '', trim($serviceLevel['NetCharge'], '$'))), 2);
                    }
                }
            }

            // Extract Amount from Charges for specific titles
            $chargeTitles = [
                'Lift Gate Fee',
                'Residential/Limited Access Delivery',
                'Notification Fee',
                'Inside Delivery Fee',
            ];

            if (!empty($data['Charges'])) {
                foreach ($data['Charges'] as $charge) {
                    if (in_array($charge['Title'], $chargeTitles)) {
                        $amounts[$charge['Title']] = round(floatval(str_replace(',', '', trim($charge['Amount'], '$'))), 2);;
                    }
                }
            }

            // Prepare quote details
            $ws_quotes[$location] = [
                'net_freight_charge' => $netCharges,
                'residential_delivery' => $amounts['Residential/Limited Access Delivery'] ?? 0,
                'liftgate_delivery' => $amounts['Lift Gate Fee'] ?? 0,
                'notify_before_delivery' => $amounts['Notification Fee'] ?? 0,
                'inside_delivery' => $amounts['Inside Delivery Fee'] ?? 0,
            ];

            // Increment location for next iteration
            $location = $location + 1;
        }
    }
}