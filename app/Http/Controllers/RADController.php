<?php

namespace App\Http\Controllers;

use App\Constants\Constant;
use App\CurlRequest;
use App\Models\AddonSettings;
use App\Models\Connection;
use App\Models\InstalledCarrier;
use Illuminate\Http\Request;

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
        if (empty($request->addon_id)) {
            return response()->json([
                'error' => true,
                'data' => [],
                'message' => 'Empty Addon Id',
            ], 200);
        }

        $installed_addon_settings = AddonSettings::firstOrNew(['installed_addon_id' => $request->addon_id]);

        if ($installed_addon_settings) {
            $installed_addon_settings->value = json_encode($request->address);
            $installed_addon_settings->installed_addon_id = $request->addon_id;
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

}
