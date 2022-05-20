<?php

namespace App\CustomClasses\Unishippers\small;

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
            // -------------Carrier Credentials------------- //
            'username' => $data->username,
            'password' => $data->password,
            'unishipperscustomernumber' => $data->unishippers_customer_number,
            'upsaccountnumber' => $data->ups_account_number,
            'requestkey' => $data->request_key,
            'carrierName' => 'unisheppers',
            'carrier_mode' => 'test',
            'unique_key' => '87676ba67fc1bd58a97e77f05063c177',
            'platform' => 'bigcommerce',
            'serverName' => $storeName, // $_SERVER['SERVER_NAME'];
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
        } elseif (isset($output['severity']) && $output['severity'] === 'SUCCESS') {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
                'data' => [],
            ];
        }

        return $response;
    }
}
