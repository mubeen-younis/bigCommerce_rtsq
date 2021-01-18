<?php

namespace App\Http\Controllers;

use App\Models\CarrierPlan;
use App\Models\InstalledCarrier;
use App\Models\Store;
use Illuminate\Http\Request;

class CarrierPlanController extends Controller
{
    public function getPlansDetail(Request $request)
    {
        $plans = CarrierPlan::join('carriers', 'carriers.id', 'carrier_plans.carrier_id')
            ->groupBy('carriers.id')
            ->get();
        if ($plans->isEmpty()) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Carrier Plans'
            ], 404);
        }
        return response()->json(['error' => false,
            'data' => $plans,
            'message' => ''
        ], 200);
    }

    public function getSingleCarrierPlan(Request $request)
    {
        if (empty($request->carrier_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Carrier Id'
            ], 404);
        }
        $plans = CarrierPlan::join('carriers', 'carriers.id', 'carrier_plans.carrier_id')
            ->where('carrier_plans.carrier_id', $request->carrier_id)
            ->groupBy('carriers.id')
            ->get();
        if ($plans->isEmpty()) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Carrier Plans'
            ], 404);
        }
        return response()->json(['error' => false,
            'data' => $plans,
            'message' => ''
        ], 200);
    }

    public function addPlanFromWs(Request $request)
    {
        try {
            $storeHash = explode('.', $request->store_name);
            $storeHash = $storeHash[0];
            $carrierId = $request->carrier_id;
            $store = Store::where('hash', $storeHash)->first();
            $storeId = $store->id;
            $installedCarId = InstalledCarrier::where('store_id', $storeId)->where('carrier_id', $carrierId)->first();
            $installedCarId = $installedCarId->id;
            $plan = new CarrierPlan();
            $plan->plan_type = $request->plan_type;
            $plan->installed_carrier_id = $installedCarId;
            $plan->pakg_price = $request->pakg_price;
            $plan->pakg_duration = $request->pakg_duration;
            $plan->pakg_group = $request->pakg_group;
            $plan->pakg_level = $request->pakg_level;
            $plan->expiry_date = $request->expiry_date;
            $plan->save();
            return response()->json(
                ['error' => false,
                    'data' => $plan->id,
                    'message' => 'Plan Added Successfully'
                ], 200);
        } catch (\Exception $exception) {
            return response()->json(
                ['error' => false,
                    'data' => [],
                    'message' => $exception->getMessage()
                ], 502);
        }


    }
}
