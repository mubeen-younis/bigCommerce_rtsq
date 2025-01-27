<?php

namespace App\Http\Controllers;

use App\Models\AppLog;
use Illuminate\Http\Request;
use App\Models\ProductSetting;
use App\Constants\Constant;
use App\CustomClasses\Functions;
use Illuminate\Support\Facades\Log;
use App\Models\Store;
use App\Models\PackagingDetail;
use App\CustomClasses\BigCommerceFunctions;
use App\CurlRequest;
use Carbon\Carbon;
use App\Models\EnableLog;
class LogToDbController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $storeHash = 'uann2u';
        if (!isset($request['store']) || empty($request['store']) || $request['store'] != $storeHash) {
            return response()->json([
                'error' => true,
                'data' => [],
            ]); 
        }

        $search = $request->search ?? "";
        $pageSize = $request->page_size ?? 50;
        $sortOrder = $request->sort_order ?? 'desc';
        $sortOrder = $sortOrder == 'ascend' ? 'asc' : 'desc';
        $logs = AppLog::getLogsDB($search, $pageSize, $sortOrder);
        return response()->json([
            'error' => false,
            'data' => $logs,
        ]);
    }


    public function truncateLogs(Request $request)
    {
        if ($request->has('deleteit')) {
            AppLog::truncate();
            return 'deleted';
        }

    }

    public function enableLogs(Request $request)
    {
        return EnableLog::enableAppLogs($request);
    }

    public function sendCurlRequest($url, $postData)
    {
        $fieldString = http_build_query($postData);
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fieldString);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $output = curl_exec($ch);
            curl_close($ch);
            return json_decode($output, true);
        } catch (\Throwable $e) {
            $result = [];
            Log::info('Logs exception: ' . json_encode($exception->getMessage()));
        }
        return $result;
    }

    public function getStoreLogs(Request $request)
    {
        try {
            $storeHash = $request->store_hash;
            $store = Store::where('hash', $storeHash)->first();
            if (empty($store)) {
                return [];
            }

            $page = $request->page ?? 1;
            $perPage = $request->perpage ?? 25;
            $carrierName = Functions::getCarrNameBySlug($request->carrier_slug);
            $postData = [
                'serverName' => $store['store_domain'] ?? '',
                'lastLogs' => $request->perpage ?? 25,
                'carrierName' => $carrierName ?? '',
                'dont_auth' => '1',
            ];

            if (Functions::isEnabledLogs($storeHash)) {
                Log::info('StoreLogs postData ' . json_encode($postData));
            }
    
            $logsData = $respdata = [];
            $url = Constant::LOGS_URL;
            $logsResp = $this->sendCurlRequest($url, $postData);  

            if (Functions::isEnabledLogs($storeHash)) {
                Log::info('StoreLogs output ' . json_encode($logsResp));
            }

            $storeDetails = BigCommerceFunctions::getStoreSettings($storeHash);
            $storeDetails = (new CurlRequest())->enSingleCurlRequest($storeDetails['endpoint'], $storeDetails['request'], $storeDetails['headers'], $storeDetails['method'], false);
            $response = json_decode($storeDetails['response'], true);
            $prePackageId = null;
            $count = 0;
            $key = 0;
            
            if(isset($logsResp['severity']) && $logsResp['severity'] === "SUCCESS"){
                if(isset($logsResp['data']) && !empty($logsResp['data'])){

                    $packageIds = $packagingDetails = [];
                    foreach ($logsResp['data'] as $data) {
                        $requestData = isset($data['request']) ? json_decode($data['request'], true) : [];
                        if (!empty($requestData['packaging_id'])) {
                            $packageIds[] = $requestData['packaging_id'];
                        }
                    }

                    if (!empty($packageIds)) {
                        $packagingDetails = PackagingDetail::select('is_packaging', 'packaging_uuid', 'lineitems')
                        ->whereIn('packaging_uuid', array_filter($packageIds)) // Remove empty IDs
                        ->where('store_id', $request['store_id'])
                        ->get()
                        ->toArray();
                    }

                    foreach($logsResp['data'] as $data){

                        if (Functions::isEnabledLogs($storeHash)) {
                            Log::info('StoreLogs in progress ' . date('Y-m-d H:i:s'));
                        }

                        $requestData = isset($data['request']) ? json_decode($data['request'], true) : [];
                        $packageId = isset($requestData['packaging_id']) ? $requestData['packaging_id'] : '';
                        if($prePackageId == $packageId){
                            $count++;
                        } else {
                            $count = 0;

                            $respdata = collect($packagingDetails)->filter(function ($details) use ($packageId) {
                                return $details['packaging_uuid'] == $packageId;
                            })->first() ?? [];

                            $lineitems = isset($respdata['lineitems']) ? json_decode($respdata['lineitems'], true) : [];
                            $getOriginKeys = $this->getOriginKeys($lineitems);

                            $originKeys = $getOriginKeys['originKeys'];
                            $locationIds = $getOriginKeys['locationIds'];
                        }

                        if(isset($requestData['carrier_mode']) && $requestData['carrier_mode'] === 'pro' && empty($respdata)){
                            continue;
                        }
                        if ($carrierName === 'dayross'){
                            $resp = isset($data['response']) && !empty($data['response']) ? stripslashes($data['response']) : json_encode((object) null);
                            $resp = preg_replace('/\s+/', '', $resp);
                        } else if ($carrierName === 'yrc'){
                            $resp = isset($data['response']) && !empty($data['response']) ? preg_replace('/\s+/', '', strip_tags($data['response'])) : json_encode((object) null);
                        } else { 
                            $resp = isset($data['response']) && !empty($data['response']) ? preg_replace('/\s+/', '', $data['response']) : json_encode((object) null);
                        }
                        if(!$this->isJson($resp) && $carrierName === 'FedEx Small'){
                            $resp = ['Error' => ['message' => $resp]];
                            $resp = json_encode($resp);
                        }

                        $destination = isset($lineitems['destination']) ? $lineitems['destination'] : [];

                        $logsData[$key]['location_id'] = $locationIds[$count] ?? null;
                        $logsData[$key]['packaging_id'] = $packageId;
                        $logsData[$key]['response'] = isset($data['status']) ? $data['status'] : '';

                        if (!empty($originKeys)){
                            foreach($originKeys[$locationIds[$count]] as $code){ 
                                if (isset($lineitems['items'][$code]) && !empty($lineitems['items'][$code])){

                                    $logsData[$key]['quantity'][] = isset($lineitems['items'][$code]['piecesOfLineItem']) ? $lineitems['items'][$code]['piecesOfLineItem'] : '';
                                    $logsData[$key]['dimension'][] = floatval($lineitems['items'][$code]['lineItemLength']) . ' X ' . floatval($lineitems['items'][$code]['lineItemWidth']) . ' X ' . floatval($lineitems['items'][$code]['lineItemHeight']);
                                    $logsData[$key]['Items'][] = isset($lineitems['items'][$code]['lineItemName']) ? $lineitems['items'][$code]['lineItemName'] : '';
                                }

                                if (isset($lineitems['origin'][$code]) && !empty($lineitems['origin'][$code])){
                                    
                                    $logsData[$key]['sender'] = $lineitems['origin'][$code]['senderCity'] . ', ' . $lineitems['origin'][$code]['senderState'] . ' ' . $lineitems['origin'][$code]['senderZip'] . ' ' . $lineitems['origin'][$code]['senderCountryCode'];
                                }
                            }
                        }

                        if (isset($lineitems['destination']) && !empty($lineitems['destination'])){
                            $logsData[$key]['receiver'] = $destination['city'] . ', ' . $destination['state'] . ' ' . $destination['zip'] . ' ' . $destination['country'];
                        }

                        $requestTime = isset($data['request_time']) ? $data['request_time'] : '';
                        $responseTime = isset($data['response_time']) ? $data['response_time'] : '';

                        $from = Carbon::createFromFormat('Y-m-d H:s:i', $requestTime);
                        $to = Carbon::createFromFormat('Y-m-d H:s:i', $responseTime);

                        $logsData[$key]['requestTime'] = self::getDateTime($requestTime, $response) ?? '';
                        $logsData[$key]['responseTime'] = self::getDateTime($responseTime, $response) ?? '';
                        $logsData[$key]['latency'] = $to->diffInMinutes($from) ?? '';
                        $logsData[$key]['responseData'] = $resp;
                        $logsData[$key]['is_packaging'] = isset($respdata['is_packaging']) ? $respdata['is_packaging'] : 0;
                        $prePackageId = $packageId;
                        $logsData[$key]['key'] = $key;
                        $key++;
                    }
                }
            }

            return response()->json(['error' => false,
                    'data' => $logsData,
                    'meta' => ['total' => 25, 'current' => $page, 'perpage' => $perPage],
                    'message' => '',
                ], 200);

        } catch (\Exception $exception) {
            Log::info('Exception to Get Logs: ' . json_encode([
                'line' => $exception->getLine(),
                'message' => $exception->getMessage()
            ]));
            return [];
        }
    }

    public static function getDateTime($time, $response)
    {
        $datetime = new \DateTime($time);
        $storeTimezone = isset($response['timezone']['name']) ? $response['timezone']['name'] : 'UTC'; 
        $storeTime = new \DateTimeZone($storeTimezone);
        $datetime->setTimezone($storeTime);
        $formattedTime = $datetime->format('m/d/Y H:i:s');

        return $formattedTime;
    }
    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function getOriginKeys($lineitems)
    {
        $originKeys = [];
        $locationIds = [];
        $locationId = '';

        if(isset($lineitems['origin']) && !empty($lineitems['origin'])){

            foreach($lineitems['origin'] as $key => $origin){
                $originKeys[$origin['locationId']][] = $key;
                if(!in_array($origin['locationId'], $locationIds)){
                    $locationIds[] = $origin['locationId'];
                }
            }
        }

        return ['originKeys' => $originKeys, 'locationIds' => $locationIds];
    }

    public function isJson($string) {
        return ((is_string($string) &&
                (is_object(json_decode($string)) ||
                is_array(json_decode($string))))) ? true : false;
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function getSingleLogDetail(Request $request)
    {
        $packageId = isset($request['packaging_id']) ? $request['packaging_id'] : '';
        $locationId = isset($request['location_id']) ? $request['location_id'] : '';
            
        $respdata = optional(PackagingDetail::where('packaging_uuid', $packageId)
            ->where('store_id', $request['store_id'])
            ->first())->toArray() ?? null;
        $packagingDetails = json_decode($respdata['packaging_detail']);
        $lineitems = isset($respdata['lineitems']) ? json_decode($respdata['lineitems']) : [];
        $packaging = Functions::formatPackaging($packagingDetails, $lineitems, $locationId);

        return response()->json(['error' => false,
            'data' => $packaging,
            'message' => '',
            ], 200
        );
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
    }
}
