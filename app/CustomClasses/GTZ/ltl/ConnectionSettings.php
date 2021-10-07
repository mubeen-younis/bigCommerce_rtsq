<?php

namespace App\CustomClasses\GTZ\ltl;

use App\CustomClasses\CurlRequest;
use Illuminate\Support\Facades\DB;

class ConnectionSettings
{
    private $testConnectionUrl = 'https://eniture-qa.com/ws/index.php';
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
        if($data->api_type === 'CRS'){
            $data = $data->cerasis;
            $apiType = 'cerasis';
        }else{
            $data = $data->global_tranz;
            $apiType = 'globalTranz';
        }
        $params  = [
            'platform' => 'bigcommerce',
            'carrier_mode' => 'test',
            'accessLevel' => 'pro', // pro , test
            'version' => '2.0',
            'username' => $data['user_name'] ?? '',
            'password' => $data['password'] ?? '',
            'accessKey' => $data['access_key'] ?? '',
            'dont_auth' => '1',
            'serverName' => $storeName ?? '',
            'customer_id' => $data['customer_id'] ?? '',
            'shipperID' => $data['customer_id'] ?? '',
            'carrierName' => $apiType,
        ];

        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');
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

    function getCerasisProviders($storeId){
        $connectionSettings = DB::table('connection_settings')
            ->select('connection_settings.value')
            ->join('installed_carriers','installed_carriers.id', 'connection_settings.installed_carrier_id')
            ->where('installed_carriers.carrier_id', 11)
            ->where('installed_carriers.store_id', $storeId)->first();
        dd($connectionSettings, $storeId);
        $url = $this->testConnectionUrl;
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
        );
        $data = array(
//    'licence_key' => 'LIY0S1Q4-F1RQX57P-34NTF4B1-6ZRHWXKY',
//    'server_name' => 'wpdev4.eniture-dev3.com', // $_SERVER['SERVER_NAME'];
            'licenseKey' => 'MLI5TAWA-CERASIS-LKDYFT-DEV3GGLC',
            'serverName' => 'wpdev1.eniture-dev3.com',
            'platform'    => 'WordPress',
            'carrierName' => 'cerasis',
            'carrier_mode' => 'getcarriers',
            'dont_auth' => '1',
            'requestKey'   => '1146161112344645',
            // -------------Carrier Credentials------------- //
            'shipperID' => 'Demo5',
            'username' => 'eniture',
            'password' => 'wn5kZ8hM',
            'accessKey' => 'd059ba27-7341-40a7-8830-e7c4ec499e97',
        );
        $queryString = http_build_query($params);
    }

}
