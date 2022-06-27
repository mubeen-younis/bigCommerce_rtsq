<?php

namespace App\CustomClasses\SouthEasternLtl;

use App\CustomClasses\CurlRequest;

class ConnectionSettings
{
    private $testConnectionUrl = 'https://eniture.com/ws/index.php';

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

        $params = array(
            'sever_name' => $storeName,
            'licence_key' => 'TDVB9ONC-M7QJRPRQ-5EDIH32D-DE73Y57I',
            'dont_auth' => '1',

            'carrierName' => 'southeastern',
            'carrier_mode' => 'test',
            'apiVersion' => '1.0',
            'platform' => 'bigcommerce',

            // -------------Carrier Credentials------------- //
            'username' => $data->username,
            'password' => $data->password,
            'customerAccount' => $data->customer_account_number,
            'customerName' => $data->customer_name,
            'customerStreet' => $data->customer_street_address,
            'customerCity' => $data->customer_city,
            'customerState' => $data->customer_state,
            'customerZip' => $data->customer_zip_code,
            'Option' => 'S',
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

        if (isset($output['q']) && isset($output['q']['error']) && !blank($output['q']['error'])) {
            $response = [
                'error' => true,
                'message' => $output['q']['error'],
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
