<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Plans;

class PlansController extends Controller
{
    public function getPlansInfo(Request $request){
        $store = $request->store;
        if($store !== null) {
            $plans = Plans::select('plans_info.value')
                ->join('installed_carriers', 'installed_carriers.id', '=', 'plans_info.installed_carrier_id')
                ->join('stores', 'stores.id', '=', 'installed_carriers.store_id')
                ->where('installed_carriers.is_enabled', 1)
                ->where('stores.hash', $store)->get();
            return ['error' => false,
                'data' => $plans,
                'status' => 200
            ];
        }else{
            return ['error' => true,
                'data' => [],
                'message' => 'Store is required',
                'status' => 400
            ];
        }
    }
}
