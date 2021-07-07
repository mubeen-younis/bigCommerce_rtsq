<?php

namespace App\CustomClasses;

use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;
use Illuminate\Support\Facades\Log;
use App\Models\RequestTempData;
use App\Models\Store;
use Carbon\Carbon;
class Shipping
{

    /**
     * @var WweLTLShipmentPackage
     */
    private $shipmentPkg;

    private $isHazmat = 'N';

    private $compileQuotes;

    public function __construct()
    {
        $this->shipmentPkg = new WweLTLShipmentPackage();
        $this->compileQuotes = new CompileQuotes();
    }

    /**
     * @param $request
     * @param $storeData
     * @param $connectionSettings
     * @param $quoteSettings
     * @return array | bool
     */
    public function collectRates($request, $storeData, $connectionSettings, $cartInfo)
    {
        $quoteSettings = [];
        $generateReqData = new GenerateRequestData();
        //   init is a function to to call it explixitlitly rather constructor
        $generateReqData->_init($quoteSettings, $connectionSettings, $storeData);
        $package = $request['lineItemData'];
        // Disabling instore pickup if there is multi shipment case
        $originAddress = $this->checkInstorePickup($package['origin']);
        // Generating carrier creds and origin array
        $carriersArray = $generateReqData->generateEnitureArray($originAddress);

        // Checking if any productis hazardous
        $hazmatAllItems = $this->isHazmatMaterial($package);
        if ($this->isHazmat == 'Y') {
            foreach($carriersArray['carriers'] as $key => $carriers){
                $carriersArray['carriers'][$key]['api']['lineItemHazmatInfo'] = [
                    [
                        'isHazmatLineItem' => 'Y',
                        'lineItemHazmatUNNumberHeader' => 'UN #',
                        'lineItemHazmatUNNumber' => 'UN 1139',
                        'lineItemHazmatClass' => '1.1',
                        'lineItemHazmatEmContactPhone' => '4043308699',
                        'lineItemHazmatPackagingGroup' => 'I',
                    ],
                ];
            }
        }
// Genearting final request Array
        $requestArr = $generateReqData->generateRequestArray($request, $carriersArray, $package['items']);

        /*  echo json_encode($requestArr);die();*/

        if (empty($requestArr)) {
            return false;
        }
        $url = Constant::QUOTES_URL;
//print_r($requestArr);
        //$resp = ['requestArr' => $requestArr, 'binReponse' => $binReponse];
        $quotes = $this->sendCurlRequest($url, $requestArr['requestArr']);
        if(isset($requestArr['binReponse']) && !empty($requestArr['binReponse'])){
            $quotes = $this->addBinResponseToQuotes($requestArr['binReponse'], $quotes);
        }
Log::info('after addBinResponseToQuotes '. json_encode($quotes));
//echo "<pre>"; print_r($quotes); exit;
        // Debug point will print data if en_print_query=1
        if (isset($_GET['DEBUG_ON'])) {
            $printData = [
                'url' => $url,
                'buildQuery' => http_build_query($requestArr),
                'request' => $requestArr,
                'quotes' => $quotes
            ];
            dd($printData);
        }
        //dd($requestArr,$quotes);
        $finalQuotes = $this->compileQuotes->newGetQuotesResults($quotes, $connectionSettings, $package['origin'], $this->isHazmat, $hazmatAllItems);

        $finalQuotes = $this->addRateId($finalQuotes);
        $resp = $this->generateQuoteFormatResponse($finalQuotes);
        $this->orderWidgetSave($request, $requestArr, $quotes, $finalQuotes, $resp, $cartInfo);
        return $resp;
    }

    private function addBinResponseToQuotes($binReponse, $quotes){
        foreach ($binReponse as $locationId => $bin){
            $quotes['wweSmall'][$locationId]['binPackagingData']['response'] = $bin;
        }
        return $quotes;
    }
    public function orderWidgetSave($lineItems, $requestArr, $quotes, $finalQuotes, $resp, $cartInfo){
        //print_r($cartId); print_r($requestArr); print_r($quotes); print_r($finalQuotes); print_r($resp); exit;

        foreach ($finalQuotes as $finalQuote){
            $RequestTempData = new RequestTempData();
            $RequestTempData->request = json_encode($requestArr);
            $RequestTempData->lineitems = json_encode($lineItems);
            $RequestTempData->quotes = json_encode($quotes);
            $RequestTempData->response = json_encode($resp);
            $RequestTempData->store_id = $cartInfo['store_id'];
            $RequestTempData->rate_id = $finalQuote['rate_id'];
            $RequestTempData->cart_id = $cartInfo['cartId'];
            $RequestTempData->save();
        }
    }

