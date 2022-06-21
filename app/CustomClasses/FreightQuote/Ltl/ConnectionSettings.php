<?php

namespace App\CustomClasses\FreightQuote\Ltl;

use App\Constants\Constant;
use App\CustomClasses\CurlRequest;
use Illuminate\Support\Facades\Log;

class ConnectionSettings
{
    private $testConnectionUrl = Constant::BASEURL . '/ws/index.php';

    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
    }

    public function testConnection($data, $storeName)
    {

        $params = [

            // -------------Carrier type and Status------------- //
            /*
             * Use License key and Server name if you want Authenication for eniture Subcription.
             */
            'sever_name' => $storeName ?? '', // $_SERVER['SERVER_NAME'];

            /*
             *  carrierName is mendatory to get quotes for specific Carrier.
             */
            'carrierName' => 'b2b', //
            'carrier_mode' => 'test', // use test / pro
            /*
              comment "dont_auth" if you want Authentication.
              Uncomment "dont_auth" and set 1 if you don't want Authentication for license key and domain name etc.
             */
            'dont_auth' => '1',
            // -------------Carrier Credentials------------- //
            // when freightquote.com is selected from the dropdown
            'name' => $data->username ?? '',
            'password' => $data->password ?? '',
            'platform' => 'bigcommerce',
            'version' => '2.0',
        ];


        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($this->testConnectionUrl, $queryString, [], 'POST');
        Log::info('FreightQuote Test Con Response ' . $output['response']);
        $output = json_decode($output['response'], true);
        if (isset($output['severity']) && $output['severity'] == "ERROR") {
            $response = [
                'error' => true,
                'message' => 'Invalid authentication info',
            ];
        } else {
            $response = [
                'error' => false,
                'message' => 'Test connection successful.',
                'data' => [],
            ];
        }


        return $response;

    }


}
