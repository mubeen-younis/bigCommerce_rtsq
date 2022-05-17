<?php

namespace App\CustomClasses\YrcLTL;

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

        $params = array(
            'dont_auth' => '1',
            'licence_key' => 'TDVB9ONC-M7QJRPRQ-5EDIH32D-DE73Y57I',
            // -------------Carrier Credentials------------- //
            'userId' => $data->username,
            'password' => $data->password,
            'busId' => $data->business_id,
            'dimWeightBaseAccount' => $data->yrc_rates,

            'apiVersion' => '1.0',
            'carrierName' => 'yrc',
            'carrier_mode' => 'test',
            'platform' => 'bigcommerce',
            'RequestOption' => 'Rate',
            'ServiceClass' => 'STD',
            'sever_name' => $storeName,
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

        if (isset($output['severity']) && $output['severity'] == 'ERROR' && isset($output['Message']) || (isset($output['error']) && $output['error'] && $output['error'] == 1)) {
            $response = [
                'error' => true,
                'message' => $output['Message'] ?? $output['error_desc'],
            ];
        } elseif ((isset($output['severity']) && $output['severity'] === 'SUCCESS') || (isset($output['success']) && $output['success'] == 1)) {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
                'data' => [],
            ];
        }

        return $response;
    }
}
