<?php

namespace App\Http\Controllers;

use App\Constants\Constant;
use App\Models\Store;
use App\Models\Subscription\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SaleGraphController extends Controller
{

    static function updateGraphData(){
        $url = Constant::GRAPH_UPDATE_DATA;
        $activeStores = Subscription::where('status', 1)->where('plan_id', '>', 1)->get()->count();
        Log::info('active store count '. json_encode($activeStores));
        $data = array(
            'platform' => 'bigcommerce',
            'licenseKey' => 'V1T9ZBIG-COMMERCE-01MMZZ3W-O0TOJAQG',
            'totalInstallCount' => $activeStores // total active apps count
        );

        $field_string = http_build_query($data);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $field_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($ch);
        curl_close($ch);
        Log::info('update data on graphs '. json_encode($output));
    }
}
