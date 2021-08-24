<?php

namespace App\CustomClasses;

use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;
use Illuminate\Support\Facades\Log;
use App\Models\RequestTempData;
use App\Models\Store;
use App\Models\BoxSize;
use Carbon\Carbon;
class Shipping
{

    /**
     * @var WweLTLShipmentPackage
     */
    private $shipmentPkg;

    private $isHazmat = 'N';

    private $compileQuotes;
    private $isInsurance = 'N';
    private $isRequestMultishipment = false;
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
        $quoteSettings = $multiShipmentQuotes = [];
        $generateReqData = new GenerateRequestData();
        //   init is a function to to call it explixitlitly rather constructor
        $generateReqData->_init($quoteSettings, $connectionSettings, $storeData);
        $package = $request['lineItemData'];
        // Disabling instore pickup if there is multi shipment case
        $originAddress = $this->checkInstorePickup($package['origin']);
        // Generating carrier creds and origin array
        $destination = $request['lineItemData']['destination'] ?? [];
        $resp = $generateReqData->generateEnitureArray($originAddress, $destination);
        $residential = $resp['residential'];
        $carriersArray = $resp['carriersArr'];
        //dd($residential);
        //['carriersArr' => $carriersArr, 'residential' => $this->residential];
        // Checking if any productis hazardous
        $hazmatAllItems = $this->isHazmatMaterial($package);
        $this->isInsurance($package);
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
        if($this->isInsurance === 'Y'){
            foreach($carriersArray['carriers'] as $key => $carriers){
                $carriersArray['carriers'][$key]['api']['insureShipment'] = 1;
            }
        }
// Genearting final request Array
        $requestArr = $generateReqData->generateRequestArray($request, $carriersArray, $package['items'], $cartInfo);


        if (empty($requestArr)) {
            return false;
        }
        $url = Constant::QUOTES_URL;
        //echo "<pre>"; print_r($requestArr['requestArr']); exit;
        $this->checkIsRequestMiltiShipment($requestArr['requestArr']);

        $quotes = $this->sendCurlRequest($url, $requestArr['requestArr']);

        $boxbins = $requestArr['boxBins'] ?? [];
        if(isset($requestArr['binReponse']) && !empty($requestArr['binReponse'])){
            $quotes = $this->addBinResponseToQuotes($requestArr['binReponse'], $quotes);
        }
        Log::info('after addBinResponseToQuotes '. json_encode($quotes));

        if (isset($_GET['DEBUG_ON'])) {
            $printData = [
                'url' => $url,
                'buildQuery' => http_build_query($requestArr),
                'request' => $requestArr,
                'quotes' => $quotes
            ];
            dd($printData);
        }
        $quotesFromWs = $quotes ?? [];
        $finalQuotes = $this->compileQuotes->newGetQuotesResults($quotes, $connectionSettings, $package['origin'], $this->isHazmat, $hazmatAllItems, $residential);
        if (!empty($finalQuotes['multiShipmentQuotes'])){
            $multiShipmentQuotes = $finalQuotes['multiShipmentQuotes'];
            $finalQuotes = $finalQuotes['checkoutQuotes'];
        }
        //When one

        $_finalQuotes = $finalTitlesTemp = $finalCodesTemp = [];
        $finalTitles = array_column($finalQuotes, 'title');
        $finalCodes = array_column($finalQuotes, 'code');
        foreach ($finalTitles as $key=>$finalTitle ){
            $finalTitlesTemp[$key] = explode(' ', $finalTitle)[0];
        }
        foreach ($finalCodes as $key=>$finalCode ){
            $finalCodesTemp[$key] = explode('+', $finalCode)[0];
        }
        $isFreightTitleExist = array_search('Freight', $finalTitlesTemp);
        $isShippingTitleExist = array_search('Shipping', $finalTitlesTemp);
        $isAVGCodeExist = gettype(array_search('AVG', $finalCodesTemp)) == 'integer';
        $freightCode = '';
        $finalCost = 0;
        //dd($finalTitles,$finalQuotes, $isShippingTitleExist, $isFreightTitleExist);
        if ((gettype($isFreightTitleExist) == 'integer') && (gettype($isShippingTitleExist) == 'integer')){

            foreach ($finalQuotes as $key=>$_quote){
                if ($_quote['title'] == 'Freight' || $_quote['title'] == 'Shipping'){
                    $finalCost += $_quote['rate'];
                    $freightCode = ($_quote['code'] != 'Multi') ? $_quote['code'] : $freightCode;
                }
                if($_quote['title'] != 'Freight' && $_quote['title'] != 'Shipping'){
                    $_finalQuotes[$key]['code'] = $_quote['code'];
                    $_finalQuotes[$key]['rate'] = $_quote['rate'];
                    $_finalQuotes[$key]['title'] = $_quote['title'];
                }
            }
        }
        //dd($finalQuotes, (gettype($isFreightTitleExist) == 'integer'), (gettype($isShippingTitleExist) == 'integer'));
        if (!empty($_finalQuotes)){
            $_finalQuotes[$key]['code'] = $freightCode;
            $_finalQuotes[$key]['title'] = 'Freight';
            $_finalQuotes[$key]['rate'] = $finalCost;
            $_finalQuotes = array_values($_finalQuotes);
            $finalQuotes = $_finalQuotes;
        }else {
            $isShippingOrFreight = gettype($isFreightTitleExist) == 'integer' || gettype($isShippingTitleExist) == 'integer';

            if($isShippingOrFreight && gettype($isFreightTitleExist) == 'integer' && $isAVGCodeExist){
                $isShippingOrFreight = false;
            }
            if($this->isRequestMultishipment && !$isShippingOrFreight) {
                $finalQuotesMulti = $this->makeMultishipmentSmallLtl($finalQuotes, $connectionSettings,  $residential, $quotesFromWs);
                $finalQuotes = $finalQuotesMulti['checkoutQuotes'] ?? [];
                $multiShipmentQuotes = $finalQuotesMulti['multiShipmentQuotes'] ?? [];
            }
        }
        $finalQuotes = $this->addRateId($finalQuotes);

