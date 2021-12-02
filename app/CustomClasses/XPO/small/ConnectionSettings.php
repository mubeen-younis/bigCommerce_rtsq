<?php

namespace App\CustomClasses\XPO\small;

use App\CustomClasses\CurlRequest;
use Illuminate\Support\Facades\DB;

class ConnectionSettings
{
    private $testConnectionUrl = 'https://eniture-qa.com/ws/s/fedex/fedex_shipment_rates_test.php';
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
        $params = Array(
            'dont_auth' => '1',
            // -------------Carrier Credentials------------- //
            'fedex_user_id' => $data->api_access_key ?? '',
            'fedex_password' => $data->password ?? '',
            'fedex_account_number' => $data->account_number ?? '',
            'fedex_meter_number' => $data->meter_number ?? '',
            'licence_key' =>  '',
            'platform' => 'bigcommerce',
            'server_name' => $storeName, // $_SERVER['SERVER_NAME'];
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
