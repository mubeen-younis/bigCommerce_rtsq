<?php


namespace App\CustomClasses\Priority1Ltl;

use App\CustomClasses\CurlRequest;
use App\Endpoints\Endpoints;
use App\Models\Connection;
use Illuminate\Support\Facades\Log;
use App\CustomClasses\CarriersConnectionSettings;
use App\Constants\Constant;

class ConnectionSettings extends CarriersConnectionSettings
{
    public function __construct()
    {
        parent::__construct();
        $this->curlRequest = new CurlRequest();
    }

    public function testLtlConnection($data, $storeName)
    {
        $response = [
            'error' => true,
            'message' => 'Something went wrong!',
        ];

        $url = $this->testConnectionUrl;  

        $params = [
            'platform' => 'bigcommerce',
            'carrierName' => 'priority1',
            'serverName' => $storeName ?? '',
            'dont_auth' => 1,
            'carrier_mode' => 'test',
            'apiKey' => isset($data['api_key']) ? $data['api_key'] : '',
            'requestKey'   => '',
        ];

        $queryString = http_build_query($params);

        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');

        if (isset($output['status']) && $output['status'] == false) {
            $response = [
                'error' => true,
                'message' => $output['response'],
            ];
        }

        $output = json_decode($output['response'], true);
        if (isset($output['error']) && isset($output['error_desc'])) {
            $response = [
                'error' => true,
                'message' => $output['error_desc'],
            ];
        } elseif (isset($output['severity']) && $output['severity'] === 'ERROR') {
            $response = [
                'error' => true,
                'message' => $output['Message'],
            ];
        } elseif (isset($output['success']) || (isset($output['severity']) && $output['severity'] === 'SUCCESS')) {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
                'data' => [],
            ];
        }
        return $response;

    }
}
