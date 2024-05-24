<?php

namespace App\CustomClasses\UpsLandCostApi;

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

        $params = array(
            'dont_auth' => '1',
            'licence_key' => '',
            // -------------Carrier Credentials------------- //
            'apiVersion' => '1.0',
            'carrierName' => 'UPSLandedCost',
            'carrier_mode' => 'test',
            'platform' => 'bigcommerce',
            'sever_name' => $storeName,

            // -------------API Credentials------------- //
            'clientId' => $data->clientId,
            'clientSecret' => $data->clientSecret,
            'upsAccountNumber' => $data->account_number, // optional
        );

        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');
        
        $outputResp = json_decode($output['response'], true);
        if (isset($output['status']) && $output['status'] == false) {
            $response = [
                'error' => true,
                'message' => $outputResp['response'] ?? '',
            ];
        }

        if (isset($outputResp['severity'])  && $outputResp['severity'] == 'ERROR') {
            $response = [
                'error' => true,
                'message' =>  "Invalid credentials",
            ];
        }else{    
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
                'data' => [],
            ];
        }

        return $response;
    }
}
