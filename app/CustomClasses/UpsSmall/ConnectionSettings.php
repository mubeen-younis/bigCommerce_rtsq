<?php

namespace App\CustomClasses\UpsSmall;

use App\CustomClasses\CurlRequest;
use App\Endpoints\Endpoints;

class ConnectionSettings
{
    private $testConnectionUrl;

    public function __construct()
    {
        $this->testConnectionUrl = Endpoints::upsSmallTestEndpoint();
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
            'dont_auth' => '1',
            'platform' => 'bigcommerce',
            'ups_domain_name' => $storeName ?? '',
            'plugin_licence_key' => $data->license_key ?? '',
        );
        if(isset($data->api_type) && $data->api_type === 'new_api'){
            $params['ups_account_number'] = $data->new_api_account_number ?? '';
            $params['clientId'] = $data->clientId ?? '';
            $params['clientSecret'] = $data->clientSecret ?? '';
            $params['ApiVersion'] = '2.0';

        } else{
            $params['ups_account_number'] = $data->account_number ?? '';
            $params['ups_username'] = $data->username ?? '';
            $params['ups_password'] = $data->password ?? '';
            $params['ups_license_key'] = $data->ups_api_access_key ?? '';
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
        if (isset($output['error']) && $output['error'] == 1) {
            $response = [
                'error' => true,
                'message' => 'Invalid authentication info',
            ];
        } elseif (isset($output['severity']) && $output['severity'] === 'ERROR' ) {
            $response = [
                'error' => true,
                'message' => $output['Message'] ?? 'Invalid authentication info',
            ];
        } elseif (isset($output['success']) || (isset($output['severity']) && $output['severity'] === 'SUCCESS')) {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
                'data' => [],
            ];
        }

        return $response;
    }
}
