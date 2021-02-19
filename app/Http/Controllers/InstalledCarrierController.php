<?php

namespace App\Http\Controllers;

use App\Models\Carrier;
use App\Models\InstalledCarrier;
use Illuminate\Http\Request;

class InstalledCarrierController extends Controller
{
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function installCarrier(Request $request)
    {
        try {
            $installCarrier = new InstalledCarrier();
            $installCarrier->store_id = $request->store_id;
            //$installCarrier->carrier_plan_id = $request->carrier_id;
            $installCarrier->is_enabled = $request->is_enabled;
            $installCarrier->installed_at = now();
            $installCarrier->plan_updated_at = now();
            $installCarrier->save();
            return response()->json(['error' => false,
                'data' => $installCarrier->id,
                'message' => 'Carrier Installed Successfully',
            ], 200);
        } catch (\Exception $exception) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getInstalledCarriers(Request $request)
    {
        $store = $request->store;

        $installedCarriers = Carrier::select("*")->join('installed_carriers', 'installed_carriers.carrier_id', '<>', 'carriers.id')->join('stores', 'store.id', '=', 'installed_carriers.store_id')
            ->where('stores.hash', $store)
            ->get();

        if ($installedCarriers->isEmpty()) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Installed Carriers Found',
            ], 404);
        }

        return response()->json(['error' => false,
            'data' => $installedCarriers,
            'message' => '',
        ], 200);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateCarrier(Request $request)
    {
        if (empty($request->carrier_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Carrier Id Not Exists',
            ], 404);
        }
        if (empty($request->is_enabled)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Request Not Properly Formatted',
            ], 404);
        }
        if (InstalledCarrier::where('id', $request->carrier_id)->exists()) {
            InstalledCarrier::where('id', $request->carrier_id)->update(['is_enabled' => $request->is_enabled]);
            return response()->json(['error' => false,
                'data' => [],
                'message' => 'Carrier Updated Successfully',
            ], 200);
        } else {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Carrier exists against this Id',
            ], 404);
        }
    }
}
