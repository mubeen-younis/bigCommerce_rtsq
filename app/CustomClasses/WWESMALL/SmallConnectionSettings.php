<?php


namespace App\CustomClasses\WWESMALL;

use App\CustomClasses\CurlRequest;
use App\Models\Connection;

class SmallConnectionSettings
{
    private $testConnectionUrl = 'https://eniture.com/ws/carriers/wwe-small/speedshipTest.php';
    public function __construct()
    {

        $this->curlRequest = new CurlRequest();
    }

    public function testSmallConnection($data, $storeName)
    {
        $response = [
            'error' => true,
            'message' => 'Something went wrong!',
        ];

        $url = $this->testConnectionUrl; //Constant::TEST_CONN_URL;
        $params = [
            'platform' => 'bigcommerce',
            'speed_freight_username' => $data->username,
            'speed_freight_password' => $data->password,
            'authentication_key' => $data->authentication_key,
            'world_wide_express_account_number' => $data->account_number,
            'plugin_domain_name' => $storeName ?? '',
            'plugin_licence_key' => $data->license_key ?? '',
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
