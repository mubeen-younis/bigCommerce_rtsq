<?php

namespace App\Http\Controllers;

use App\Models\Carrier;
use App\Models\InstalledCarrier;
use Illuminate\Http\Request;

class InstalledCarrierController extends Controller
{
    public function installCarrier(Request $request)
    {
        try {
            $installCarrier = new InstalledCarrier();
            $installCarrier->store_id = $request->store_id;
            $installCarrier->carrier_plan_id = $request->carrier_id;
            $installCarrier->is_enabled = $request->is_enabled;
            $installCarrier->installed_at = now();
            $installCarrier->plan_updated_at = now();
            $installCarrier->save();
            return response()->json(['error' => false,
                'data' => $installCarrier->id,
                'message' => 'Carrier Installed Successfully'
            ], 200);
        } catch (\Exception $exception) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => $exception->getMessage()
            ], 500);
        }
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getInstalledCarriers(Request $request)
    {
        $installedCarriers = Carrier::join('installed_carriers', 'carriers.id', 'installed_carriers.carrier_plan_id')
            ->where('installed_carriers.store_id', $request->store_id)
            ->get();
        if ($installedCarriers->isEmpty()) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Installed Carriers Found'
            ], 404);
        }
        return response()->json(['error' => false,
            'data' => $installedCarriers,
            'message' => ''
        ], 200);
    }
}
