<?php

namespace App\CustomClasses\Fedex\small;

use App\CustomClasses\CarriersConnectionSettings;
use App\CustomClasses\CurlRequest;
use App\Endpoints\Endpoints;

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
        $url = Endpoints::fedexSmallTestEndpoint();
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
            // New Api Test Connection Params
            'clientId' => $data->clientId,
            'clientSecret' => $data->clientSecret,
            'accountNumber' => $data->new_api_account_number,
            'requestForNewAPI' => '1', 
        );

        if (isset($data->api_type) && $data->api_type === 'new_api'){
            unset($params['fedex_user_id'], $params['fedex_password'], $params['fedex_meter_number'], $params['fedex_account_number']);

        } else {
            unset($params['clientId'], $params['clientSecret'], $params['requestForNewAPI'], $params['accountNumber']);

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
        if (isset($output['error']) && isset($output['Message']) || (isset($output['severity']) && isset($output['severity']) == 'ERROR')) {
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
