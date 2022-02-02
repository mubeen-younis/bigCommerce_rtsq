<?php

namespace App\CustomClasses\Unishippers\small;

use App\CustomClasses\CurlRequest;

class ConnectionSettings
{
    private $testConnectionUrl = 'https://eniture-qa.com/ws/index.php';

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

        // array (
        //     'username' => 'everestproducts',
        //     'password' => 'shipping',
        //     'requestkey' => '3.0',
        //     'upsaccountnumber' => '7F1E28',
        //     'unishipperscustomernumber' => 'U18859353891',
        //     'carrierName' => 'unisheppers',
        //     'carrier_mode' => 'test',
        //     'unique_key' => '87676ba67fc1bd58a97e77f05063c177',
        //     'serverName' => 'wc.eniture-dev.com',
        //     'dont_auth' => 1,
        //     'platform' => 'WordPress',
        //   );
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
        // dd($queryString);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');
        dd($output);

        if (isset($output['status']) && $output['status'] == false) {
            $response = [
                'error' => true,
                'message' => $output['response'],
            ];
        }

        $output = json_decode($output['response'], true);
        if (isset($output['error']) && isset($output['Message'])) {
            $response = [
                'error' => true,
                'message' => $output['Message'],
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
