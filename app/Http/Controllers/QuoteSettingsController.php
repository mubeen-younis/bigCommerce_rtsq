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
use App\Models\Carrier;

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
        
        $carrierName = optional(Carrier::where('slug', $carrierSlug)->first())->toArray() ?? [];

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
                    $request->store_id = $settings['store_id'];
                    $carrierServicesArray = $carrierServicesArray[$settings['api_type']] ?? [];
                }
        
            
                $carrierProviders = $carrierProviders->index($request);
                $services = json_decode(json_encode($carrierProviders))->original->data ?? [];

                foreach($services as $key => $service){
                    if(in_array($service->speed_freight_carrierName, $carrierServicesArray) && $request->carrier_type == 'CRS'){
                        $data[] = ['key' => $service->speed_freight_carrierName, 'value' => $service->speed_freight_carrierSCAC];
                    } else if(in_array($service->speed_freight_carrierSCAC, $carrierServicesArray)){
                        $data[] = ['key' => $service->speed_freight_carrierSCAC, 'value' => $service->speed_freight_carrierName];
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
                        
                        $value = json_decode($settings->value);
                        $services->deliver_to_threshold = $value->deliver_to_threshold;
                        $services->deliver_to_room_of_choice = $value->deliver_to_room_of_choice;
                        $services->deliver_and_packaging_removal = $value->deliver_and_packaging_removal;
                        $services->deliver_to_threshold_two_man = $value->deliver_to_threshold_two_man;
                        $services->deliver_to_room_of_choice_two_man = $value->deliver_to_room_of_choice_two_man;
                        $services->deliver_and_packaging_removal_two_man = $value->deliver_and_packaging_removal_two_man;

                        foreach($services as $key => $service){
                            if($service == true && strpos($key, 'markup') == false){
                                
                                if(strpos($key, 'deliver_') !== false){
                                    $key = str_replace('and', '&' , $key);
                                    $key = str_replace('two', '- 2' , $key);
                                    $data[] = ['key' => $key, 'value' => ucfirst(str_replace('_', ' ' , $key))];
                                } else {
                                    $key = str_replace('am', 'AM' , $key);
                                    $key = str_replace('pm', 'PM' , $key);
                                    $key = str_replace('pac', 'PAC' , $key);
                                    $key = str_replace('us', 'US' , $key);
                                    $data[] = ['key' => $key, 'value' => ucwords(str_replace('_', ' ' , $key))];
                                }
                            }
                        }
                    } else {
                        if(isset($carrierName['name'])){
                            $data[] = ['key' => $carrierSlug, 'value' => $carrierName['name'] . ' LTL'];
                        }
                    }
                } 

            } else if($carrierSlug == 'rl-ltl') {
                $settings = QuoteSetting::where('installed_carrier_id', $carrierId)->first();
                $service = isset($settings->value) ? json_decode($settings->value) : [];
                if ($service->standard_service){
                    $data[] = ['key' => 'standard_service', 'value' => ucwords(str_replace('_', ' ' , 'standard_service'))];
                }
                if ($service->guaranteed_pm){
                    $data[] = ['key' => 'guaranteed_pm', 'value' => ucwords(str_replace('_pm', ' PM' , 'guaranteed_pm'))];
                }
                if ($service->guaranteed_am){
                    $data[] = ['key' => 'guaranteed_am', 'value' => ucwords(str_replace('_am', ' AM' , 'guaranteed_am'))];
                }
                if ($service->guaranteed_hourly_window){
                    $data[] = ['key' => 'guaranteed_hourly_window', 'value' => ucwords(str_replace('_', ' ' , 'guaranteed_hourly_window'))];
                }
            } else {
                if(isset($carrierName['name'])){
                    $data[] = ['key' => $carrierSlug, 'value' => $carrierName['name'] . ' LTL'];
                }
            }

        } else if($carrierSlug == 'purolator-small') {

            $settings = QuoteSetting::where('installed_carrier_id', $carrierId)->first();
            $services = isset($settings->value) ? json_decode($settings->value)->carrier_services : [];
            foreach($services as $key => $service){

                if($service == true && strpos($key, 'markup') == false){
                    $key = str_replace('__', ' ' , $key);
                    $key = str_replace('_', ' ' , $key);
                    $key = str_replace('10 30AM', '10:30 AM' , $key);
                    $key = str_replace('9AM', '9 AM' , $key);
                    $key = str_replace('us 9 am', 'US 9 AM' , $key);
                    $key = str_replace('us 10 30 am', 'US 10:30 AM' , $key);
                    $key = str_replace('us', 'US' , $key);
                    $key = str_replace('12 00', '12:00' , $key);
                    $key = ucwords($key);
                    
                    $data[] = ['key' => $key, 'value' => $key];
                }
                
            }

        } else {
            $settings = QuoteSetting::where('installed_carrier_id', $carrierId)->first();

            $services = isset($settings->value) ? json_decode($settings->value)->carrier_services : [];
            foreach($services as $key => $service){
                if($service == true && strpos($key, 'markup') == false){
                    $key = str_replace('_', ' ' , $key);
                    $key = str_replace('am', 'A.M.' , $key);
                    if($carrierSlug == 'ups-ship-engine') {
                        $key = str_replace('early A.M.', 'early' , $key);
                    }
                    $key = str_replace('ups', 'UPS' , $key);
                    $key = str_replace('usps', 'USPS' , $key);
                    $key = str_replace('flat rate', 'flat rate*' , $key);
                    $key = str_replace('flat rate* box', 'flat rate box*' , $key);
                    $key = str_replace('first class', 'First-Class' , $key);
                    $key = str_replace('2 day A.M.', '2 Day AM' , $key);
                    $key = str_replace('surepost', 'SurePost' , $key);
                    $key = str_replace('1lb', '1LB' , $key);
                    $key = ucwords($key);
                    $key = str_replace('Or Greater', 'or greater' , $key);
                    $key = str_replace('Than', 'than' , $key);
                    $key = str_replace('With', 'with' , $key);
                    $data[] = ['key' => $key, 'value' => $key];
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
