<?php

namespace App\CustomClasses\FreightQuote\ChrLtl;

use App\CustomClasses\CarriersConnectionSettings;
use App\CustomClasses\CurlRequest;
use Illuminate\Support\Facades\Log;

class ConnectionSettings extends CarriersConnectionSettings
{
    public function __construct()
    {
        parent::__construct();
        $this->curlRequest = new CurlRequest();
    }

    public function testConnection($data, $storeName)
    {
        $url = $this->testConnectionUrl;
        $params = [
            // -------------Carrier type and Status------------- //
            'licence_key' => '',
            'sever_name' => $storeName ?? '',
            'platform' => 'bigcommerce',
            'carrierName' => 'b2b', //
            'carrier_mode' => 'test',
            'dont_auth' => '1',
            // -------------Carrier Credentials------------- //
            'b2bApiVersion' => '2.0',
            'client_id' => '0oa6btwvdsXYlfNy3357',
            'client_secret' => 'aLZrUajjP-_FX6X7tHmDZqzSBtQ93esruZ0jG5Vj',
            'customer_code' => $data->customer_code,
        ];

        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');
        Log::info('FreightQuote CHR Test Con Response ' . $output['response']);
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
