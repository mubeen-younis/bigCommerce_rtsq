<?php

namespace App\CustomClasses\GTZ\ltl;

use App\Constants\Constant;
use App\CustomClasses\CarriersConnectionSettings;
use App\CustomClasses\CurlRequest;
use Illuminate\Support\Facades\DB;
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
        $url = $this->testConnectionUrl;
        if($data->api_type === 'CRS'){
            $data = $data->cerasis;
            $apiType = 'cerasis';
        }elseif($data->api_type === 'GTZ'){
            $data = $data->global_tranz;
            $apiType = 'globalTranz';
        }else{
            $data = $data->gtz_new_api;
            $apiType = 'NEWAPI';
        }
        if($apiType === 'NEWAPI'){
            $url = Endpoints::wweLtlTestEndpoint();
            $params = [
                'platform' => 'bigcommerce',
                'carrier_mode' => 'test',
                'speed_freight_username' => $data['user_name'],
                'speed_freight_password' => $data['password'],
                'plugin_domain_name' => $storeName ?? '',
                'plugin_licence_key' => $data->license_key ?? '',
                'dont_auth' => 1,
                // New Api Test Connection Params
                'clientId' => $data['clientId'],
                'clientSecret' => $data['clientSecret'],
                'ApiVersion' => '2.0',
                'requestFromGlobalTranz' => 1
            ];
        }else{
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
        }

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

    function getCerasisProviders($storeId, $gtzAppId){
        $connectionSettings = DB::table('connection_settings')
            ->select('connection_settings.value')
            ->join('installed_carriers','installed_carriers.id', 'connection_settings.installed_carrier_id')
            ->where('installed_carriers.carrier_id', $gtzAppId)
            ->where('installed_carriers.store_id', $storeId)->first();

        if($connectionSettings == null || empty($connectionSettings)){
            return null;
        }
        $data = json_decode($connectionSettings->value);

        if($data->api_type === 'GTZ'){
            return null;
        }

        $url = $this->testConnectionUrl;

        $params = array(
            'serverName' => $data->store_name,
            'platform'    => 'bigcommerce',
            'carrierName' => 'cerasis',
            'carrier_mode' => 'getcarriers',
            'dont_auth' => '1',
            // -------------Carrier Credentials------------- //
            'shipperID' => $data->cerasis->customer_id ?? '',
            'username' => $data->cerasis->user_name ?? '',
            'password' => $data->cerasis->password ?? '',
            'accessKey' => $data->cerasis->access_key ?? '',
        );
        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');
        $output = json_decode($output['response'], true);

        if (isset($output['severity']) && $output['severity'] === 'ERROR') {
            return null;
        }
        return $output;
    }

}
