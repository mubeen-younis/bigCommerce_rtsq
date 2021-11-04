<?php


namespace App\CustomClasses\Bin3D;
use App\Constants\Constant;
use App\Http\Controllers\Subscription\PackageSubscriptionController;
use App\Models\BinRequestLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;

class Bin3D
{
    private $userName = Constant::BIN_USER;

    /**
     * Property contains API key.
     * @var string
     */
    private $apiKey = Constant::BIN_API_KEY;

    /**
     * Property contains URL we hit for 3dBin API.
     * @var  string
     */
    private $endURL = Constant::BIN_URL;

    public function getBinResponse($storeId, $bins, $items, $itemsAlone, $hits, $cartInfo)
    {
        //loop for each bin request
        $sbsStatus = $this->consumeHits($storeId,$hits);
        if(!$sbsStatus['status']){
            return [];
        }
        //$items = $itemsAlone;
        if(count($items)) {
            foreach ($items as $key => $item) {
                $binRequest[$key] = $this->generateBinRequest($bins, $item);
            }
            $responseFromSBS = $this->binRequest($binRequest, $storeId, $hits, $cartInfo);
            foreach ($itemsAlone as $key => $itemAlone){
                $items[$key] = $itemAlone;
            }
        }else if(count($itemsAlone)){
            $responseFromSBS = $this->generateShipAloneBinResponse($itemsAlone);
            $items = $itemsAlone;
        }
        $sbsCompiledResponse = $this->handleNotPacked($responseFromSBS, $items);
        return $sbsCompiledResponse;
    }
/*
 * Consume hits will check is sbs not suspend and has hits for consume
 * response true or false;
 * **/
    private function consumeHits($storeId,$hits){
       $PackageSubscriptionController = new PackageSubscriptionController();
       $param = ['store_id' => $storeId, 'hits'=>$hits, 'addon_type'=>'SBS'];
       $resp = $PackageSubscriptionController->consumeHits($param);

       return $resp;
    }
    /*
    * Generate formated request for bin
     * $bins -> available boxes in db for any store
     * $items -> items with dimensions to be packed in boxes
     */
    private function generateBinRequest($bins, $item)
    {
        //bins_utilization or bin_number
        $optimizationMode = "bins_utilization";
        $params = [
            'optimization_mode'  => $optimizationMode,
            'images_background_color' => '255,255,255',
            'images_bin_border_color' => '59,59,59',
            'images_bin_fill_color' => '230,230,230',
            'images_item_border_color' => '214,79,79',
            'images_item_fill_color' => '177,14,14',
            'images_item_back_border_color' => '215,103,103',
            'images_sbs_last_item_fill_color' => '99,93,93',
            'images_sbs_last_item_border_color' => '145,133,133',
            'images_width' => '100',
            'images_height' => '100',
            'images_source' => 'file',
            'images_sbs' => '1',
            'stats' => '1',
            'item_coordinates' => '1',
            'images_complete' => '1',
            'images_separated' => '1'
        ];
        $finalRequest['username'] = $this->userName;
        $finalRequest['api_key'] = $this->apiKey;
        $finalRequest['params'] = $params;
        $finalRequest['bins']   = $bins;
        $finalRequest['items']  = $item;
        return $finalRequest;
    }

