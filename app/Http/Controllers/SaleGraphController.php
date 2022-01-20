<?php

namespace App\Http\Controllers;

use App\Constants\Constant;
use App\Models\Store;
use Illuminate\Http\Request;

class SaleGraphController extends Controller
{

    static function updateGraphData(){
        $url = Constant::GRAPH_UPDATE_DATA;
        $data = array(
            'platform' => 'bigcommerce',
            'licenseKey' => 'V1T9ZBIG-COMMERCE-01MMZZ3W-O0TOJAQG',
            'totalInstallCount' => Store::where('app_status', 1)->get()->count() // total active apps count
        );

        $field_string = http_build_query($data);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $field_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($ch);
        curl_close($ch);
    }
}
