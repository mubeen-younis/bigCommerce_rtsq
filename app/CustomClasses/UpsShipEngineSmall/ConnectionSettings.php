<?php

namespace App\CustomClasses\UpsShipEngineSmall;

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
        $params = [];

        if(isset($data['shipengine_carrier_id']) && !empty($data['shipengine_carrier_id']) &&isset($data['shipengine_api_key']) && !empty($data['shipengine_api_key'])){
            $params = array(
                'dont_auth' => '1',
                'server_name' => $storeName,
                'carrierName' => 'shipEngine',
                'carrier_mode' => 'test',
                'platform' => 'bigcommerce',
                'shipEngineCarrierIds' => [$data['shipengine_carrier_id']],
                'apiKey' => $data['shipengine_api_key'],
                'myCarriersInShipengine' => 1,
            );
        }

        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');

        if (isset($output['status']) && $output['status'] == false) {
            $response = [
                'error' => true,
                'message' => $output['response'],
            ];
        }

        $output = json_decode($output['response'], true);
        if (isset($output['severity']) && $output['severity'] === 'ERROR' && isset($output['Message'])) {
            $response = [
                'error' => true,
                'message' => $output['Message'],
            ];
        } elseif (isset($output['severity']) && ($output['severity'] === 'SUCCESS' || $output['severity'] === 'Success')) {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
                'data' => [],
            ];
        }

        return $response;
    }
}
