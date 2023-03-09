<?php


namespace App\CustomClasses\PurolatorSmall;

use App\CustomClasses\CarriersConnectionSettings;
use App\CustomClasses\CurlRequest;
use App\Endpoints\Endpoints;
use App\Models\Connection;

class SmallConnectionSettings extends CarriersConnectionSettings
{


    public function __construct()
    {
        parent::__construct();
        $this->curlRequest = new CurlRequest();
    }

    public function testSmallConnection($data, $storeName)
    {
        $response = [
            'error' => true,
            'message' => 'Something went wrong!',
        ];

        $url = $this->testConnectionUrl;
        $params = [
            'license_key' => '',
            'server_name' => $storeName ?? '',

            'carrierName' => 'purolator',
            'carrier_mode' => 'test',
            'apiVersion' => '1.0',
            'platform' => 'bigcommerce',

            'productionKey' => $data->productionKey ?? '',
            'productionPass' => $data->productionPass ?? '',
            'billingAccount' => $data->billingAccount ?? '',
            'registeredAccount' => $data->registeredAccount ?? '',
            'senderCity' => $data->senderCity ?? '',
            'senderState' => $data->senderState ?? '',
            'senderZip' => $data->senderZip ?? '',
            'senderCountryCode' => 'CA',
            'dont_auth' => 1
        ];
        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');

        if (isset($output['status']) && $output['status'] == false) {
            $response = [
                'error' => true,
                'message' => $output['response'],
            ];
        }
        $output = json_decode($output['response'], true);
        if (isset($output['severity']) && $output['severity'] == 'ERROR') {
            $response = [
                'error' => true,
                'message' => $output['Message'],
            ];
        } elseif (isset($output['severity']) && $output['severity'] == 'SUCCESS') {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
                'data' => [],
            ];
        }
        return $response;

    }
}
