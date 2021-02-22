<?php

namespace App\Http\Controllers;

use App\Models\Carrier;
use App\Models\InstalledCarrier;
use App\Models\Store;
use Illuminate\Http\Request;

class CarrierController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $response = [
            'error' => false,
            'carriers' => Carrier::get(),
        ];

        return response()->json($response, 200);

    }

    public function getAllCarriers(Request $request)
    {
        $response = [
            'error' => false,
        ];
        $store = $request->store ?? null;
        if (!empty($store)) {
            //$installedCarriers = Store::where('hash', $store)->installedCarriers();
            $installedCarriers = Store::where('hash', $store)->get();
            $response['data']['installedCarriers'] = $installedCarriers;

        } else {
            $response = [
                'error' => true,
                'message' => 'Store hash is required.',
                'data' => [],
            ];
        }

        return response()->json($response, 200);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Carrier  $carrier
     * @return \Illuminate\Http\Response
     */
    public function show(Carrier $carrier)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Carrier  $carrier
     * @return \Illuminate\Http\Response
     */
    public function edit(Carrier $carrier)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Carrier  $carrier
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Carrier $carrier)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Carrier  $carrier
     * @return \Illuminate\Http\Response
     */
    public function destroy(Carrier $carrier)
    {
        //
    }

    public function getCarrierDetails(Request $request)
    {
        return response()->json([], 200);
    }

    public function installCarrier(Request $request)
    {
        if (empty($request->carrier_id)) {
            return response()->json([
                'error' => true,
                'message' => 'Empty Carrier ID',
            ], 200);
        }

        if (!empty($request->header('authorization'))) {
            $token = explode(' ', $request->header('authorization'))[1];
            $store = Store::where('token', $token)->first();

            $installCarrier = new InstalledCarrier();
            $installCarrier->store_id = $store->id;
            $installCarrier->carrier_id = $request->carrier_id;
            $installCarrier->is_enabled = false;
            $installCarrier->installed_at = now();
            $installCarrier->plan_updated_at = now();
            $installCarrier->save();

            return response()->json(['error' => false,
                'data' => $installCarrier,
                'error' => false,
                'message' => 'Carrier Installed Successfully',
            ], 200);

        } else {
            return response()->json(['error' => true,
                'data' => [],
                'message' => "Carrier couldn't be installed",
            ], 500);
        }
    }

    public function getInstalledCarriers(Request $request)
    {
        if (!empty($request->header('authorization'))) {
            $token = explode(' ', $request->header('authorization'))[1];

            $installedCarriers = Carrier::select('carriers.name', 'carriers.id', 'carriers.logo', 'installed_carriers.store_id', 'installed_carriers.carrier_id', 'carriers.carrier_type', 'installed_carriers.is_enabled')
                ->join('installed_carriers', 'installed_carriers.carrier_id', '=', 'carriers.id')
                ->join('stores', 'stores.id', '=', 'installed_carriers.store_id')
                ->where('stores.token', $token)->get();

            if ($installedCarriers->isEmpty()) {
                return response()->json(['error' => true,
                    'data' => [],
                    'message' => 'No Installed Carriers Found',
                ], 404);
            }

            $response['error'] = false;
            $response['data']['installedCarriers'] = $installedCarriers;

            return response()->json($response, 200);
        } else {
            return response()->json([
                'error' => true,
                'data' => [],
                'message' => 'Empty Token Field',
            ], 404);
        }
    }

    public function getRecommendedCarriers(Request $request)
    {
        if (!empty($request->header('authorization'))) {
            $token = explode(' ', $request->header('authorization'))[1];

            $installedCarriers = Carrier::select('carriers.id', 'carriers.carrier_type', 'carriers.name', 'carriers.logo', 'installed_carriers.store_id', 'installed_carriers.carrier_id', 'installed_carriers.is_enabled')
                ->join('installed_carriers', 'installed_carriers.carrier_id', '!=', 'carriers.id')
                ->join('stores', 'stores.id', '=', 'installed_carriers.store_id')
                ->where('stores.token', $token)->get();

            if ($installedCarriers->isEmpty()) {
                return response()->json(['error' => true,
                    'data' => [],
                    'message' => 'Carriers Not Found',
                ], 404);
            }

            $response['error'] = false;
            $response['data']['carriers'] = $installedCarriers;

            return response()->json($response, 200);
        }
    }

    public function changeCarrierStatus(Request $request)
    {
        $carrier = InstalledCarrier::where('carrier_id', $request->carrier_id)->first();

        if ($carrier) {
            InstalledCarrier::where('carrier_id', $request->carrier_id)->update(['is_enabled' => !$carrier->is_enabled]);

            return response()->json(['data' => InstalledCarrier::find($request->carrier_id), 'message' => 'Carrier updated'], 200);
        } else {
            return response()->json([
                'message' => 'Invalid Carrier ID',
            ], 404);
        }
    }

}
