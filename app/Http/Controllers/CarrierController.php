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

     return response()->json(Carrier::get(),200);

    }

    public function getAllCarriers(Request $request)
    {
        $response = [
            'error' => true,
            'message' => 'Something went wrong',
            'data' => []
        ];
        $store = $request->store ?? null;
        if (!empty($store)){
            $installedCarriers = Store::find('hash', $store)->installedCarriers();
            $response['data']['installedCarriers'] = $installedCarriers;
        }else{
            $response['message'] = 'Store hash is required.';
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
}
