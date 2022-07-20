<?php

namespace App\CustomClasses\SouthEasternLtl;

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
            'sever_name' => $storeName,
            'licence_key' => '',
            'dont_auth' => '1',

            'carrierName' => 'southeastern',
            'carrier_mode' => 'test',
            'apiVersion' => '1.0',
            'platform' => 'bigcommerce',

            // -------------Carrier Credentials------------- //
            'username' => $data->username,
            'password' => $data->password,
            'customerName' => $data->customer_name,
            'customerStreet' => $data->customer_street_address,
            'customerCity' => $data->customer_city,
            'customerState' => $data->customer_state,
            'customerZip' => $data->customer_zip_code,
        );

        if (isset($data->third_party_account_number) && !empty($data->third_party_account_number && $data->access_level === 'third_party_account_number')) {
            $params['Option'] = 'T';
            $params['customerAccount'] = $data->third_party_account_number;
        } else {
            $params['Option'] = 'S';
            $params['customerAccount'] = $data->customer_account_number;
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
