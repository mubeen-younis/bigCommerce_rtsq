<?php

namespace App\Http\Controllers;

use App\Models\Carrier;
use Illuminate\Http\Request;
use App\Models\CarrierServices;
use App\Models\AdditionalCarrierTabSetting;
use Illuminate\Support\Facades\DB;
use App\CustomClasses\GTZ\ltl\ConnectionSettings;

class AdditionalCarrierTabSettingController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $installed_carrier = $request->installed_carrier_id;

        $carrier = DB::table('installed_carriers')
            ->select('slug')
            ->join('carriers', 'carriers.id', 'installed_carriers.carrier_id')
            ->where('installed_carriers.id', $installed_carrier)->first();

        if($carrier->slug == 'ltl-quotes' || $carrier->slug == 'freightquote-ltl' || $carrier->slug == 'tql-ltl' || $carrier->slug == "echo-ltl"){
            $services = CarrierServices::join('installed_carriers', 'installed_carriers.carrier_id', '=', 'app_id')
                ->where('installed_carriers.id', $installed_carrier)
                ->orderBy('speed_freight_carrierName')->get();
        }else if($carrier->slug == 'gtz-ltl'){
            $storeId = null;
            $carrierType = $request->carrier_type ?? 'gtz';
            //dd($carrierType);
            if($carrierType === 'CRS'){
                $storeId = $request['store_id'] ?? null;
                $services = CarrierServices::join('installed_carriers', 'installed_carriers.carrier_id', '=', 'app_id')
                    ->where('installed_carriers.id', $installed_carrier)
                    ->where('shopify_freights.store_id', $storeId)
                    ->orderBy('speed_freight_carrierSCAC')->get();
            }else{
                $services = CarrierServices::join('installed_carriers', 'installed_carriers.carrier_id', '=', 'app_id')
                    ->where('installed_carriers.id', $installed_carrier)
                    ->whereNull('shopify_freights.store_id')
                    ->orderBy('speed_freight_carrierName')->get();
            }
        }        

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

    public function syncGTZCerasisProviders(Request $request){
        $storeId = $request['store_id'];
        $ConnectionSettings = new ConnectionSettings();
        $gtzLtlId = Carrier::select('id')->where('slug', 'gtz-ltl')->pluck('id')->toArray()[0] ?? '';
        $resp = $ConnectionSettings->getCerasisProviders($storeId, $gtzLtlId);
        if($resp){
            $insert = [];
            foreach ($resp['carriers'] as $carrier){
                $insert = [
                    'speed_freight_carrierSCAC' => $carrier['CarrierName'] ?? '',
                    'speed_freight_carrierName' => $carrier['CarrierSCAC'] ?? '',
                    'carrier_logo' => $carrier['CarrierLogoUrl'] ?? '',
                    'app_id' => $gtzLtlId,
                    'store_id' => $request['store_id']
                ];
                $Added = CarrierServices::where('speed_freight_carrierSCAC', $insert['speed_freight_carrierSCAC'])
                    ->where('speed_freight_carrierName', $insert['speed_freight_carrierName'])
                    ->where('app_id', $insert['app_id'])
                    ->where('store_id', $insert['store_id'])->exists();
                if(!$Added){
                    CarrierServices::insert($insert);
                    unset($insert);
                }
            }
        }
        $services = CarrierServices::where('shopify_freights.app_id', $gtzLtlId)
            ->where('shopify_freights.store_id', $storeId)
            ->orderBy('speed_freight_carrierSCAC')->get();

        return response()->json(['error' => false,
            'data' => $services,
            'message' => "Success! Carriers list updated successfully.",
        ], 200);
    }

    public function hasInsurance(Request $request){
        $storeId = $request['store_id'];
        $installed_carrier = $request->installed_carrier_id;
        $carrier = DB::table('installed_carriers')
            ->select('slug')
            ->join('carriers', 'carriers.id', 'installed_carriers.carrier_id')
            ->where('installed_carriers.id', $installed_carrier)
            ->where('installed_carriers.store_id', $storeId)->first();

        return response()->json(['error' => false,
            'data' => $this->isInusreCarrier($carrier->slug)
        ], 200);
    }

    public function isInusreCarrier($slug){
        $insureCarrier = ['ltl-quotes', 'small-package', 'ups-small','fedex-small', 'unishippers-small'];
        return in_array($slug, $insureCarrier);
    }
}
