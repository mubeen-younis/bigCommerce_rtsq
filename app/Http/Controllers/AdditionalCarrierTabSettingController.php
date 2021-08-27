<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CarrierServices;
use App\Models\AdditionalCarrierTabSetting;

class AdditionalCarrierTabSettingController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $services = CarrierServices::where('app_id', 1)->orderBy('speed_freight_carrierName')->get();
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
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $settings = AdditionalCarrierTabSetting::firstOrNew(['installed_carrier_id' => $request->carrierId, 'store_id' => $request->store_id]);

        $settings->installed_carrier_id = $request->carrierId;
        $settings->store_id = $request->store_id;
        $settings->value = json_encode($request->services);
        $settings->save();

        return response()->json(['error' => false, 'message' => 'Carriers has been saved successfully.', 'data' => $settings]);
    }

    /**
     * Display the specified resource.
     *
     * @param \App\AdditionalCarrierTabSetting $additionalCarrierTabSetting
     * @return \Illuminate\Http\Response
     */
    public function show(AdditionalCarrierTabSetting $additionalCarrierTabSetting)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param \App\AdditionalCarrierTabSetting $additionalCarrierTabSetting
     * @return \Illuminate\Http\Response
     */
    public function edit(AdditionalCarrierTabSetting $additionalCarrierTabSetting)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\AdditionalCarrierTabSetting $additionalCarrierTabSetting
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, AdditionalCarrierTabSetting $additionalCarrierTabSetting)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\AdditionalCarrierTabSetting $additionalCarrierTabSetting
     * @return \Illuminate\Http\Response
     */
    public function destroy(AdditionalCarrierTabSetting $additionalCarrierTabSetting)
    {
        //
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAddTabSett(Request $request)
    {
        if (empty($request->carrier_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => "Carrier Id Missing",
            ], 404);
        }
        $addTabSettings = AdditionalCarrierTabSetting::where('store_id', $request->store_id)
            ->where('carrier_id', $request->carrier_id)
            ->first();
        // If Record NOt Exists
        if ($addTabSettings === null) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => "Settings Not Found",
            ], 404);
        }
        return response()->json(['error' => false,
            'data' => $addTabSettings,
            'message' => "Settings Not Found",
        ], 200);

    }

    public function getAddTabSettByCarrierID(Request $request, $carrierId)
    {
        $addTabSettings = AdditionalCarrierTabSetting::select('additional_carrier_tab_settings.value')
            ->where('additional_carrier_tab_settings.store_id', $request->store_id)
            ->where('additional_carrier_tab_settings.installed_carrier_id', $carrierId)
            ->get();

        return response()->json(['error' => false,
            'data' => $addTabSettings,
            'message' => "Settings Found",
        ], 200);
    }
}
