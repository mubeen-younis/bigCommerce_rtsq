<?php

namespace App\CustomClasses\Fedex\ltl;

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
            'platform' => 'bigcommerce',
            'testConnectionCarrier' => 'fedex',
            'carrier_mode' => 'test',
            'carrierName' => 'fedex',
            'sever_name' => $storeName ?? '',
            'accountType' => 'shipper',
            'AccountNumber' => $data->account_number ?? '',
            'MeterNumber' => $data->meter_number ?? '',
            'password' => $data->password ?? '',
            'key' => $data->api_access_key ?? '',
            'shippingChargesAccount' => $data->shipping_account_number ?? '',
            'billingLineAddress' => $data->billing_address ?? '',
            'billingCountry' => $data->billing_country ?? '',
            'billingCity' => $data->billing_city ?? '',
            'billingState' => $data->billing_state ?? '',
            'billingZip' => $data->billing_zip ?? '',
            'physicalCountry' => $data->physical_country ?? '',
            'physicalAddress' => $data->physical_address ?? '',
            'physicalCity' => $data->physical_city ?? '',
            'physicalStateOrProvinceCode' => $data->physical_state ?? '',
            'physicalPostalCode' => $data->physical_zip ?? '',
            'third_party_account' => $data->third_party_account ?? '',
        );
        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');

        if (isset($output['severity']) && $output['severity'] === 'ERROR') {
            $response = [
                'error' => true,
                'message' => $output['message'],
            ];
        }
        $output = json_decode($output['response'], true);
        if (isset($output['severity']) && $output['severity'] === 'SUCCESS') {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
                'data' => [],
            ];
        } else{
            $response = [
                'error' => true,
                'message' => 'Invalid authentication info',
            ];
        }

        return $response;
    }
}
