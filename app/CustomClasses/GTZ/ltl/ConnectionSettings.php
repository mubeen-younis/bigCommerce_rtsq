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

        $params  =array (
            '//requestKey' => '9e75216a3b07dasdasd5d2ceqweqw22a',
            'platform' => 'bigcommerce',
            'carrier_mode' => 'test',
            'accessLevel' => 'pro', // pro , test
            'version' => '2.0',
            'carrierName' => 'globalTranz',
            'customerId' => $data->customer_id ?? '',
            'username' => $data->user_name ?? '',
            'password' => $data->password ?? '',
            'accessKey' => $data->access_key ?? '',
            //'unique_key' => '299c408137ae554d3f2qwqeq47',
            'dont_auth' => '1',
            'serverName' => $storeName ?? '',
        );
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
