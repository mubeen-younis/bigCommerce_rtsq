<?php

namespace App\CustomClasses\RL\ltl;

use App\Constants\Constant;
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
            'platform' => 'bigcommerce',
            'carrier_mode' => 'test',
            'dont_auth' => '1',
            'carrierName' => 'rnl',
            'serverName' => $storeName ?? '',
            'APIVersion' =>  '2.0',
            'UserName' => $data['username'] ?? '',
            'Password' => $data['password'] ?? '',
            'APIKey' => $data['api_key'] ?? '',
        ];

        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');
        $output = json_decode($output['response'], true);

        if (isset($output['error'])) {
            $response = [
                'error' => true,
                'message' => 'Error! The credentials entered did not result in a successful test. Confirm your credentials and try again. ',
            ];
        } else {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
                'data' => [],
            ];
        }

        return $response;
    }

}
