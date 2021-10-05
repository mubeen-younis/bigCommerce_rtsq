<?php

namespace App\CustomClasses\GTZ\ltl;

use App\CustomClasses\CurlRequest;

class ConnectionSettings
{
    private $testConnectionUrl = 'https://eniture-qa.com/ws/index.php';
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
        $params = [];
        if($data->api_type === 'cerasis'){
            $data = $data->cerasis;
            $params['carrierName'] = 'cerasis';
            $params['shipperID'] = $data->customer_id ?? '';
        }else{
            $data = $data->gtz;
            $params['carrierName'] = 'globalTranz';
            $params['customerId'] = $data->customer_id ?? '';
        }
        $params  = [
            'platform' => 'bigcommerce',
            'carrier_mode' => 'test',
            'accessLevel' => 'pro', // pro , test
            'version' => '2.0',
            'username' => $data->user_name ?? '',
            'password' => $data->password ?? '',
            'accessKey' => $data->access_key ?? '',
            'dont_auth' => '1',
            'serverName' => $storeName ?? '',
        ];
        if($data->api_type === 'cerasis'){
            $params['shipperID'] = $data->customer_id ?? '';
            $params['carrierName'] = 'cerasis';
        }


        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');
        $output = json_decode($output['response'], true);

        if (isset($output['severity']) && $output['severity'] === 'SUCCESS') {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
                'data' => [],
            ];
        } else{
            $response = [
                'error' => true,
                'message' => 'Invalid authentication info',
            ];
        }

        return $response;
    }
}
