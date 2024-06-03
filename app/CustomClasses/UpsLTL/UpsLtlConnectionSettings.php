<?php

namespace App\CustomClasses\UpsLTL;

use App\CustomClasses\CarriersConnectionSettings;
use App\CustomClasses\CurlRequest;

class UpsLtlConnectionSettings extends CarriersConnectionSettings
{
    public function __construct()
    {
        parent::__construct();
        $this->curlRequest = new CurlRequest();
    }

    public function testUpsLtlConnection($data, $storeName)
    {
        $response = [
            'error' => true,
            'message' => 'Something went wrong!',
        ];
        $url = $this->testConnectionUrl;
        $params = array(
            'carrierName' => 'ups',
            'carrier_mode' => 'test',
            'platform' => 'bigcommerce',
            'dont_auth' => 1,
            'dimWeightBaseAccount' => $data->rates_my_freight_based ?? 0,
            'UserName' => $data->username ?? '',
            'Password' => $data->password ?? '',
        );

        if(isset($data->api_type) && $data->api_type === 'new_api'){
            $params['clientId'] = $data->clientId ?? '';
            $params['clientSecret'] = $data->clientSecret ?? '';
            $params['requestForTForceQuotes'] = '1';
            $params['licenseKey'] = $data->license_key ?? '';
            $params['serverName'] = $storeName ?? '';

        } else{
            $params['accessLevel'] = $data->access_level; //test or pro
            $params['AccountNumber'] = $data->account_number ?? '';
            $params['APIKey'] = $data->ups_api_access_key ?? '';
            $params['licence_key'] = $data->license_key ?? '';
            $params['server_name'] = $storeName ?? '';
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

        if (isset($output['q']['TotalShipmentCharge']['MonetaryValue']) || (isset($output['severity']) && $output['severity'] === 'SUCCESS')) {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
                'data' => [],
            ];
        } else if(isset($output['error'])) {
            $response = [
                'error' => true,
                'message' => $output['error']['Description'] ?? $output['error'],
            ];
        } else if(isset($output['severity']) && $output['severity'] === 'ERROR'){
            $response = [
                'error' => true,
                'message' => $output['message'] ?? $output['ApiResponse']['error'],
            ];
        }

        return $response;
    }
}
