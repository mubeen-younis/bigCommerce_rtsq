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
    
            $logsData = [];
            $url = Constant::LOGS_URL;
            $logsResp = $this->sendCurlRequest($url, $postData);  

            $storeDetails = BigCommerceFunctions::getStoreSettings($storeHash);
            $storeDetails = (new CurlRequest())->enSingleCurlRequest($storeDetails['endpoint'], $storeDetails['request'], $storeDetails['headers'], $storeDetails['method'], false);
            $response = json_decode($storeDetails['response'], true);
            $prePackageId = null;
            $count = 0;
            $key = 0;
            
            if(isset($logsResp['severity']) && $logsResp['severity'] === "SUCCESS"){
                if(isset($logsResp['data']) && !empty($logsResp['data'])){
                    foreach($logsResp['data'] as $data){
                        $requestData = isset($data['request']) ? json_decode($data['request'], true) : [];
                        $packageId = isset($requestData['packaging_id']) ? $requestData['packaging_id'] : '';
                        if($prePackageId == $packageId){
                            $count++;
                        } else {
                            $count = 0;
                        }
                        $respdata = optional(PackagingDetail::select('is_packaging', 'lineitems')->where('packaging_uuid', $packageId)
                        ->where('store_id', $request['store_id'])
                        ->first())->toArray() ?? [];

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

                        $lineitems = isset($respdata['lineitems']) ? json_decode($respdata['lineitems'], true) : [];
                        $getOriginKeys = $this->getOriginKeys($lineitems);
                        $destination = isset($lineitems['destination']) ? $lineitems['destination'] : [];
                        $originKeys = $getOriginKeys['originKeys'];
                        $locationIds = $getOriginKeys['locationIds'];

                        $logsData[$key]['location_id'] = $locationIds[$count] ?? null;
                        $logsData[$key]['packaging_id'] = $packageId;
                        $logsData[$key]['response'] = isset($data['status']) ? $data['status'] : '';

                        if (!empty($originKeys) && $this->isMulti){
                            foreach($originKeys[$locationIds[$count]] as $key1 => $code){ 
                                if (isset($lineitems['items']) && !empty($lineitems['items'])){
                                    foreach($lineitems['items'] as $itemIndex => $item){
                                        if ($itemIndex == $code){
                                            $logsData[$key]['quantity'][] = isset($item['piecesOfLineItem']) ? $item['piecesOfLineItem'] : '';
                                            $logsData[$key]['dimension'][] = floatval($item['lineItemLength']) . ' X ' . floatval($item['lineItemWidth']) . ' X ' . floatval($item['lineItemHeight']);
                                            $logsData[$key]['Items'][] = isset($item['lineItemName']) ? $item['lineItemName'] : '';
                                       }
                                    }
                                }
                                if (isset($lineitems['origin']) && !empty($lineitems['origin'])){
                                    foreach($lineitems['origin'] as $origIndex => $origin){
                                        if ($origIndex == $code){
                                            $logsData[$key]['sender'] = $origin['senderCity'] . ', ' . $origin['senderState'] . ' ' . $origin['senderZip'] . ' ' . $origin['senderCountryCode'];
                                        }
                                    }
                                }
                            }
                        } else {
                            if (isset($lineitems['items']) && !empty($lineitems['items'])){
                                foreach($lineitems['items'] as $item){
                                    $logsData[$key]['quantity'][] = isset($item['piecesOfLineItem']) ? $item['piecesOfLineItem'] : '';
                                    $logsData[$key]['dimension'][] = floatval($item['lineItemLength']) . ' X ' . floatval($item['lineItemWidth']) . ' X ' . floatval($item['lineItemHeight']);
                                    $logsData[$key]['Items'][] = isset($item['lineItemName']) ? $item['lineItemName'] : '';
                                }
                            }
    
                            if (isset($lineitems['origin']) && !empty($lineitems['origin'])){
                                foreach($lineitems['origin'] as $origin){
                                    $logsData[$key]['sender'] = $origin['senderCity'] . ', ' . $origin['senderState'] . ' ' . $origin['senderZip'] . ' ' . $origin['senderCountryCode'];
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
            Log::info('Exception to Get Logs: ' . json_encode($exception->getMessage()));
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
        $countOrigin = 0;
        $locationId = '';

        if(isset($lineitems['origin']) && !empty($lineitems['origin'])){
            $countOrigin = count($lineitems['origin']) - 1;

            foreach($lineitems['origin'] as $key => $origin){
                $originKeys[$origin['locationId']][] = $key;
                if(!in_array($origin['locationId'], $locationIds)){
                    $locationIds[] = $origin['locationId'];
                }
                $countOrigin--;
            }
            
            $this->isMulti = count($locationIds) > 1 ? true : false;
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
