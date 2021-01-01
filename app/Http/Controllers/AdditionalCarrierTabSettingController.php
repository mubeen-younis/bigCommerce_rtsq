<?php

namespace App\Http\Controllers;

use App\Models\AdditionalCarrierTabSetting;
use App\Models\CarrierServices;
use Illuminate\Http\Request;

class AdditionalCarrierTabSettingController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $services = CarrierServices::where('app_id', 1)->get();
        return response()->json(['error' => false, 'data' => $services]);
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
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $settings = AdditionalCarrierTabSetting::firstOrNew(['carrier_id' => 1, 'store_id' => 1]);
        $settings->carrier_id = 1;
        $settings->store_id = 1;
        $settings->value = json_encode($request->all());
        $settings->save();
        return response()->json(['error' => false, 'message' => 'Carriers have been successfully saved.', 'data' => $settings]);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\AdditionalCarrierTabSetting  $additionalCarrierTabSetting
     * @return \Illuminate\Http\Response
     */
    public function show(AdditionalCarrierTabSetting $additionalCarrierTabSetting)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\AdditionalCarrierTabSetting  $additionalCarrierTabSetting
     * @return \Illuminate\Http\Response
     */
    public function edit(AdditionalCarrierTabSetting $additionalCarrierTabSetting)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\AdditionalCarrierTabSetting  $additionalCarrierTabSetting
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, AdditionalCarrierTabSetting $additionalCarrierTabSetting)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\AdditionalCarrierTabSetting  $additionalCarrierTabSetting
     * @return \Illuminate\Http\Response
     */
    public function destroy(AdditionalCarrierTabSetting $additionalCarrierTabSetting)
    {
        //
    }
}
