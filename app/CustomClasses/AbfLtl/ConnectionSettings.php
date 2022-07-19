<?php

namespace App\CustomClasses\AbfLtl;

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
            'id' => $data->business_id,
            'apiVersion' => '1.0',
            'carrierName' => 'abf',
            'carrier_mode' => 'test',
            'platform' => 'bigcommerce',
            'RequestOption' => 'Rate',
            'ServiceClass' => 'STD',
            'sever_name' => $storeName,
            
        );

        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');
        $outresp = json_decode($output['response'], true);
        if (isset($output['status']) && $output['status'] == True && isset($outresp['q']['NUMERRORS']) || $outresp['q']['NUMERRORS'] == 1) {
            $response = [
                'error' => true,
                'message' => $output['response'],
            ];
        }

        $output = json_decode($output['response'], true);
        if (isset($output['q']['ERROR'])  && isset($output['q']['ERROR']['ERRORMESSAGE']) || (isset($output['error']))) {
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