        $resp = $this->generateQuoteFormatResponse($finalQuotes);
        $this->orderWidgetSave($request, $requestArr, $quotes, $finalQuotes, $resp, $cartInfo, $boxbins, $multiShipmentQuotes);
        return $resp;
    }

    private function checkIsRequestMiltiShipment($request){


        $carriers = $request['carriers'] ?? [];

        if(!isset($carriers['wweLTL'])  || !isset($carriers['wweSmall']) ){
            return false;
        }
        foreach ($carriers as $carrier){
            $output= $this->multi_unique($carrier['originAddress']);
            if(count($output) > 1) {
                $this->isRequestMultishipment = true;
                break;
            }
        }
    }

    private function multi_unique($src){
        $output = array_map("unserialize",
            array_unique(array_map("serialize", $src)));
        return $output;
    }

    private function makeMultishipmentSmallLtl($quotes, $connectionSettings,  $residential, $quotesFromWs){

        $quoteSettings = $connectionSettings['ltl-quotes']['quote_settings'];
        $isResi = $residential['wweLtl'] == 'Y' ? true:false;
        $lgQuotesAlways =
            (isset($quoteSettings['alwaysLiftGateDelivery']) && $quoteSettings['alwaysLiftGateDelivery']);

        $parcel = $ltl = $ltlLG = $ownArrangement =  [];

        foreach ($quotes as $quote){
            if(!empty($quote) && $quote['code'] !== 'own_arrangement') {
                if (strpos($quote['code'], 'parcel_12') !== false) {
                    $parcel[] = $quote;
                } else {
                    if (strpos($quote['code'], '+LG') !== false) {
                        $ltlLG[] = $quote;
                    } else {
                        $ltl[] = $quote;
                    }
                }
            }else if($quote['code'] === 'own_arrangement'){
                $ownArrangement = $quote;
            }
        }

        //print_r($quotes); print_r($parcel); print_r($ltl); print_r($ltlLG); exit;
        if(empty($parcel) || (empty($ltl) && empty($ltlLG))){
            return ['checkoutQuotes' => $quotes];
        }
        $indexes = $this->indexesOfQuotes($quotesFromWs);

        $isLG = count($ltlLG) > 0;
        $parcel = !empty($parcel) ? $this->getSmallest($parcel) : [];
        $ltl = !empty($ltl) ? $this->getSmallest($ltl) : [];
        $ltlLG = !empty($ltlLG) ? $this->getSmallest($ltlLG) : [];

        $parcelRate = $parcel['rate'] ?? 0;
        $ltlRate = $ltl['rate'] ?? 0;
        $ltlLGRate = $ltlLG['rate'] ?? 0;
        $rCode = $isResi ? '+R':'';
        $rtitle = $isResi ? ' ( R )':'';

        $parcelRate = (float) str_replace(',','',$parcelRate);
        $ltlRate = (float) str_replace(',','',$ltlRate);
        if(!empty($ltl)) {
            $newQuotes[] = [
                'code' => 'multi' . $rCode,
                'rate' => $parcelRate + $ltlRate,
                'title' => 'Freight' . $rtitle
            ];
        }
        if($isLG){
            if(!$lgQuotesAlways) {
                $rtitle = $isResi ? ' ( R | L )' : ' ( L )';
            }
            $newQuotes[] = [
                'code' => 'multi'.$rCode.'+LG',
                'rate' => $parcelRate+$ltlLGRate,
                'title' => 'Freight'.$rtitle
            ];
        }
        $multiShipmentQuotes = $this->createOrderWidgetRates($parcel, $ltl, $ltlLG, $indexes);
        if(!empty($ownArrangement)){
            $newQuotes[count($newQuotes)] = $ownArrangement;
        }
        $resp = [
            'multiShipmentQuotes' => $multiShipmentQuotes,
            'checkoutQuotes' => $newQuotes
        ];
        return $resp;
    }

    private function indexesOfQuotes($quotes){
        $indexes = [];
        $count = 0;
        foreach ($quotes['wweLTL'] as $key=>$quote){
            $indexes[$count]=$key;
            $count++;
        }
        $count = 0;
        foreach ($quotes['wweSmall'] as $key=>$quote){
            $indexes[$count]=$key;
            $count++;
        }
        return $indexes;
    }

    private function createOrderWidgetRates($parcel, $ltl, $ltlLG, $indexes){
        $orderWidgetRates = [];
        if(!empty($parcel) && !empty($ltl)) {
            $orderWidgetRates['simple'] = [$indexes[0] => $parcel, $indexes[1] => $ltl];
        }
        if(!empty($ltlLG)){
            $orderWidgetRates['liftgate'] = [ $indexes[0]=>$parcel, $indexes[1]=>$ltlLG];
        }
        return $orderWidgetRates;
    }
    private function getSmallest($quotes){
        usort($quotes, function($a, $b) {
            return $a['rate'] <=> $b['rate'];
        });
        return $quotes[0];
    }

    private function addBinResponseToQuotes($binReponse, $quotes){
        $boxFee = [];
        $addedFee = false;
        foreach ($binReponse as $locationId => $bin){
            $quotes['wweSmall'][$locationId]['binPackagingData']['response'] = $bin;
            if(!$addedFee) {
                $boxFee[$locationId] = $this->getCumulativeBoxFee($bin);
                //$addedFee = true;
            }
        }
        if(!empty($boxFee)){
            $quotes = $this->addBoxFeeToQuotes($quotes, $boxFee);
        }
        return $quotes;
    }

    private function addBoxFeeToQuotes(array $quotes, array $boxFee) : array
    {
        if(isset($quotes['wweSmall']) && !empty($quotes['wweSmall'])){
            foreach ($quotes['wweSmall'] as $locId => $q){
                if(isset($q['q'])) {
                    foreach ($q['q'] as $key => $qs) {
                        if (isset($qs['totalNetCharge']['Amount'])) {
                            $quotes['wweSmall'][$locId]['q'][$key]['totalNetCharge']['Amount'] = $qs['totalNetCharge']['Amount'] + $boxFee[$locId];
                        }
                    }
                }
            }
        }
        return $quotes;
    }

    private function getCumulativeBoxFee(object $bins):float
    {
        $boxFee = 0;
        if(!empty($bins->bins_packed)){
            foreach($bins->bins_packed as $pack){
                $boxId = $pack->bin_data->id;
                $boxFee += $this->BoxFeeByID($boxId);
            }
        }
        return $boxFee;
    }

    private function BoxFeeByID(int $boxId)
    {
        if(BoxSize::where('id',$boxId)->exists()){
            return BoxSize::find($boxId)->pluck('box_fee')->first();
        }
        return 0;
    }

    public function orderWidgetSave($lineItems, $requestArr, $quotes, $finalQuotes, $resp, $cartInfo, $boxbins, $multiShipmentQuotes = null){
        //echo "<pre>"; print_r($requestArr); print_r($quotes); exit;
        foreach ($finalQuotes as $finalQuote){
            $RequestTempData = new RequestTempData();
            $RequestTempData->request = json_encode($requestArr);
            $RequestTempData->lineitems = json_encode($lineItems);
            $RequestTempData->quotes = json_encode($quotes);
            $RequestTempData->response = json_encode($resp);
            $RequestTempData->multiShipmentresponse = json_encode($multiShipmentQuotes, JSON_FORCE_OBJECT);
            $RequestTempData->store_id = $cartInfo['store_id'];
            $RequestTempData->rate_id = $finalQuote['rate_id'];
            $RequestTempData->cart_id = $cartInfo['cartId'];
            $RequestTempData->box_bins = json_encode($boxbins);
            $RequestTempData->save();
        }
    }

    public function addRateId($finalQuotes){
        $time = time();
        foreach($finalQuotes as $key => $finalQuote){
            $finalQuotes[$key]['rate_id'] = isset($finalQuote['code']) ? $finalQuote['code'].$time : $time;
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
                $hazmatAllItems[$items['origin'][$key]['locationId']] = 'Y';
            }else{
                $hazmatAllItems[$items['origin'][$key]['locationId']] = 'N';
            }
        }
        return $hazmatAllItems;
    }

    /**
     * to enable insurance property for Api
     */
    public function isInsurance($items)
    {
        foreach ($items['items'] as $key => $item) {
            if (isset($item['product_insurance_active']) && $item['product_insurance_active'] === 1) {
                $this->isInsurance = 'Y';
            }
        }
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
                    'cost' => ['currency' => 'USD', 'amount' => str_replace(',','',$quote['rate'])],
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
