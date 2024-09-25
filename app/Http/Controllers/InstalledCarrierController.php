<?php

namespace App\Http\Controllers;

use App\Models\Carrier;
use App\Models\InstalledCarrier;
use Illuminate\Http\Request;
use App\Models\Store;
use App\Models\Connection;
use App\Models\QuoteSetting;

class InstalledCarrierController extends Controller
{
    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function installCarrier(Request $request)
    {
        try {
            $installCarrier = new InstalledCarrier();
            $installCarrier->store_id = $request->store_id;
            //$installCarrier->carrier_plan_id = $request->carrier_id;
            $installCarrier->is_enabled = $request->is_enabled;
            $installCarrier->installed_at = now();
            $installCarrier->plan_updated_at = now();
            $installCarrier->save();
            return response()->json(['error' => false,
                'data' => $installCarrier->id,
                'message' => 'Carrier Installed Successfully',
            ], 200);
        } catch (\Exception $exception) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getInstalledCarriers(Request $request)
    {
        $store = $request->store;

        $installedCarriers = Carrier::select("*")->join('installed_carriers', 'installed_carriers.carrier_id', '<>', 'carriers.id')->join('stores', 'store.id', '=', 'installed_carriers.store_id')
            ->where('stores.hash', $store)
            ->get();

        if ($installedCarriers->isEmpty()) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Installed Carriers Found',
            ], 404);
        }

        return response()->json(['error' => false,
            'data' => $installedCarriers,
            'message' => '',
        ], 200);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateCarrier(Request $request)
    {
        if (empty($request->carrier_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Carrier Id Not Exists',
            ], 404);
        }
        if (empty($request->is_enabled)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Request Not Properly Formatted',
            ], 404);
        }
        if (InstalledCarrier::where('id', $request->carrier_id)->exists()) {
            InstalledCarrier::where('id', $request->carrier_id)->update(['is_enabled' => $request->is_enabled]);
            return response()->json(['error' => false,
                'data' => [],
                'message' => 'Carrier Updated Successfully',
            ], 200);
        } else {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Carrier exists against this Id',
            ], 404);
        }
    }
    // installed carrier on all existing stores
    public function installCarrierAllStores(Request $request)
    {
        try {
            $stores = Store::getAllStoreDetails();
            $data = [];

            if(!empty($stores)){
                foreach($stores as $store){
                    // install Carrier
                    $carrier = optional(Carrier::where('slug', $request->carrier_slug)->first())->toArray() ?? [];

                    if (!empty($carrier) && $carrier['status'] === 1) {
                
                        $installCarrier = InstalledCarrier::firstOrNew(['store_id' => $store['id'], 'carrier_id' => $carrier['id']]);
                        if(!empty($installCarrier->store_id) && !empty($installCarrier->carrier_id)){
                            continue;
                        }
                        $installCarrier->store_id = $store['id'];
                        $installCarrier->carrier_id = $carrier['id'];
                        $installCarrier->is_enabled = false;
                        $installCarrier->installed_at = now();
                        $installCarrier->plan_updated_at = now();
                        $installCarrier->save();
            
                        if ($carrier['slug'] == 'dbsc') {
            
                            $otherSettings = DbscOtherSettings::create();
                            $generalProfile = DbscShippingProfile::create(['p_nickname' => "General Profile",
                                'store_id' => $store['id'], 'is_general_profile' => 1,
                                'allow_all_classes' => 1
                            ]);
            
                        }
            
                        $install_carrier = InstalledCarrier::find($installCarrier->id);
            
                        if ($carrier['slug'] == "ltl-quotes" || $carrier['slug'] == 'unishipper-ltl' || $carrier['slug'] == "freightquote-ltl" || $carrier['slug'] == "tql-ltl" || $carrier['slug'] == "echo-ltl" || $carrier['slug'] == "freightquote-chr-ltl" || $carrier['slug'] == 'priority-one-ltl') {
            
                            $services = CarrierServices::where("app_id", $carrier['id'])->pluck("speed_freight_carrierSCAC")->all();
                            $checked = $this->CheckedAllServices($installCarrier->id, $services, $store['id']);
            
                        } else if ($carrier['slug'] == "gtz-ltl") {
            
                            $GTZ = CarrierServices::join('installed_carriers', 'installed_carriers.carrier_id', '=', 'app_id')
                                ->where('installed_carriers.id', $install_carrier->id)
                                ->whereNull('shopify_freights.store_id')
                                ->orderBy('speed_freight_carrierName')->pluck("speed_freight_carrierSCAC")->all();
            
                            $CRS = CarrierServices::join('installed_carriers', 'installed_carriers.carrier_id', '=', 'app_id')
                                ->where('installed_carriers.id', $install_carrier->id)
                                ->where('shopify_freights.store_id', 1)
                                ->orderBy('speed_freight_carrierSCAC')->pluck("speed_freight_carrierName")->all();
            
                            $NEWAPI = CarrierServices::where('app_id', 1)->orderBy('speed_freight_carrierName')->pluck("speed_freight_carrierSCAC")->all();
            
                            $services = array(
                                "GTZ" => $GTZ,
                                "CRS" => $CRS,
                                'NEWAPI' => $NEWAPI,
                            );
            
                            $checked = $this->CheckedAllServices($installCarrier->id, $services, $store['id']);
                        }
            
                        $uspsSmall = 'usps-small';
                        $shipEngineSlug = 'ups-ship-engine';
                        $settings = [];
                        if ($carrier['slug'] === $uspsSmall || $carrier['slug'] === $shipEngineSlug) {
                            $con = Connection::firstOrNew(['installed_carrier_id' => $installCarrier->id]);
            
                            $settings['carrier_id'] = $installCarrier->id;
                            $settings['carrierId'] = $installCarrier->id;
                            $settings['testType'] = false;
                            $settings['installed_carrier_id'] = $installCarrier->id;
                            $settings['store_id'] = $store['id'];
                            $settings['store_name'] = $storeName;
                            $settings['store_hash'] = $storeHash;
                            $isTestStore = Helpers::checkIsTestStore($storeHash);
                            $settings['is_test_store'] = $isTestStore;
                            $con->value = json_encode($settings);
                            $con->installed_carrier_id = $installCarrier->id;
            
                            $con->save();
                        }
                    }
                }

                return response()->json(['error' => false,
                    'data' => $data,
                    'error' => false,
                    'message' => 'Carriers and Add-ons Installed Successfully',
                ], 200);
            }
        } catch (\Exception $exception) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => $exception->getMessage(),
            ], 200);
        }
    }      

    // unInstall carrier on all existing stores
    public function unInstallCarrierAllStores(Request $request)
    {
        try {
            $stores = Store::getAllStoreDetails();
            $data = [];

            if(!empty($stores)){
                foreach($stores as $store){
                    // Get Carrier
                    $carrier = optional(Carrier::where('slug', $request->carrier_slug)->first()) ?? [];

                    if (!empty($carrier)) {
                        // Get installed carrier details
                        $installCarrier = InstalledCarrier::where(['store_id' => $store['id'], 'carrier_id' => $carrier->id])->first();
                        if(empty($installCarrier->store_id) && empty($installCarrier->carrier_id)){
                            continue;
                        }

                        $connectionSettings = Connection::where('installed_carrier_id', $installCarrier->id)->first();
                        $quoteSettings = QuoteSetting::where('installed_carrier_id', $installCarrier->id)->first();
                        // remove connection settings from DB
                        if(!empty($connectionSettings)){
                            $connectionSettings->delete();
                        }
                        // remove quote settings from DB
                        if(!empty($quoteSettings)){
                            $quoteSettings->delete();
                        }
                        // remove carrier from DB installed carrier list
                        $installCarrier->delete();
                        $carrier->update([
                            'status' => 0,
                        ]);
                    }
                }

                return response()->json(['error' => false,
                    'data' => $data,
                    'error' => false,
                    'message' => 'Carrier UnInstalled Successfully',
                ], 200);
            }
        } catch (\Exception $exception) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => $exception->getMessage(),
            ], 200);
        }
    }  
}
