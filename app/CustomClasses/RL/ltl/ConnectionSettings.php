<?php

namespace App\CustomClasses\RL\ltl;

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
            'platform' => 'bigcommerce',
            'carrier_mode' => 'test',
            'dont_auth' => '1',
            'carrierName' => 'rnl',
            'serverName' => $storeName ?? '',

            'UserName' => $data['username'] ?? '',
            'Password' => $data['password'] ?? '',
            'APIKey' => $data['authentication_key'] ?? '',
        ];


        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');
        $output = json_decode($output['response'], true);

        if (isset($output['error'])) {
            $response = [
                'error' => true,
                'message' => 'Invalid authentication info',
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
