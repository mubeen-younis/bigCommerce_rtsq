<?php

namespace App\CustomClasses\UnishipperLtl;
use App\Endpoints\Endpoints;
use App\CustomClasses\CurlRequest;
use App\Models\Connection;

class UnishipperLtlConnectionSettings
{
    private $testConnectionUrl;

    public function __construct()
    {
        $this->testConnectionUrl = Endpoints::wweLtlTestEndpoint();

        $this->curlRequest = new CurlRequest();
    }

    public function testLtlConnection($data, $storeName)
    {
        
        $response = [
            'error' => true,
            'message' => 'Something went wrong!',
        ];
        $url = $this->testConnectionUrl;  //Constant::TEST_CONN_URL;
        $params = [
            'platform' => 'bigcommerce',
            'plugin_domain_name' => $storeName ?? '',
            'dont_auth' => 1,
            'carrier_mode' => 'test',
            'carrierName' => 'Unishipper Ltl',
            // New Api Test Connection Params
            'clientId' => $data->clientId,
            'clientSecret' => $data->clientSecret,
            'speed_freight_username' => $data->username ?? '',
            'speed_freight_password' => $data->password ?? '',
            'ApiVersion' => '2.0',
            'requestFromUnishippersLTL' => 1
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
                'data' => Connection::where('installed_carrier_id', $data->carrierId)->first(),
                'type' => 'ltl'
            ];
        }
        return $response;

    }
}
