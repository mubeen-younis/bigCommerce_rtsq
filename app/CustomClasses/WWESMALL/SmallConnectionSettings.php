<?php


namespace App\CustomClasses\WWESMALL;

use App\CustomClasses\CurlRequest;
use App\Models\Connection;

class SmallConnectionSettings
{
    public function __construct()
    {

        $this->curlRequest = new CurlRequest();
    }

    public function testSmallConnection($data)
    {
        $response = [
            'error' => true,
            'message' => 'Something went wrong!',
        ];
        $url = 'https://eniture-qa.com/ws/carriers/wwe-small/speedshipTest.php'; //Constant::TEST_CONN_URL;
        $params = [
            'platform' => 'bigcommerce',
            'speed_freight_username' => $data->username,
            'speed_freight_password' => $data->password,
            'authentication_key' => $data->authentication_key,
            'world_wide_express_account_number' => $data->account_number,
            'plugin_domain_name' => 'store-uann2u.mybigcommerce.com',
            'plugin_licence_key' => $data->license_key,
        ];

        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');
        dd($output);
/*
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1000);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $query_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($ch);
        curl_close($ch);
        $output = \GuzzleHttp\json_decode($output, true);*/
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
            ];
        }
        return $response;

    }
}