    /*
     * Send request to bin
     * $binRequest-> formated data which need to send bins endpoint
     * */
    private function binRequest($binRequest, $storeId, $hits, $cartInfo){
        $requestHash = $this->get_encrypted_params(json_encode($binRequest));

        /*
         * Check hash if available same request in last 24 hours then no need to send request to 3dbin
         * **/
        if(BinRequestLog::where('request_hash', '=', $requestHash)->where('created_at', '>', Carbon::now()->subDay(1))->exists()){
           $response = BinRequestLog::select('api_response')->where('request_hash', '=', $requestHash)->where('created_at', '>', Carbon::now()->subDay(1))->first();
           return json_decode($response['api_response']);
        }
//dd(1);
        $binRequestLog = new BinRequestLog();
        $binRequestLog->store_id = $storeId;
        $binRequestLog->cart_id = $cartInfo['cartId'];
        $binRequestLog->request = json_encode($binRequest);
        $binRequestLog->request_hash = $requestHash;
        $binRequestLog->request_time = now();
        $binRequestLog->hits = $hits;
        $binRequestLog->save();
        $binRequestLogId = $binRequestLog->id;

        $endpoint = $this->endURL;
        // create array for curl handles
        $chs = [];
        // create array for responses
        $responses = [];
        $this->responseDeco = [];
        // init curl multi handle
        $mh = curl_multi_init();
        // create running flag
        $running = null;
        // cycle through requests and set up
        foreach ($binRequest as $key => $request) {
            $prepared_query = 'query=' . json_encode($request);
            // init individual curl handle
            $chs[$key] = curl_init();
            // set url
            curl_setopt($chs[$key], CURLOPT_URL, $this->endURL);
            // check for post data and handle if present
            curl_setopt($chs[$key], CURLOPT_POST, 1);
            curl_setopt($chs[$key], CURLOPT_POSTFIELDS, $prepared_query);
            curl_setopt($chs[$key], CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($chs[$key], CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($chs[$key], CURLOPT_SSL_VERIFYHOST, 0);
            /*
             * execute Curl Reqeusst
             */
            curl_multi_add_handle($mh, $chs[$key]);
        }

        do {
            // execute curl requests
            curl_multi_exec($mh, $running);
            // block to avoid needless cycling until change in status
//            curl_multi_select($mh);
            // check flag to see if we're done
        } while ($running > 0);
        // cycle through requests
        foreach ($chs as $key => $ch) {
            $binResponse = curl_multi_getcontent($ch);
            $responses[$key] = $binResponse;
            $this->responseDeco[$key] = json_decode($binResponse);
            // close individual handle
            curl_multi_remove_handle($mh, $ch);
        }
        // close multi handle
        curl_multi_close($mh);
        $binRequestLog = BinRequestLog::find($binRequestLogId);
        $binRequestLog->api_response = json_encode($responses);
        $binRequestLog->response_time = now();
        $binRequestLog->save();
        return $responses;
    }

    private function handleNotPacked($responseFromSBS, $items){
        foreach ($responseFromSBS as $key => $SBSResp){
            $data[$key] = json_decode($SBSResp)->response;
            $resp = json_decode($SBSResp);
            $not_packed_items = $resp->response->not_packed_items;
            if(count($not_packed_items)){
                foreach ($not_packed_items as $not_packed_item) {
                    $not_packed_item = (array)$not_packed_item;
                    for ($i = 1; $i <= $not_packed_item['q']; $i++) {
                        array_push($data[$key]->bins_packed, $this->createItemOwnPackage($not_packed_item));
                    }
                }
            }
        }
        return $data;
    }

    private function createItemOwnPackage($itemPropertiesArr){
        $itemPackage = new \stdClass();
        $itemPackage->bin_data = new \stdClass();
        $itemPackage->bin_data->w = $itemPropertiesArr['w'];
        $itemPackage->bin_data->h = $itemPropertiesArr['h'];
        $itemPackage->bin_data->d = $itemPropertiesArr['d'];
        $itemPackage->bin_data->id = $itemPropertiesArr['id'];
        $itemPackage->bin_data->type = 'item';
        $itemPackage->bin_data->used_space = '100';
        $itemPackage->bin_data->weight = $itemPropertiesArr['wg'];
        $itemPackage->bin_data->used_weight = '100';
        $itemPackage->bin_data->order_id = 'unknown';
        $itemPackage->image_complete = 'http://us-east.api.3dbinpacking.com/images/cb0549790cbc9e08eeb636779afa3280/20181207/d4fad4107306d71b188c4b82ff167d16/1544164000-3488-1952286.png';
        $itemPackage->images_generation_time = '0.00279';
        $itemPackage->packing_time = '0.00537';
        $itemPackage->items = array();
        $itemPackage->items[0] = new \stdClass();
        $itemPackage->items[0]->id = $itemPropertiesArr['id'];
        $itemPackage->items[0]->w = $itemPropertiesArr['w'];
        $itemPackage->items[0]->h = $itemPropertiesArr['h'];
        $itemPackage->items[0]->d = $itemPropertiesArr['d'];
        $itemPackage->items[0]->wg = $itemPropertiesArr['wg'];
        $itemPackage->items[0]->type = 'box';
        $itemPackage->items[0]->image_separated = 'http://us-east.api.3dbinpacking.com/images/cb0549790cbc9e08eeb636779afa3280/20181207/d4fad4107306d71b188c4b82ff167d16/1544164000-3472-1245616.png';
        $itemPackage->items[0]->image_sbs = 'http://us-east.api.3dbinpacking.com/images/cb0549790cbc9e08eeb636779afa3280/20181207/d4fad4107306d71b188c4b82ff167d16/1544164000-3481-4328454.png';
        $itemPackage->items[0]->coordinates = new \stdClass();
        $itemPackage->items[0]->coordinates->x1 = '0';
        $itemPackage->items[0]->coordinates->y1 = '0';
        $itemPackage->items[0]->coordinates->z1 = '0';
        $itemPackage->items[0]->coordinates->x2 = $itemPropertiesArr['d'];
        $itemPackage->items[0]->coordinates->y2 = $itemPropertiesArr['w'];
        $itemPackage->items[0]->coordinates->z2 = $itemPropertiesArr['h'];

        return $itemPackage;
    }

    private  function get_encrypted_params($string) {
        $key = "address_validation"; //key to encrypt and decrypt
        $result = '';
        $test = "";
        for ($i = 0; $i < strlen($string); $i++) {
            $char = substr($string, $i, 1);
            $keychar = substr($key, ($i % strlen($key)) - 1, 1);
            $char = chr(ord($char) + ord($keychar));
            $result .= $char;
        }

        return urlencode(base64_encode($result));
    }

    private function generateShipAloneBinResponse($items){
        $object = $notPacked =  [];
        foreach ($items as $Shipkey => $item){
            foreach ($item as $key=>$it){
                $not_packed_items[$key] = json_decode(json_encode($it));
            }
            $notPacked['not_packed_items'] = $not_packed_items;
            $notPacked['response_time'] = 0;
            $notPacked['id'] = rand();
            $notPacked['total_cost'] = 0;
            $notPacked['bins_packed'] = [];
            $notPacked['status'] = 1;
            $notPacked['errors'] = [];
            $data['response'] = $notPacked;
            $object[$Shipkey] = json_encode($data);

        }
        return json_decode(json_encode($object));
    }

}
