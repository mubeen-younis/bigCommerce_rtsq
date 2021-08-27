<?php

namespace App\Http\Controllers;

use App\Constants\Constant;
use App\CurlRequest;
use App\Models\Connection;
use App\Models\InstalledCarrier;
use Illuminate\Http\Request;

class SBSController extends Controller
{
    private $curlRequest;

    public function __construct()
    {
        $this->curlRequest = new CurlRequest();
    }

    public function getPlans(Request $request)
    {
        $data = $this->runSBSAction($request, 's');

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

        $data = $this->runSBSAction($request, $request->selected_plan == 'disable' ? 'd' : 'c');

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
        $data = $this->runSBSAction($request, 's');
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

    public function runSBSAction($request, $action)
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
                        'request_key' => 'e48dc18afwewb49ca2a48cb92b8ff',
                        'action' => $action,
                        'package' => !$request->selected_plan || $request->selected_plan == 'disable' ? '' : $request->selected_plan,
                        'license_key' => $settings->license_key,
                        'domain_name' => 'store-uann2u.mybigcommerce.com',
                        'serverName' => $request->store_name,
                    ];

                    $response = $this->curlRequest->sendPostRequest(Constant::SBS_PLAN_URL, json_encode($requestData));

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
