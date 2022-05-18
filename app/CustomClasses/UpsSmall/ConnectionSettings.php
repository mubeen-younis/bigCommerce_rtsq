<?php

namespace App\CustomClasses\UpsSmall;

use App\CustomClasses\CurlRequest;
use App\Endpoints\Endpoints;

class ConnectionSettings
{
    private $testConnectionUrl;

    public function __construct()
    {
        $this->testConnectionUrl = Endpoints::upsSmallTestEndpoint();
        $this->curlRequest = new CurlRequest();
    }

    public function testUpsLtlConnection($data, $storeName)
    {
        $response = [
            'error' => true,
            'message' => 'Something went wrong!',
        ];
        $url = $this->testConnectionUrl;
        /*$params = array(
            'carrierName' => 'ups',
            'carrier_mode' => 'test',
            'accessLevel' => $data->access_level, //test or pro
            'AccountNumber' => $data->account_number ?? '',
            'UserName' => $data->username ?? '',
            'Password' => $data->password ?? '',
            'APIKey' => $data->ups_api_access_key ?? '',
            'licence_key' => $data->license_key ?? '',
            'server_name' => $storeName ?? '',
            'dont_auth' => 1
        );*/
        $params = array(
            'dont_auth' => '1',
            'platform' => 'bigcommerce',
            'ups_username' => $data->username ?? '',
            'ups_password' => $data->password ?? '',
            'ups_license_key' => $data->ups_api_access_key ?? '',
            'ups_account_number' => $data->account_number ?? '',
            'ups_domain_name' => $storeName ?? '',
            'plugin_licence_key' => $data->license_key ?? '',
        );
        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');


        if (isset($output['status']) && $output['status'] == false) {
            $response = [
                'error' => true,
                'message' => $output['response'],
            ];
        }
        $output = json_decode($output['response'], true);
        if (isset($output['error']) && $output['error'] == 1) {
            $response = [
                'error' => true,
                'message' => 'Invalid authentication info',
            ];
        } elseif (isset($output['success'])) {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
                'data' => [],
            ];
        }

        return $response;
    }
}
