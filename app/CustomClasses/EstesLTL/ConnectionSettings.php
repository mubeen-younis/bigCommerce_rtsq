<?php

namespace App\CustomClasses\EstesLTL;

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
            'carrierName' => 'estes',
            'carrier_mode' => 'test',
            'dont_auth' => '1',
            'version' => '2.0',
            'serverName' => $storeName ?? '',
            'UserName' => $data['username'] ?? '',
            'Password' => $data['password'] ?? '',
            'CUSTNMBR' => $data['customer_number'] ?? '',
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
        if ($output['severity'] === 'ERROR') {
            $response = [
                'error' => true,
                'message' => $output['Message'],
            ];
        }if ($output['severity'] === 'SUCCESS') {
            $response = [
                    'error' => false,
                    'message' => 'Test connection successful.',
                    'data' => [],
                    ];
        }        return $response;

    }
}
