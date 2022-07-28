<?php

namespace App\CustomClasses\UspsSmall;

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
        $params = array(
            'dont_auth' => '1',
            'licence_key' => 'TDVB9ONC-M7QJRPRQ-5EDIH32D-DE73Y57I',
            'serverName' => $storeName,
            'carrierName' => 'usps',
            'carrier_mode' => 'test', // use test / pro
            'apiVersion' => '1.0',
            'platform' => 'bigcommerce',
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
