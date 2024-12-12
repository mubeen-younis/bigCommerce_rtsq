<?php

namespace App\CustomClasses\Fedex\ltl;

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

        if(isset($data->account_type) && $data->account_type == 'shipper'){
            if(empty($data->shipping_account_number)){
                return [
                    'error' => true,
                    'message' => 'Shipper Account Number Required',
                ];
            }

        }else {
            if(empty($data->third_party_account)){
                return [
                    'error' => true,
                    'message' => 'Third Party Account No. Required',
                ];
            }
        }

        $url = $this->testConnectionUrl;
        $params = array(
            'dont_auth' => '1',
            'platform' => 'bigcommerce',
            'testConnectionCarrier' => 'fedex',
            'carrier_mode' => 'test',
            'carrierName' => 'fedex',
            'sever_name' => $storeName ?? '',
            'accountType' => $data->account_type,
            'AccountNumber' => $data->account_number ?? '',
            'MeterNumber' => $data->meter_number ?? '',
            'password' => $data->password ?? '',
            'key' => $data->api_access_key ?? '',
            // New Api Test Connection Params
            'clientId' => $data->clientId,
            'clientSecret' => $data->clientSecret,
            'requestForNewAPI' => '1',
            // ---------------- //
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

        if (isset($data->api_type) && $data->api_type === 'new_api'){
            unset($params['AccountNumber'], $params['MeterNumber'], $params['password'], $params['key']);

        } else {
            unset($params['clientId'], $params['clientSecret'], $params['requestForNewAPI']);

        }

        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');

        if (isset($output['severity']) && $output['severity'] === 'ERROR') {
            $response = [
                'error' => true,
                'message' => $output['message'],
            ];
        }
        $output = json_decode($output['response'], true);

        if (isset($output['severity']) && $output['severity'] === 'ERROR') {
            $response = [
                'error' => true,
                'message' => $output['Message'] ?? 'Invalid authentication',
                'data' => [],
            ];

        } elseif (isset($output['severity']) && $output['severity'] === 'SUCCESS') {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
                'data' => [],
            ];
        } else {
            $response = [
                'error' => true,
                'message' => 'Invalid authentication info',
            ];
        }

        return $response;
    }
}
