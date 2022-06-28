<?php

namespace App\CustomClasses\XPO\ltl;

use App\Constants\Constant;
use App\CustomClasses\CarriersConnectionSettings;
use App\CustomClasses\CurlRequest;
use Illuminate\Support\Facades\DB;

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

        $params = [
            'platform' => 'bigcommerce',
            'carrier_mode' => 'test',
            'accessLevel' => 'pro', // pro , test
            'version' => '2.0',
            'dont_auth' => '1',
            'carrierName' => 'xpoLogistics',
            'serverName' => $storeName ?? '',
            'UserName' => $data['username'] ?? '',
            'Password' => $data['password'] ?? '',
            'CUSTNMBR' => $data['delivery_account_number'] ?? '',
            'physicalZipCode' => $data['delivery_postal_code'] ?? '',
            'thirdPartyAccountNumber' => $data['bill_to_account_number'] ?? '',
            'requestType' => isset($data['access_level']) && $data['access_level'] == 'pro' ? 'thirdParty' : 'shipper'
        ];
        $isPro = false;
        if (isset($data['access_level']) && $data['access_level'] == 'pro' && isset($data['api_key']) && $data['api_key'] != '') {
            $Test = [
                'basicAccessToken' => $data['api_key'] ?? '',
                'xpoApiVersion' => '1.0',
            ];
            $params = array_merge($params, $Test);
            $isPro = true;
        }

        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');
        $output = json_decode($output['response'], true);
        if (isset($output['error'])) {
            $response = [
                'error' => true,
                'message' => 'Invalid authentication info',
            ];
        } else {
            if ($isPro) {
                if (isset($output['severity']) && $output['severity'] === 'ERROR') {
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
            } else {
                if (isset($output['Error'])) {
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
            }
        }
        return $response;

    }


}
