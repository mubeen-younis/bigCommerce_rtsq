<?php

namespace App\Http\Controllers;

use App\CurlRequest;
use App\Models\Connection;
use App\Models\InstalledCarrier;
use App\Models\Store;
use Illuminate\Http\Request;

class RADController extends Controller
{
    private $curlRequest;

    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
    }

    public function getPlans(Request $request)
    {
        $storeId = $request->store_id ?? null;
        $message = 'No store found!';

        if ($storeId !== null){
                $getInstalledCarriers = InstalledCarrier::where(['store_id' => $storeId, 'is_enabled' => 1])->first();
                if (!empty($getInstalledCarriers)){
                    $connectionSettings = Connection::where('installed_carrier_id', $getInstalledCarriers->id)->first();
                    if (!empty($connectionSettings)){
                        $settings = json_decode($connectionSettings->value);
                        $requestData = [
                            'platform' => 'bigcommerce',
                            'request_key' => 'fowpejopeojpwefwekashdkasd',
                            'action' => 's',
                            'package' => '',
                            'licenseKey' => $settings->license_key,
                            'serverName' => 'store-uann2u.mybigcommerce.com',//$store->hash,
                        ];
                        $response = $this->curlRequest->enSingleCurlRequest('https://eniture-qa.com/ws/addon/rad/index.php',$requestData, [],'  POST',false);
                        return response()->json([
                            'data' => $response,
                            'error' => false,
                            'message' => 'Response Successful.'
                        ], 200);
                    }else{
                        $message = 'No connection settings available!';
                    }
                }else{
                    $message = 'No carrier enabled!';
                }
        }
        return response()->json([
            'data' => [],
            'error' => true,
            'message' => $message
        ],403);
    }

    public function changePlan(Request $request)
    {

    }

    public function changeStatus(Request $request)
    {

    }

    public function setDefaultAddress(Request $request)
    {

    }

}
