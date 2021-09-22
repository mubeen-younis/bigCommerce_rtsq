<?php

namespace App\CustomClasses\Fedex\ltl;

use App\CustomClasses\CurlRequest;

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
        $params = array(
            'dont_auth' => '1',
            'platform' => 'bigcommerce',
            'testConnectionCarrier' => 'fedex',
            'carrier_mode' => 'test',
            'carrierName' => 'fedex',
            'sever_name' => $storeName ?? '',
            'accountType' => 'shipper',
            'AccountNumber' => $data->account_number ?? '',
            'MeterNumber' => $data->meter_number ?? '',
            'password' => $data->password ?? '',
            'key' => $data->api_access_key ?? '',
            'shippingChargesAccount' => $data->shipping_account_number ?? '',
            'billingLineAddress' => $data->billing_address ?? '',
            'billingCountry' => $data->billing_country ?? '',
            'billingCity' => $data->billing_city ?? '',
            'billingState' => $data->billing_state ?? '',
            'billingZip' => $data->billing_zip ?? '',
            'physicalCountry' => $data->physical_country ?? '',
            'physicalAddress' => $data->physical_address ?? '',
            'physicalCity' => $data->physical_city ?? '',
            'physicalStateOrProvinceCode' => $data->physical_state ?? '',
            'physicalPostalCode' => $data->physical_zip ?? '',
            'third_party_account' => $data->third_party_account ?? '',
        );
        /*$params = array (
            'testConnectionCarrier' => 'fedex',
            'AccountNumber' => '337176828',
            'MeterNumber' => '110649551',
            'password' => 'VdLIMFlQLmmue66wjN9kvAf1V',
            'key' => 'qpnfALPWMLAYIj39',
            'shippingChargesAccount' => '322517297',
            'billingLineAddress' => '2525 N. LOCH LOMOND CT',
            'billingCountry' => 'US',
            'billingCity' => 'Wichita',
            'billingState' => 'KS',
            'billingZip' => '67228',
//  'physicalAddress' => '2525 N. LOCH LOMOND CT',
            'physicalCountry' => 'US',
            'physicalAddress' => '15500 E 590 ROAD',
            'physicalCity' => 'INOLA',
            'physicalStateOrProvinceCode' => 'OK',
            'physicalPostalCode' => '74036',
            'third_party_account' => '',
            'dont_auth' => '1',
            'plateform' => 'bigcommerce',
            'carrier_mode' => 'test',
            'carrierName' => 'fedex',
            'sever_name' => 'store-uann2u.mybigcommerce.com',
            'accountType' => 'shipper',
        );*/
        $queryString = http_build_query($params);
        $output = $this->curlRequest->enSingleCurlRequest($url, $queryString, [], 'POST');

        if (isset($output['severity']) && $output['severity'] === 'ERROR') {
            $response = [
                'error' => true,
                'message' => $output['message'],
            ];
        }
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
}
