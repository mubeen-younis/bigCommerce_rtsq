<?php

namespace App\CustomClasses\OdflLTL;

use App\CustomClasses\CarriersConnectionSettings;
use App\CustomClasses\CurlRequest;
use Illuminate\Support\Facades\DB;

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
        $params  = [
            'license_key' => '',
            'carrierName' => 'odfl4me',
            'carrier_mode' => 'test',
            'dont_auth' => '1',
            'version' => '1.0',
            'serverName' => $storeName ?? '',
            'odflUserName' => $data['username'] ?? '',
            'odflPassword' => $data['password'] ?? '',
            'odflCustomerAccount' => $data['customer_number'] ?? '',
            'senderZip' => $data['billing_postal_Code'] ?? '',
        ];

        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');
        $output = json_decode($output['response'], true);

        if(isset($output['soapenvBody']['ns2getLTLRateEstimateResponse']['return']['errorMessages']))
         { 
            $response = [
                'error' => true,
                'message' =>"Invalid credentials.",
            ];
        }
       
        if(isset($output['soapenvBody']['ns2getLTLRateEstimateResponse']['return']['destinationCities']))
        { 
           $response = [
               'error' => false,
               'message' => "Test connection successful.",
           ];
       }

       return $response;
    }
}
