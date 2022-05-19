<?php

namespace App\CustomClasses\SaiaLTL;

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
            'dont_auth' => '1',
            // -------------Carrier Credentials------------- //
            'userID' => $data->userID,
            'password' => $data->password,
            'accountNumber' => $data->account_number,
            'application' => $data->third_party_account_number ?? 'ThirdParty',
            // Inbound, Outbound, ThirdParty
            'originPostalCode' => $data->original_postal_code,

            'licence_key' => 'TDVB9ONC-M7QJRPRQ-5EDIH32D-DE73Y57I',
            'serverName' => $storeName,

            'carrierName' => 'saia',
            'carrier_mode' => 'test',
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
