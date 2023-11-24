<?php

namespace App\Http\Controllers;

use App\Models\QuoteSetting;
use App\Models\WeightThresholdSettings;
use App\Models\InstalledCarrier;
use Illuminate\Http\Request;
use App\Http\Controllers\AdditionalCarrierTabSettingController;
use App\Models\AdditionalCarrierTabSetting;

class QuoteSettingsController extends Controller
{
    //
    public function getSettings(Request $request, $carrierId)
    {
        $carrierId = $carrierId ?? 1;
        $settings = QuoteSetting::where('installed_carrier_id', $carrierId)->first();
        return response()->json(['error' => false, 'data' => $settings, 'debug' => $request->all()], 200);
    }

    public function getCarrierServices(Request $request)
    {
        $carrierProviders = new AdditionalCarrierTabSettingController(); 
        $carrierId = $request->carrierId ?? 1;
        $request->installed_carrier_id = $carrierId;
        $isLTL = $request->isLTL ?? false;
        
        if ($isLTL == 1){
            $carrierServices = AdditionalCarrierTabSetting::where('installed_carrier_id', $carrierId)->first() ?? [];
            $carrierServicesArray = json_decode($carrierServices->value, true);
            $carrierProviders = $carrierProviders->index($request);
            $services = json_decode(json_encode($carrierProviders))->original->data;

            foreach($services as $service){
                if(in_array($service->speed_freight_carrierSCAC, $carrierServicesArray)){
                    $data[] = $service;
                }
            }

        } else {
            $settings = QuoteSetting::where('installed_carrier_id', $carrierId)->first();
            $data = json_decode($settings->value)->carrier_services;
        }
        
        return response()->json(['error' => false, 'data' =>$data, 'debug' => $request->all()], 200);
    }

    public function saveSettings(Request $request)
    {
        $quoteSettings = QuoteSetting::firstOrNew(['installed_carrier_id' => $request->carrierId]);
        $quoteSettings->installed_carrier_id = $request->carrierId;
        $quoteSettings->value = json_encode($request->all());
        $quoteSettings->save();
        return response()->json(['error' => false, 'message' => 'Quote settings have been saved successfully.', 'data' => $quoteSettings]);
    }

    public function saveThresholdSettings(Request $request)
    {
        $WeightThresholdSettings = WeightThresholdSettings::firstOrNew(['store_id' => $request->store_id]);
        $WeightThresholdSettings->store_id = $request->store_id;
        $WeightThresholdSettings->parcel_rates = $request->parcel_rates ?? 1;
        $WeightThresholdSettings->save();
        return response()->json(['error' => false, 'data' => $WeightThresholdSettings]);
    }

    public function getThresholdSettings(Request $request)
    {
        $WeightThresholdSettings = WeightThresholdSettings::where('store_id', $request->store_id)->first();
        $parcel = ['parcel_rates' => 1];
        $WeightThresholdSettings = empty($WeightThresholdSettings) ? $parcel : $WeightThresholdSettings;
        return response()->json(['error' => false, 'data' => $WeightThresholdSettings, 'debug' => $request->all()], 200);
    }

}
