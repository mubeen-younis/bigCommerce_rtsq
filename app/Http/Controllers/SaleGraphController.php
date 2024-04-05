<?php

namespace App\Http\Controllers;

use App\Constants\Constant;
use App\CustomClasses\CurlRequest;
use App\Models\Subscription\Subscription;
use Illuminate\Support\Facades\Log;

class SaleGraphController extends Controller
{

    /**
     * @return void
     */
    public static function updateGraphData(): void
    {
        try {
            $url = Constant::GRAPH_UPDATE_DATA;
            $activeStoresCount = Subscription::where('status', 1)->where('plan_id', '>', 1)->where('is_test_subscription', 0)->get()->count();
            $totalRevenue = Subscription::where('status', 1)->where('plan_id', '>', 1)->where('is_test_subscription', 0)->sum('amount_charged');
            $request = self::getRequest($activeStoresCount, $totalRevenue);
            $curlResponse = (new CurlRequest())->enSingleCurlRequest($url, $request, [], 'POST');
            Log::info('Graph Cron executed successfully ' . json_encode($curlResponse));
        } catch (\Exception|\Throwable $exception) {
            Log::info('Graph Update Exception ' . json_encode($exception->getMessage()));

        }
    }


    /**
     * @param $activeStores
     * @param $totalRevenue
     * @return string
     */
    public static function getRequest($activeStores, $totalRevenue): string
    {
        $requestArray = [
            'platform' => 'bigcommerce',
            'licenseKey' => 'V1T9ZEOG-RTSQAPPS-01MMZZ3W-O0TOJAQG',
            'totalInstallCount' => $activeStores, // total active apps count
            'totalRevenue' => $totalRevenue
        ];

        return http_build_query($requestArray);
    }
}
