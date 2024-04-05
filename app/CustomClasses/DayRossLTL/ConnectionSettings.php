<?php

namespace App\CustomClasses\DayRossLTL;

use App\CustomClasses\CarriersConnectionSettings;
use App\CustomClasses\CurlRequest;

class ConnectionSettings extends CarriersConnectionSettings
{
    public function __construct()
    {
        parent::__construct();
        $this->curlRequest = new CurlRequest();
    }

    public function testConnection($data, $storeName)
    {
        $response = [
            'error' => true,
            'message' => 'Something went wrong!',
        ];

        $url = $this->testConnectionUrl;
        $params = [
            // -------------Carrier type and Status------------- //
            'licence_key' => '',
            'sever_name' => $storeName,
            'carrierName' => 'dayross',
            'carrier_mode' => 'test', // use test / pro
            'dont_auth' => '1',
            'platform' => 'bigcommerce',
            // -------------Carrier Credentials------------- //
            'emailAddress' => $data['email'],
            'password' => $data['password'],
            'billToAccountNumber' => $data['billing_account_number'],
            'senderCountryCode' => $data['sender_country_code'], // CA or US
        ];

        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');

        $output = json_decode($output['response'], true);
        if (isset($output['q']['soapBody']['soapFault'])) {
            $response = [
                'error' => true,
                'message' => 'Invalid credentials',
            ];
        } elseif (isset($output['q']['soapBody']['CreateUSQuoteResponse']) || isset($output['q']['soapBody']['GetRate2Response'])) {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
            ];
        }

        return $response;
    }
}
