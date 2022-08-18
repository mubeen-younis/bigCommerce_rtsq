<?php

namespace App\CustomClasses\UpsLTL;

use App\CustomClasses\CarriersConnectionSettings;
use App\CustomClasses\CurlRequest;

class UpsLtlConnectionSettings extends CarriersConnectionSettings
{
    public function __construct()
    {
        parent::__construct();
        $this->curlRequest = new CurlRequest();
    }

    public function testUpsLtlConnection($data, $storeName)
    {
        $response = [
            'error' => true,
            'message' => 'Something went wrong!',
        ];
        $url = $this->testConnectionUrl;
        $params = array(
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

        if (isset($output['q']['TotalShipmentCharge']['MonetaryValue'])) {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
                'data' => [],
            ];
        } else if(isset($output['error'])) {
            $response = [
                'error' => true,
                'message' => $output['error']['Description'] ?? $output['error'],
            ];
        }

        return $response;
    }
}
