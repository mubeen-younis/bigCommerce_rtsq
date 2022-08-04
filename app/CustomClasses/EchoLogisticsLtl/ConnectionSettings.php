<?php

namespace App\CustomClasses\EchoLogisticsLtl;

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
            'sever_name' => $storeName ?? '',
            'carrierName' => 'echoLogistics',
            'carrier_mode' => 'test', // use test / pro
            'apiVersion' => '1.0',
            'platform' => 'bigcommerce',

            'dont_auth' => '1',
            // -------------Carrier Credentials------------- //
            'apiKey' => $data['api_key'],
            'accountNumber' => $data['account_number'],
        ];

        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');
        $output = json_decode($output['response'], true);

        if (isset($output['severity']) && $output['severity'] == 'ERROR') {
            $response['message'] = 'Invalid credentials.';
        } else {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
            ];
        }

        return $response;
    }
}