    public function addRateId($finalQuotes){
        $time = time();
        foreach($finalQuotes as $key => $finalQuote){
            $finalQuotes[$key]['rate_id'] = $finalQuote['code'].$time;
        }
        return $finalQuotes;
    }

    public function checkInstorePickup($origin)
    {
        if (count($origin) > 1) {
            $whIDs = [];
            foreach ($origin as $wh) {
                $whIDs[] = $wh['locationId'];
            }
            if (count(array_unique($whIDs)) > 1) {
                foreach ($origin as $id => $wh) {
                    if (isset($wh['InstorPickupLocalDelivery'])) {
                        $origin[$id]['InstorPickupLocalDelivery'] = [];
                    }
                }
            }
        }
        return $origin;
    }

    /**
     * to enable hazmat property for Api
     */
    public function isHazmatMaterial($items)
    {
        $hazmatAllItems = [];
        foreach ($items['items'] as $key => $item) {
            if (isset($item['isHazmatLineItem']) && $item['isHazmatLineItem'] == 'Y') {
                $this->isHazmat = 'Y';
                $hazmatAllItems[$items['origin'][$key]['senderZip']] = 'Y';
            }else{
                $hazmatAllItems[$items['origin'][$key]['senderZip']] = 'N';
            }
        }
        return $hazmatAllItems;
    }

    /**
     * @return array
     */
    public function getAllowedMethods()
    {
        return [$this->_code => $this->getConfigData('name')];
    }

    /**
     * @param $quotes
     * @return array
     */
    public function setCarrierRates($quotes)
    {
        return $quotes = $quotes ?? [];
    }

    public function generateQuoteFormatResponse($quotes)
    {
        //echo "<pre>"; print_r($quotes); exit;
        $current = str_replace(' ', 'T', Carbon::now())."-00:00";
        if (!empty(array_filter($quotes))) {
            $resp['quote_id'] = (string) rand(1,9);// need to change
            $resp['messages'] = [];// need to change
            $resp['carrier_quotes'][0] = ['carrier_info' => ['code' => 'usps_pitney_bowes', 'display_name' => $this->limitTitle($quotes[0])]];
            foreach ($quotes as $key => $quote) {
                $resp['carrier_quotes'][0]['quotes'][$key] = [
                    'code' => $quote['code'],
                    'rate_id' => $quote['rate_id'],
                    'display_name' => $this->limitTitle($quote),
                    'cost' => ['currency' => 'USD', 'amount' => $quote['rate']],
                    'dispatch_date' => "$current"
                    //'cost' => ['currency' => 'USD', 'amount' => number_format($quote['rate'], 2, '.', ',')],
                    //'transit_time' => ['units' => 'BUSINESS_DAYS', 'duration' => 1],
                    // TODO: Will be set

                ];
            }
        } else {
            $resp = [];
        }

        Log::info('$resp '. json_encode($resp));
        return $resp;
    }


    public function limitTitle($quote){
        $res = $quote['title'];
        if( strlen($quote['title']) > 100 ){
            $res = explode("(Estimated", $quote['title'])[0];
        }else if( $quote['title'] == "" ){
            $res = $quote['code'];
        }
        return $res;
    }

    /**
     * This function send request and return response
     * $isAssocArray Parameter When TRUE, then returned objects will
     * be converted into associative arrays, otherwise its an object
     * @param $url
     * @param $postData
     * @return object|array
     */
    public function sendCurlRequest($url, $postData)
    {
        Log::info('$postData '. json_encode($postData));
        $fieldString = http_build_query($postData);
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1000);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fieldString);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $output = curl_exec($ch);
            curl_close($ch);
            Log::info('$output '. $output);
            return json_decode($output, true);
        } catch (\Throwable $e) {
            $result = [];
        }
        return $result;
    }
}
