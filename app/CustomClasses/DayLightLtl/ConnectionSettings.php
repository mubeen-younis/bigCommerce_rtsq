<?php

namespace App\CustomClasses\DayLightLtl;

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
            'licence_key' => '',
            'serverName' => $storeName,
            'dont_auth' => '1',
            // -------------Carrier Credentials------------- //
            'userName' => $data->username,
            'password' => $data->password,
            'accountNumber' => $data->account_number,

            'carrierName' => 'daylight',
            'carrier_mode' => 'test',
            'apiVersion' => '1.0',
            'platform' => 'bigcommerce',
        );

        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');

        if (isset($output['status']) && $output['status'] == false) {
            $response['message'] = $output['Message'];
        }

        $output = json_decode($output['response'], true);

        if (isset($output['severity']) && $output['severity'] === 'ERROR' && isset($output['message'])) {
            $response['message'] = $output['message'];
        } elseif (isset($output['severity']) && $output['severity'] === 'SUCCESS') {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
            ];
        }

        return $response;
    }
}
