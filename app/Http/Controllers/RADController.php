<?php

namespace App\Http\Controllers;

use App\Constants\Constant;
use App\CurlRequest;
use App\Models\AddonSettings;
use App\Models\Connection;
use App\Models\InstalledCarrier;
use Illuminate\Http\Request;
use App\Models\ResidentialSetting;
use App\Models\InstalledAddon;
use Illuminate\Support\Facades\Log;

class RADController extends Controller
{
    private $curlRequest;

    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
    }

    public function getPlans(Request $request)
    {
        $data = $this->runRADAction($request, 's');

        if ($data['error']) {
            $response = $data['response'];
        } else {
            $responseError = isset($data['response']->severity) && $data['response']->severity == 'ERROR';

            $response = [
                'data' => [
                    'plans' => $data['response']->ListOfPackages->Info ?? [],
                    'current_plan' => $responseError ? $data['response']->Message : $data['response'],
                ],
                'error' => false,
            ];
        }

        return response()->json($response, $data['status']);
    }

    public function changePlan(Request $request)
    {
        if (!$request->selected_plan || empty($request->selected_plan)) {
            return response()->json([
                'error' => false,
                'message' => 'No plan is selected',
            ], 200);
        }

        $data = $this->runRADAction($request, $request->selected_plan == 'disable' ? 'd' : 'c');

        if ($data['error']) {
            $response = $data['response'];
        } else {
            $response = [
                'data' => $data['response'] ?? [],
                'error' => false,
                'message' => 'Your plan has been changed successfully.',
            ];
        }

        return response()->json($response, $data['status']);
    }

    public function getCurrentPlan(Request $request)
    {
        $data = $this->runRADAction($request, 's');
        if ($data['error']) {
            $response = $data['response'];
        } else {
            $response = [
                'data' => $data['response'] ?? [],
                'error' => false,
            ];
        }
        return response()->json($response, $data['status']);
    }

    public function changeStatus(Request $request)
    {

    }

    public function getDefaultAddress(Request $request)
    {
        if (empty($request->addon_id)) {
            return response()->json([
                'error' => true,
                'data' => [],
                'message' => 'Empty Addon Id',
            ], 200);
        }

        $add_settings = AddonSettings::where(['installed_addon_id' => $request->addon_id])->first();

        if ($add_settings) {
            return response()->json([
                'error' => false,
                'data' => $add_settings,
                'message' => '',
            ], 200);

        } else {
            return response()->json([
                'error' => true,
                'data' => [],
                'message' => 'Invalid Addon Id',
            ], 404);
        }
    }

    public function setDefaultAddress(Request $request)
    {
        $installed_addon = InstalledAddon::join('addons', 'addons.id', 'installed_addons.addon_id')
                ->where(['installed_addons.store_id' => $request->store_id,
                    'installed_addons.is_enabled' => 1,
                    'addons.short_code' => 'RAD',
                ])->select('installed_addons.id')->first();

        if (empty($installed_addon->id)) {
            return false;
        }

        $address = ['unconfirmed_default' => $request->settings['unconfirmed_address_type']];
        $installed_addon_settings = AddonSettings::firstOrNew(['installed_addon_id' => $installed_addon->id]);

        if ($installed_addon_settings) {
            $installed_addon_settings->value = json_encode($address);
            $installed_addon_settings->installed_addon_id = $installed_addon->id;
            $installed_addon_settings->save();

            return response()->json(["error" => false, 'message' => "Default Unconfirmed Address has been updated.", "data" => $installed_addon_settings]);

        } else {
            return response()->json([
                'error' => true,
                'data' => [],
                'message' => 'Invalid Addon Id',
            ], 404);
        }
    }

    public function runRADAction($request, $action)
    {
        $message = 'No store found!';
        $storeId = $request->store_id ?? null;

        if ($storeId !== null) {
            $getInstalledCarriers = InstalledCarrier::where(['store_id' => $storeId, 'is_enabled' => 1])->first();

            if (!empty($getInstalledCarriers)) {
                $connectionSettings = Connection::where('installed_carrier_id', $getInstalledCarriers->id)->first();

                if (!empty($connectionSettings)) {
                    $settings = json_decode($connectionSettings->value);
                    $requestData = [
                        'platform' => 'bigcommerce',
                        'request_key' => 'fowpejopeojpwefwekashdkasd',
                        'action' => $action,
                        'package' => !$request->selected_plan || $request->selected_plan == 'disable' ? '' : $request->selected_plan,
                        'licenseKey' => $settings->license_key,
                        'serverName' => $request->store_name,
                    ];

                    $response = $this->curlRequest->sendPostRequest(Constant::RAD_PLAN_URL, $requestData);

                    return [
                        'response' => $response,
                        'status' => 200,
                        'error' => false,
                    ];
                } else {
                    $message = 'No connection settings available!';
                }
            } else {
                $message = 'No carrier enabled!';
            }
        }
        return [
            'response' => [
                'data' => [],
                'error' => true,
                'message' => $message,
            ],
            'status' => 200,
            'error' => true,
        ];
    }
    public function saveSettings(Request $request)
    {
        if (empty($request->store_id)) {
            return response()->json([
                'error' => true,
                'data' => [],
                'message' => 'Empty Store Id',
            ], 200);
        }

        $resi_settings = ResidentialSetting::firstOrNew(['store_id' => $request->store_id]);

        if ($resi_settings) {
            $resi_settings->store_id = $request->store_id;
            $resi_settings->settings = json_encode($request->settings);
            $resi_settings->save();
            if($request->settings['residential_delivery_auto_detect']){
                $resp = $this->setDefaultAddress($request);
                if(!$resp){
                    return response()->json([
                        'error' => true,
                        'data' => [],
                        'message' => 'No Add-on is Installed/Enabled',
                    ], 200);
                }
            }

            return response()->json(["error" => false, 'message' => "Address type settings saved successfully.", "data" => $resi_settings]);

        } else {
            return response()->json([
                'error' => true,
                'data' => [],
                'message' => 'Invalid store Id',
            ], 404);
        }
    }

    public function getSettings(Request $request)
    {Log::info('1 getSettings' . json_encode($request->all()));
        try {
            if (empty($request->store_id)) {
                return response()->json([
                    'error' => true,
                    'data' => [],
                    'message' => 'Empty Store Id',
                ], 200);
            }
    
            $resi_settings = ResidentialSetting::where(['store_id' => $request->store_id])->first();
            Log::info('2 getSettings' . json_encode($resi_settings));
            if (!empty($resi_settings)) {
    
                return response()->json(["error" => false, "data" => $resi_settings]);
    
            } else {
                return response()->json([
                    'error' => false,
                    'data' => [],
                    'message' => 'Invalid store Id',
                ], 200);
            }
        } catch (\Exception $error) {
            Log::info(' 3 getSettings'.json_encode($error->getMessage()));
            return response()->json([
                'error' => false,
                'data' => $error,
                'message' => $error->getMessage(),
            ], 200);
        }
        
    }

}
