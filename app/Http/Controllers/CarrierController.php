<?php

namespace App\Http\Controllers;

use App\Constants\Constant;
use App\Models\Carrier;
use App\Models\Connection;
use App\Models\InstalledCarrier;
use App\Models\AdditionalCarrierTabSetting;
use App\Models\CarrierServices;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Subscription\SubscriptionController;
use App\Models\DBSC\DbscOtherSettings;
use App\Models\DBSC\DbscShippingProfile;

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

    public function getCarriersByType($type = '')
    {
        $carriers = optional(Carrier::where('carrier_type', $type)->get()->pluck('slug'))->toArray() ?? [];
        return $carriers;
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
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Carrier $carrier
     * @return \Illuminate\Http\Response
     */
    public function show(Carrier $carrier)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param \App\Carrier $carrier
     * @return \Illuminate\Http\Response
     */
    public function edit(Carrier $carrier)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Carrier $carrier
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Carrier $carrier)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Carrier $carrier
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
        $subscirption = new SubscriptionController();
        $changeCount = ['store_id' => $request['store_id'], 'action' => 1];
        $res = $subscirption->changeCarrierCount($changeCount);
        if ($res['error']) {
            return response()->json([
                'error' => true,
                'message' => $res['message'],
            ], 200);
        }
        $store_id = $request->store_id;

        if (empty($request->carrier_id)) {
            return response()->json([
                'error' => true,
                'message' => 'Empty Carrier ID',
            ], 200);
        }

        $carrier = Carrier::find($request->carrier_id);

        if ($carrier && $carrier->status === 1) {
            $installCarrier = new InstalledCarrier();
            $installCarrier->store_id = $store_id;
            $installCarrier->carrier_id = $request->carrier_id;
            $installCarrier->is_enabled = true;
            $installCarrier->installed_at = now();
            $installCarrier->plan_updated_at = now();
            $installCarrier->save();

            if ($carrier->slug == 'dbsc') {

                $otherSettings = DbscOtherSettings::create();
                $generalProfile = DbscShippingProfile::create(['p_nickname' => "General Profile",
                    'store_id' => $request->store_id, 'is_general_profile' => 1,
                    'allow_all_classes' => 1
                ]);

            }

            $install_carrier = InstalledCarrier::find($installCarrier->id);

            if ($carrier->slug == "ltl-quotes" || $carrier->slug == "freightquote-ltl" || $carrier->slug == "tql-ltl" || $carrier->slug == "echo-ltl" || $carrier->slug == "freightquote-chr-ltl") {

                $services = CarrierServices::where("app_id", $carrier->id)->pluck("speed_freight_carrierSCAC")->all();
                $checked = $this->CheckedAllServices($installCarrier->id, $services, $request);

            } else if ($carrier->slug == "gtz-ltl") {

                $GTZ = CarrierServices::join('installed_carriers', 'installed_carriers.carrier_id', '=', 'app_id')
                    ->where('installed_carriers.id', $install_carrier->id)
                    ->whereNull('shopify_freights.store_id')
                    ->orderBy('speed_freight_carrierName')->pluck("speed_freight_carrierSCAC")->all();

                $CRS = CarrierServices::join('installed_carriers', 'installed_carriers.carrier_id', '=', 'app_id')
                    ->where('installed_carriers.id', $install_carrier->id)
                    ->where('shopify_freights.store_id', $store_id)
                    ->orderBy('speed_freight_carrierSCAC')->pluck("speed_freight_carrierName")->all();

                $NEWAPI = CarrierServices::where('app_id', 1)->orderBy('speed_freight_carrierSCAC')->pluck("speed_freight_carrierName")->all();

                $services = array(
                    "GTZ" => $GTZ,
                    "CRS" => $CRS,
                    'NEWAPI' => $NEWAPI,
                );

                $checked = $this->CheckedAllServices($installCarrier->id, $services, $request);
            }

            $uspsSmall = 'usps-small';
            $shipEngineSlug = 'ups-ship-engine';
            if ($carrier->slug === $uspsSmall || $carrier->slug === $shipEngineSlug) {
                $con = Connection::firstOrNew(['installed_carrier_id' => $installCarrier->id]);

                $request['carrier_id'] = $installCarrier->id;
                $request['carrierId'] = $installCarrier->id;
                $request['testType'] = false;
                $request['installed_carrier_id'] = $installCarrier->id;
                $con->value = json_encode($request->all());
                $con->installed_carrier_id = $installCarrier->id;

                $con->save();
            }

            return response()->json(['error' => false,
                'data' => InstalledCarrier::find($installCarrier->id),
                'services' => $checked ?? null,
                'message' => 'Carrier Installed Successfully',
            ], 200);
        }

        return response()->json(['error' => true,
            'data' => [],
            'message' => "Carrier is not available at the moment",
        ], 200);
    }

    public function CheckedAllServices($installCarrier, $services, $request)
    {
        $install_carrier = InstalledCarrier::find($installCarrier);
        $settings = AdditionalCarrierTabSetting::firstOrNew(['installed_carrier_id' => $install_carrier->id, 'store_id' => $request->store_id]);
        $settings->installed_carrier_id = $install_carrier->id;
        $settings->store_id = $request->store_id;
        $settings->value = json_encode($services);
        $settings->save();
        return ("All Carrier Services Set to Checked");

    }

    public function getInstalledCarriers(Request $request)
    {
        $store_id = $request->store_id;

        $installedCarriers = Carrier::select('carriers.name', 'installed_carriers.id', 'carriers.logo', 'installed_carriers.carrier_id', 'carriers.carrier_type', 'carriers.slug', 'installed_carriers.is_enabled')
            ->join('installed_carriers', 'installed_carriers.carrier_id', '=', 'carriers.id')
            ->where('installed_carriers.store_id', $store_id)->get();

        if ($installedCarriers->isEmpty()) {
            return response()->json(['error' => false,
                'data' => [],
                'message' => 'No Installed Carriers Found',
            ], 200);
        }


        $response['error'] = false;
        $response['data']['installedCarriers'] = $installedCarriers;

        return response()->json($response, 200);
    }

    public function getRecommendedCarriers(Request $request)
    {
        $store_id = $request->store_id;

        $installedCarriers = DB::table('installed_carriers')->join('stores', 'stores.id', '=', 'installed_carriers.store_id')->where('stores.id', $store_id)->pluck('carrier_id');

        if ($installedCarriers->isEmpty()) {
            $carriers = Carrier::get();

            $response['error'] = false;
            $response['data']['carriers'] = $carriers;

            return response()->json($response, 200);
        }

        $recommendedCarriers = Carrier::whereNotIn('id', $installedCarriers)->get();

        $response['error'] = false;
        $response['data']['carriers'] = $recommendedCarriers;

        return response()->json($response, 200);
    }

    public function changeCarrierStatus(Request $request)
    {
        $installedCarrier = InstalledCarrier::where('id', $request->carrier_id)->first();
        if ($installedCarrier->is_enabled == false) {
            $subscirption = new SubscriptionController();
            $changeCount = ['store_id' => $request['store_id'], 'action' => 1];
            $res = $subscirption->changeCarrierCount($changeCount);
            if ($res['error']) {
                return response()->json([
                    'error' => true,
                    'message' => $res['message'],
                ], 200);
            }
        } else {
            $subscirption = new SubscriptionController();
            $changeCount = ['store_id' => $request['store_id'], 'action' => 0];
            $subscirption->changeCarrierCount($changeCount);
        }
        $carrier = InstalledCarrier::find($request->carrier_id);

        if ($carrier) {
            $enabled = !$carrier->is_enabled;
            InstalledCarrier::where('id', $request->carrier_id)->update(['is_enabled' => $enabled]);
            /*Updating Carrier INstallation on FDO side and Address Validation Side*/
            FDOController::updateProviderCoupon($enabled, $carrier->id, $request['store_id']);
            $carrier = InstalledCarrier::find($request->carrier_id);
            return response()->json(['error' => false, 'data' => $carrier, 'message' => 'Carrier Status updated'], 200);
        } else {
            return response()->json([
                'error' => true,
                'message' => 'Invalid Carrier ID',
            ], 404);
        }
    }

    public function getInstalledCarrierPlanInfo(Request $request)
    {
        if (empty($request->carrierId)) {
            return response()->json([
                'error' => false,
                'message' => 'No Carrier Id found',
            ]);
        }

        $connection_settings = Connection::where('installed_carrier_id', $request->carrierId)->first();

        if (!$connection_settings) {
            return response()->json([
                'error' => false,
                'message' => 'No connection settings found against this Carrier Id',
            ]);
        }

        $license_key = json_decode($connection_settings->value)->license_key;
        $store = Store::find($request->store_id);

        if (empty($store) || !$store) {
            return response()->json([
                'error' => false,
                'message' => 'No Store found',
            ]);
        }

        if ($license_key && $store) {
            $query = array(
                'platform' => '',
                'carrier' => $this->getCarrierForPlanInfoRequest($request->carrierId), // required wwltl -> 1, wweSmall -> 2
                'store_url' => $store->url, // required store url
                'license_key' => $license_key, //required license key
                'webhook_url' => '',
                'plugin_version' => '',
            );

            $query = http_build_query($query);
            $end_point = Constant::PLAN_URL . '?' . $query;
            $res = (array)json_decode(file_get_contents($end_point));
// Means that it is trial plan
            if ($res['plan_type'] == 1 && $res['pakg_group'] == '' && $res['message'] == 'Subscription Not Found.') {
                $response['plan_type'] = 0;
                $response['expiry_date'] = '';

                return response()->json([
                    'error' => false,
                    'data' => $response,
                ], 200);
            }

            $response['expiry_date'] = $res['expiry_date'];
            $response['plan_type'] = $res['pakg_group'];

            /*
             * trial -> 0, basic -> 1, standard -> 2, advaced -> 3
             * */
            if ($res['pakg_group'] == 1 && $res['pakg_level'] == 1) {
                $response['plan_type'] = 0;
            }

            if ($res['pakg_group'] == 1 && $res['pakg_level'] != 1) {
                $response['plan_type'] = 1;
            }

            return response()->json([
                'error' => false,
                'data' => $response,
            ], 200);
        }

    }

    public function getCarrierForPlanInfoRequest($installedCarrierId)
    {
        $slug = InstalledCarrier::where('installed_carriers.id', $installedCarrierId)
            ->join('carriers', 'carriers.id', '=', 'installed_carriers.carrier_id')
            ->select('carriers.slug')->first();
        if (!isset($slug->slug)) {
            return 0;
        }
        $slug = $slug->slug;
        switch ($slug) {
            case 'ltl-quotes':
                return 1;
                break;
            case 'small-package':
                return 2;
                break;
            default:
                return 0;
                break;
        }
    }
}
