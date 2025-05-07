<?php

namespace App\CustomClasses\KNLtl;

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
        $url = $this->testConnectionUrl;
        // $url = "http:localhost/ws/index.php";
        // dd($url);

        $params = array(
            'dont_auth' => '1',
            'licence_key' => 'TDVB9ONC-M7QJRPRQ-5EDIH32D-DE73Y57I',
            // -------------Carrier Credentials------------- //
            // 'apiVersion' => '1.0',
            'carrierName' => 'Kuehne-Nagel',
            'carrier_mode' => 'test',
            'platform' => 'bigcommerce',
            'userName' => $data->username,
            'authenticationID' => $data->autId,
            'clientCode' => $data->clientCode,
            'sever_name' => $storeName,
            // 'sever_name' => 'wc.eniture-dev3.com',
            
        );

        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');

        $outresp = json_decode($output['response'], true);

        // if (isset($outresp['severity']) && $outresp['severity'] === 'ERROR') {
        //     // dd("outresp", $outresp['Message']);
        //     $response = [
        //         'error' => true,
        //         'message' => $outresp['Message'],
        //     ];
        // }

        if (isset($outresp['severity']) && $outresp['severity'] === 'SUCCESS') {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
                'data' => [],
            ];
        }elseif(isset($outresp['severity']) && $outresp['severity'] === 'ERROR') {
            // dd("outresp", $outresp['Message']);
            $response = [
                'error' => true,
                'message' => $outresp['Message'],
            ];
        }else{
            $response = [
                'error' => true,
                'message' => 'Invalid authentication info',
            ];
        }
        return $response;
    }
}
