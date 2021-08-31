<?php


namespace App\CustomClasses\WweLTL;

use App\CustomClasses\CurlRequest;
use App\Models\Connection;
use Illuminate\Support\Facades\Log;
use App\Constants\Constant;
class WweLtlConnectionSettings
{
    public function __construct()
    {

        $this->curlRequest = new CurlRequest();
    }

    public function testLtlConnection($data)
    {

        $response = [
            'error' => true,
            'message' => 'Something went wrong!',
        ];
        $url = 'https://eniture.com/ws/carriers/wwe-freight/speedfreightTest.php'; //Constant::TEST_CONN_URL;
        $params = [
            'platform' => 'bigcommerce',
            'speed_freight_username' => $data->username,
            'speed_freight_password' => $data->password,
            'authentication_key' => $data->authentication_key,
            'world_wide_express_account_number' => $data->account_number,
            'plugin_domain_name' => 'store-uann2u.mybigcommerce.com',
            'plugin_licence_key' => $data->license_key ?? '',
            'dont_auth' => 1
        ];


        $queryString = http_build_query($params);

        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');
        //Log::info('$params '. json_encode($params) . ' $output '. json_encode($output));
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
                'data' => Connection::where('installed_carrier_id', $data->carrierId)->first(),
                'type' => 'ltl'
            ];
        }
        return $response;

    }
}
