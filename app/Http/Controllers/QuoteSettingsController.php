<?php

namespace App\Http\Controllers;

use App\Models\QuoteSetting;
use App\Models\WeightThresholdSettings;
use App\Models\InstalledCarrier;
use Illuminate\Http\Request;
use App\Http\Controllers\AdditionalCarrierTabSettingController;
use App\Models\AdditionalCarrierTabSetting;
use App\Models\Connection;
use App\CustomClasses\Functions;

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
        $carrierSlug = $request->carrierSlug ?? '';
        $request->installed_carrier_id = $carrierId;
        $isLTL = $request->isLTL ?? false;
        $data = [];
        
        if ($isLTL == 1){
            
            if(Functions::is3plCarrier($carrierSlug)){
                $carrierServices = AdditionalCarrierTabSetting::where('installed_carrier_id', $carrierId)->first() ?? [];
                $carrierServicesArray = !empty($carrierServices) ? json_decode($carrierServices->value, true) : [];

                if ($carrierSlug == 'gtz-ltl'){
                    $connectionSettings = Connection::join('installed_carriers', 'installed_carriers.id', 'connection_settings.installed_carrier_id')
                    ->join('carriers', 'carriers.id', 'installed_carriers.carrier_id')
                    ->select('carriers.slug', 'connection_settings.id', 'connection_settings.installed_carrier_id',
                        'connection_settings.value')
                    ->where('connection_settings.installed_carrier_id', $carrierId)->first();
                    if ($connectionSettings !== null) {
                        $settings = json_decode($connectionSettings->value, true);
                    }
                
                    $request->carrier_type = $settings['api_type'] ?? '';
                    $carrierServicesArray = $carrierServicesArray[$settings['api_type']] ?? [];
                }
        
            
                $carrierProviders = $carrierProviders->index($request);
                $services = json_decode(json_encode($carrierProviders))->original->data ?? [];

                foreach($services as $key => $service){
                    if(in_array($service->speed_freight_carrierSCAC, $carrierServicesArray)){
                        $data[$key]['key'] = $service->speed_freight_carrierSCAC;
                        $data[$key]['value'] = $service->speed_freight_carrierName;
                    }
                }
            } else if($carrierSlug == 'fedex-ltl') {
                $settings = QuoteSetting::where('installed_carrier_id', $carrierId)->first();
                $service = isset($settings->value) ? json_decode($settings->value) : [];
                if ($service->fedex_freight_economy){
                    $data[] = ['key' => 'fedex_freight_economy', 'value' => ucwords(str_replace('_', ' ' , 'fedex_freight_economy'))];
                } 
                if ($service->fedex_freight_priority){
                    $data[] = ['key' => 'fedex_freight_priority', 'value' => ucwords(str_replace('_', ' ' , 'fedex_freight_priority'))];
                }
                
            } else if($carrierSlug == 'dayross-ltl') {
                $connectionSettings = Connection::join('installed_carriers', 'installed_carriers.id', 'connection_settings.installed_carrier_id')
                    ->join('carriers', 'carriers.id', 'installed_carriers.carrier_id')
                    ->select('carriers.slug', 'connection_settings.id', 'connection_settings.installed_carrier_id',
                        'connection_settings.value')
                    ->where('connection_settings.installed_carrier_id', $carrierId)->first();
                if ($connectionSettings !== null) {
                    $settings = json_decode($connectionSettings->value, true);

                    if($settings['api_type'] == 'sameday'){
                        $settings = QuoteSetting::where('installed_carrier_id', $carrierId)->first();
                        $services = isset($settings->value) ? json_decode($settings->value)->carrier_services : [];
                        foreach($services as $key => $service){
                            if($service == true && strpos($key, 'markup') == false){
                                $data[] = ['key' => $key, 'value' => ucwords(str_replace('_', ' ' , $key))];
                            }
                        }
                    } else {
                        $data[] = ['key' => $carrierSlug, 'value' => ucwords(str_replace('-', ' ' , $carrierSlug)) . ' ' .ucwords(str_replace('_', ' ' , $settings['api_type']))];
                    }
                } 

            } else if($carrierSlug == 'rl-ltl') {
                $settings = QuoteSetting::where('installed_carrier_id', $carrierId)->first();
                $service = isset($settings->value) ? json_decode($settings->value) : [];
                if ($service->standard_service){
                    $data[] = ['key' => 'standard_service', 'value' => ucwords(str_replace('_', ' ' , 'standard_service'))];
                }
                if ($service->guaranteed_pm){
                    $data[] = ['key' => 'guaranteed_pm', 'value' => ucwords(str_replace('_', ' ' , 'guaranteed_pm'))];
                }
                if ($service->guaranteed_am){
                    $data[] = ['key' => 'guaranteed_am', 'value' => ucwords(str_replace('_', ' ' , 'guaranteed_am'))];
                }
                if ($service->guaranteed_hourly_window){
                    $data[] = ['key' => 'guaranteed_hourly_window', 'value' => ucwords(str_replace('_', ' ' , 'guaranteed_hourly_window'))];
                }
            } else {
                $data[] = ['key' => $carrierSlug, 'value' => ucwords(str_replace('-', ' ' , $carrierSlug))];
            }

        } else {
            $settings = QuoteSetting::where('installed_carrier_id', $carrierId)->first();
            $services = isset($settings->value) ? json_decode($settings->value)->carrier_services : [];
            foreach($services as $key => $service){
                if($service == true && strpos($key, 'markup') == false){
                    $data[] = ['key' => $key, 'value' => ucwords(str_replace('_', ' ' , $key))];
                }
            }
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
