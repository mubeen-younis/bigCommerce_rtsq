<?php

namespace App\CustomClasses\OdflLTL;

use App\Constants\Constant;
use App\CustomClasses\CurlRequest;
use Illuminate\Support\Facades\DB;

class ConnectionSettings
{
    private $testConnectionUrl = Constant::BASEURL.'/ws/index.php';
    public function __construct()
    {
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
            'license_key' => 'QHZGO4SF-TW4R8NUC-A41WVCDR-5WXFESTS',
            'server_name' => 'wooplugins.eniture-dev.com',
            'carrierName' => 'odfl4me',
            'carrier_mode' => 'test',
            'dont_auth' => '1',
            'version' => '2.0',
            'serverName' => $storeName ?? '',
            'odflUserName' => $data['username'] ?? '',
            'odflPassword' => $data['password'] ?? '',
            'odflCustomerAccount' => $data['customer_number'] ?? '',
            'senderZip' => $data['billing_postal_Code'] ?? '',
            'requestType' => isset($data['access_level']) && $data['access_level'] == 'pro' ? 'thirdParty':'shipper'
        ];
        $isPro = false;
        if(isset($data['access_level']) && $data['access_level'] == 'pro' && isset($data['api_key']) && $data['api_key'] != '' ){
            $Test = [
                'basicAccessToken' => $data['api_key'] ?? '',
                'xpoApiVersion' => '1.0',
            ];
            $params = array_merge($params, $Test);
            $isPro = true;
        }

        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');
        $output = json_decode($output['response'], true);
        if(isset($output['soapenvBody']['ns2getLTLRateEstimateResponse']['return']['errorMessages']))
         { 
            $response = [
                'error' => true,
                'message' =>"Invalid authentication info",
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
