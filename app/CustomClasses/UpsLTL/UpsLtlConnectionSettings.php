<?php

namespace App\CustomClasses\UpsLtl;

use App\CustomClasses\CurlRequest;

class UpsLtlConnectionSettings
{
    public function __construct()
    {

        $this->curlRequest = new CurlRequest();
    }

    public function testUpsLtlConnection($data)
    {
        $response = [
            'error' => true,
            'message' => 'Something went wrong!',
        ];

        $url = 'https://eniture-qa.com/ws/index.php';
        $params = array(
            'carrierName' => 'ups',
            'carrier_mode' => 'test',
            'accessLevel' => $data->access_level, //test or pro
            'AccountNumber' => '',
            'UserName' => '',
            'Password' => '',
            'APIKey' => '',
            'licence_key' => '',
            'sever_name' => '',
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

        if (isset($output['error']) && isset($output['error_desc'])) {
            $response = [
                'error' => true,
                'message' => $output['error_desc'],
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
