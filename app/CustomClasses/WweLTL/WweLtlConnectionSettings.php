<?php


namespace App\CustomClasses\WweLTL;

use App\CustomClasses\CurlRequest;
use App\Endpoints\Endpoints;
use App\Models\Connection;
use Illuminate\Support\Facades\Log;
use App\Constants\Constant;

class WweLtlConnectionSettings
{
    private $testConnectionUrl;

    public function __construct()
    {
        $this->testConnectionUrl = Endpoints::wweLtlTestEndpoint();

        $this->curlRequest = new CurlRequest();
    }

    public function testLtlConnection($data, $storeName)
    {

        $response = [
            'error' => true,
            'message' => 'Something went wrong!',
        ];
        $url = $this->testConnectionUrl;  //Constant::TEST_CONN_URL;
        $params = [
            'platform' => 'bigcommerce',
            'speed_freight_username' => $data->username,
            'speed_freight_password' => $data->password,
            'authentication_key' => $data->authentication_key,
            'world_wide_express_account_number' => $data->account_number,
            'plugin_domain_name' => $storeName ?? '',
            'plugin_licence_key' => $data->license_key ?? '',
            'dont_auth' => 1,
            'carrier_mode' => 'test',
            // New Api Test Connection Params
            'clientId' => $data->clientId,
            'clientSecret' => $data->clientSecret,
            'ApiVersion' => '2.0'
        ];

        if (isset($data->api_type) && $data->api_type === 'new_api'){
            unset($params['authentication_key'], $params['world_wide_express_account_number']);

            $params['speed_freight_username'] = $data->new_api_username ?? '';
            $params['speed_freight_password'] = $data->new_api_password ?? '';

        } else {
            unset($params['clientId'], $params['clientSecret'], $params['ApiVersion']);
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
        if (isset($output['error']) && isset($output['error_desc'])) {
            $response = [
                'error' => true,
                'message' => $output['error_desc'],
            ];
        } elseif (isset($output['severity']) && $output['severity'] === 'ERROR') {
            $response = [
                'error' => true,
                'message' => $output['Message'],
            ];
        } elseif (isset($output['success']) || (isset($output['severity']) && $output['severity'] === 'SUCCESS')) {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
                'data' => [],
                'type' => 'ltl'
            ];
        }
        return $response;

    }
}
