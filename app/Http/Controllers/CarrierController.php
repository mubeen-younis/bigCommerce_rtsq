<?php

namespace App\Http\Controllers;

use App\Models\Carrier;
use App\Models\InstalledCarrier;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CarrierController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {

     return response()->json(Carrier::get(),200);

    }

    public function getAllCarriers(Request $request)
    {
        $response = [
            'error' => false,
        ];
        $store = $request->store ?? null;
        if (!empty($store)){
            //$installedCarriers = Store::where('hash', $store)->installedCarriers();
            $installedCarriers = Store::where('hash', $store)->get();
            $response['data']['installedCarriers'] = $installedCarriers;

        }else{
            $response = [
                'error' => true,
                'message' => 'Store hash is required.',
                'data' => []
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

    public function getInstalledCarriers()
    {
        $installed_carriers = DB::table('carriers')->join('installed_carriers', 'carriers.id', 'installed_carriers.carrier_id')
            ->get();

        if ($installed_carriers->isEmpty()) {
            return response()->json([
                'data' => [],
                'message' => 'No Carrier Found',
            ], 404);

        }

        $response['data']['installedCarriers'] = $installed_carriers;

        return response()->json($response, 200);
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
