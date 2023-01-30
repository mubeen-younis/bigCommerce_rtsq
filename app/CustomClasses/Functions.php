<?php

namespace App\CustomClasses;

use App\Models\BoxSize;
use Illuminate\Support\Facades\Log;
use App\Models\ResidentialSetting;
use App\Models\Locations;
use App\Models\LocAssociatedAccountNo;
use App\Models\WeightThresholdSettings;

class Functions
{
    protected static $daysAfterExpiry = 4;
    public static $defaultThresholdLimit = 150;
    public static $orderWebhookString = ['store/order/*', 'store/order/created'];
    public static $ltlErrorMessage = 'Line Item Marked as LTL.';
    public static $smallErrorMessage = 'Line Item Marked as Small.';
    public static $ltlPrefix = '-ltl';
    public static $smallPrefix = '-small';
    public static $ltlMultiTitle = '-ltlFreight';
    public static $simpleLTLTitle = 'Freight';
    public static $smallMultiTitle = '-smallShipping';
    public static $dbscSlug = 'dbsc';
    public static $insideDelLable = ' w/ inside delivery';
    public static $insideDelResiLable = ' w/ residential & inside delivery';
    public static $insideDelLiftGateLable = ' w/ lift gate & inside delivery';
    public static $insideDelLiftGateResiLable = ' w/ residential, lift gate & inside delivery';
    public static $freeShipping = 'Free Shipping';
    public static $resiPickupTitle = '+pu';
    public static $lgPickupTitle = '+lfgpu';
    public static $palletPkgUrl = 'https://us-east.api.3dbinpacking.com/packer/palletPack';
    public static $imageCompleteUrl = 'https://eniture.com/ws/addon/en_images/d549b90ece00d180c5b69a51b6354842/20221207/cd59328e85619fe6b0dc52aa4db034c7/1670418636-7316-1129122.png';
    public static $imageSeparatedUrl = 'https://us-east.api.3dbinpacking.com/images/70785010926d0cc360921e4541811a53/20181106/4c114cebfa2d61a0c8153b3170ab6663/1541503329-2391-8709331.png';
    public static $imageSbsUrl = 'https://us-east.api.3dbinpacking.com/images/70785010926d0cc360921e4541811a53/20181106/4c114cebfa2d61a0c8153b3170ab6663/1541503329-24-8612722.png';
    public static $limitedAccesDelLabel = ' w/ limited access delivery';
    public static $limitedAccessLGDelLable = ' w/ lift gate & limited access delivery';
    public static $twoManDeliveryLabel = ' w/ two man delivery';
    public static $appointmentDeliveryLabel = ' w/ appointment delivery';
    public static $twoManAppDelLabel = ' w/ two man & appointment delivery';
    public static $twoManDelAccess = '+TMD';
    public static $appointmentDelAccess = '+APD';
    public static $twoManAptDelAccess = '+TMD+APD';
    public static $twoManDelResiLabel = ' w/ residential & two man delivery';
    public static $appointmentDelResiLabel = ' w/ residential & appointment delivery';
    public static $twoManAptDelResiLabel = ' w/ residential & two man & appointment delivery';
    public static $defaultMaxWeightSmall = 150;
    public static $QAreportDataUrl = "https://ws001.eniture-qa.com/order-meta/index.php";
    public static $reportDataUrl = "https://analytic-data.eniture.com/index.php";
    public static $replace3dUrl = 'http://images-us-east.api.3dbinpacking.com';
    public static $repplaceWith3dUrl = 'https://images.eniture.com';

    public static function hasInsureCarrier($code)
    {
        $insureCarriers = ['wweltl', 'parcel_12wwe', 'parcel_12ups', 'parcel_12fd'];
        foreach ($insureCarriers as $insureCarrier) {
            if (strpos($code, $insureCarrier) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function getCarrierNameOrCode($code, $getWsCode = 0): ?string
    {
        $carrierCodes = ['wweltl', 'rnlltl', 'xpoltl', 'fedexltl', 'gtzltl', 'cltl', 'upsltl', 'parcel_12wwe', 'parcel_12ups', 'parcel_12fd', 'parcel_12uniship'];
        foreach ($carrierCodes as $carrierCode) {
            if (strpos($code, $carrierCode) !== false) {
                if ($getWsCode == 0) {
                    return self::getCarrierNameFromCode($carrierCode);
                } else {
                    return self::getCarrierCodeWs($carrierCode);
                }
            }
        }
        return null;
    }

    public static function getCarrierCodeWs($carrierCode): ?string
    {
        $carrierCodesWithName = ['wweltl' => 'wweLTL', 'rnlltl' => 'rnl', 'xpoltl' => 'xpoLogistics', 'upsltl' => 'upsLTL',
            'fedexltl' => 'fedexLTL', 'gtzltl' => 'globalTranz', 'cltl' => 'cerasis',
            'parcel_12wwe' => 'wweSmall', 'parcel_12ups' => 'upsSmall', 'parcel_12fd' => 'fedexSmall', 'parcel_12uniship' => 'unishippersSmall'];
        return $carrierCodesWithName[$carrierCode] ?? null;
    }

    public static function getCarrierNameFromCode($carrierCode): ?string
    {
        $carrierCodesWithName = ['wweltl' => 'Worldwide Express LTL', 'upsltl' => 'UPS LTL', 'rnlltl' => 'R&L Carriers', 'xpoltl' => 'XPO Logistics',
            'fedexltl' => 'FedEx LTL', 'gtzltl' => 'GlobalTranz LTL', 'cltl' => 'Cerasis Ltl',
            'parcel_12wwe' => 'Worldwide Express Small', 'parcel_12ups' => 'UPS Small', 'parcel_12fd' => 'FedEx Small', 'parcel_12uniship' => 'Unishippers Small', 
            'fqltl' => 'Freight Quote', 'fqchrltl' => 'C.H. Robinson', 'parcel_12Purolator' => 'Purolator Small', 'parcel_12usps' => 'United State Postal Service', 
            'tqlltl' => 'Total Quality Logistics', 'yrcltl' => 'YRC Freight', 'odflltl' => 'Old Dominion Freight Lines', 'dayrossltl' => 'Day & Ross Ltl', 
            'estesltl' => 'Estes Express Ltl', 'echoltl' => 'Echo Global Logistics', 'saialtl' => 'SAIA LTL Freight', 'abfltl' => 'ABF Freight', 'daylightltl' => 'DayLight LTL Freight', 
            'SouthEastern' => 'Southeastern LTL Freight'];
        return $carrierCodesWithName[$carrierCode] ?? null;


    }

    public static function getCarrierName($carrierCode): ?string
    {
        $carrierCodesWithName = ['wweltl' => 'wwe', 'upsltl' => 'ups', 'rnlltl' => 'rnl', 'xpoltl' => 'xpoLogistics',
            'fedexltl' => 'fedex', 'gtzltl' => 'globaltranz', 'cltl' => 'cerasis',
            'parcel_12wwe' => 'wwe_small_packages_quotes', 'parcel_12ups' => 'ups_small', 'parcel_12fd' => 'fedex_small', 'parcel_12uniship' => 'unishippers_small', 
            'fqltl' => 'freightquote', 'fqchrltl' => 'freightquotechr', 'parcel_12Purolator' => 'purolator_small', 'parcel_12usps' => 'usps_small', 
            'tqlltl' => 'tql', 'yrcltl' => 'yrc', 'odflltl' => 'odfl4me', 'dayrossltl' => 'dayross', 
            'estesltl' => 'estes', 'echoltl' => 'echoLogistics', 'saialtl' => 'saia', 'abfltl' => 'abf', 'daylightltl' => 'daylight', 
            'SouthEastern' => 'southeastern'];

        return $carrierCodesWithName[$carrierCode] ?? null;
    }

    public static function getLiftResidentialStatus($rateId)
    {
        $response = ['resi' => 'n', 'liftG' => 'n', 'resiPickup' => 'n'];
        $response['resi'] = strpos($rateId, '+r') ? 'Y' : 'n';
        $response['liftG'] = strpos($rateId, '+lg') ? 'Y' : 'n';
        $response['resiPickup'] = strpos($rateId, '+pu') ? 'Y' : 'n';
        return $response;
    }

    public static function getBoxName($binId)
    {
        $nickname = BoxSize::getBoxNicknameAndFee($binId);
        return $nickname->nickname ?? null;
    }

    public static function isSmallQuote($quote)
    {
        $quote = explode('(', $quote)[0];
        $quote = trim($quote);
        $small = [
            'UPS Ground',
            'UPS 3 Day Select',
            'UPS 2nd Day Air',
            'UPS 2nd Day Air Saver',
            'UPS Next Day Air Saver',
            'UPS Next Day Air',
            'UPS Next Day Air Early',
            'Fedex Ground',
        ];
        return in_array($quote, $small);
    }

    public static function isSmallCarrier($code)
    {
        $carriers = ['parcel_12wwe', 'parcel_12ups', 'parcel_12fd', 'parcel_12uniship', 'parcel_12usps'];
        foreach ($carriers as $carrier) {
            if (strpos($code, $carrier) !== false) {
                return true;
            }
        }
        return false;
    }


    public static function isExpiredSubscription($endDate): bool
    {
        try {
            if (blank($endDate)) {
                return false;
            }

            $endDate = date('m/d/Y', strtotime($endDate));
            $endDate = new \DateTime($endDate);
            $now = new \DateTime(now());
            // CHecks either the diff is positive or negative
            $invert = $endDate->diff($now)->invert ?? 0;
            $days = $endDate->diff($now)->days ?? 0;
            if ($invert == false && $days > self::$daysAfterExpiry) {
                return true;
            }
            return false;
        } catch (\Exception $exception) {
            Log::info('Exception on checking expiry ' . json_encode($exception->getMessage()));
            return false;
        }

    }


    public static function getDaysBwDates($startDate, $endDate)
    {
        try {
            if (blank($endDate) || blank($startDate)) {
                return 0;
            }
            $startDate = new \DateTime($startDate);
            $endDate = new \DateTime($endDate);
            $days = $endDate->diff($startDate)->days ?? 0;
            return $days;
        } catch (\Exception $exception) {
            Log::info('Exception on checking expiry ' . json_encode($exception->getMessage()));
            return 0;
        }
    }

    public static function fdoSLugForCarriers($carrierSlug)
    {
        $arr = ['small-package' => 'WWE_PL',
            'ltl-quotes' => 'WWE_LTL',
            'gtz-ltl' => 'GTZ',
            'unishippers-small' => 'UNI_PL'
        ];
        if (isset($arr[$carrierSlug])) {
            return $arr[$carrierSlug];
        }
        return null;
    }

    public static function carrierSlugForFdo($carrierSlug)
    {
        $arr = ['WWE_PL' => 'small-package',
            'WWE_LTL' => 'ltl-quotes',
            'GTZ' => 'gtz-ltl',
            'UNI_PL' => 'unishippers-small'
        ];
        if (isset($arr[$carrierSlug])) {
            return $arr[$carrierSlug];
        }
        return null;
    }

    public static function checkMultiUnique($src)
    {
        $output = array_map("unserialize",
            array_unique(array_map("serialize", $src)));
        return $output;
    }

    public static function returnFormExceptionArray($exception)
    {
        return ['line' => $exception->getLine(),
            'file' => $exception->getFile(),
            'message' => $exception->getMessage()];
    }

    public static function log($message, $context = null, $type = 'info')
    {
        Log::$type($message, !blank($context) ? self::returnFormExceptionArray($context) : []);
    }

    public static function isNotSmallShipmentError($quote): bool
    {
        return isset($quote['severity']) && isset($quote['Message']) && $quote['Message'] != self::$smallErrorMessage;
    }


    public static function isNotLtlShipmentError($quote): bool
    {
        return isset($quote['severity']) && isset($quote['Message']) && $quote['Message'] != self::$ltlErrorMessage;
    }

    public static function removeString($word)
    {
        return preg_replace("/[^0-9.]/", "", $word);
    }

    public static function isPOBoxAddress($rad_settings, $isPoBox): bool
    {
        return isset($rad_settings['returRates']) && $rad_settings['returRates'] && $isPoBox;
    }

    public static function arrangeHATFreight($finalQuotes, $HAT, $lableAs = '')
    {
        if (empty($HAT)) {
            return $finalQuotes;
        }
        
        $amount = 0;
        foreach ($HAT as $data) {
            $amount += $data['totalNetCharge']['Amount'];
        }
        
        $hatQuotes[] = [
            'code' => $HAT[0]['serviceType'],
            'title' => $lableAs,
            'rate' => $amount,
        ];

        return array_merge($finalQuotes, $hatQuotes);
    }

    public static function arrangeHATMulti($mulishipment, $HAT)
    {
        $quotes = $mulishipment['simple'] ?? $mulishipment['liftgate'] ?? [];
        $count = 0;
        foreach ($quotes as $shipmentId => $quote) {
            $newQuote = [
                'code' => $HAT[$count]['serviceType'] ?? '',
                'rate' => $HAT[$count]['totalNetCharge']['Amount'] ?? '',
                'title' => $HAT[$count]['serviceDesc'] ?? '',
            ];
     
            $mulishipment['hat'][$shipmentId] = $newQuote;
            $count++;
        }
     
        return $mulishipment;
    }

    public static function getHATPrice($price, $hatPrice){
        if((strlen($hatPrice) > 0)) {
            $symbolicHATFee = strpos($hatPrice, '%') ? '%' : '';
            $hatPrice = (float)$hatPrice ?? 0;
            
            if ($symbolicHATFee === '%') {
                $hatPrice = $hatPrice / 100 * $price;
                $price = $price + $hatPrice;
            } else {
                $price = $price + $hatPrice;
            }
        }
        
        return $price;
    }

    public static function getHATTitle($title = '', $address = [], $hatDistance = '', $phoneNumber = ''){
        $distance = !empty($hatDistance) ? $hatDistance : '0 mi';

        return $title . ' | Hold At Terminal | ' . $distance . ' | ' . $address['city'] . ', ' . $address['state'] . ', ' . $address['zipCode'] . ' | ' . $phoneNumber;
    }

    public static function addQuotesLtlTruckLoad($quotes){
       
        if(isset($quotes['simple']) && !empty($quotes['simple']) && isset($quotes['Truckload']) && !empty($quotes['Truckload'])){
            $key = count($quotes['simple']);
            $quotes['simple'][$key] = $quotes['Truckload'][0];
            $quotes['Truckload'][0]['code'] = 'fqltl+FLGTL';
            isset($quotes['liftgate']) ? $quotes['liftgate'][$key] = $quotes['Truckload'][0] : null;
            unset($quotes['Truckload']);
        }

        return $quotes;
    }

    public static function quotesLtlTruckLoad($allQuotes, $shipments){
       
        if(empty($allQuotes)){
            return [];
        }
        foreach($shipments as $key => $shipment){
            $index[] = $key;
        }
        $allQuotes = self::addQuotesLtlTruckLoad($allQuotes);
        foreach($allQuotes as $key1 => $quotes){
            foreach($quotes as $key2 => $quote){
                $multiShipmentQuotes[$key1][$index[$key2]] = $quote;
            }
        }
    
        return [$allQuotes, $multiShipmentQuotes];

    }

    public static function getSimpleRateBox($items, $bins)
    {
        $binData = $bins[0]->bin_data ?? [];

        if (empty($binData)) {
            return [];
        }

        $box = [
            'length' => $binData->d,
            'width' => $binData->w,
            'height' => $binData->h,
        ];

        foreach ($items as $item) {
            $box['weight'] = $item['lineItemWeight'];
            $box['price'] = $item['lineItemPrice'];
        }

        return $box;
    }
    
    public static function getServerName($storeData)
    {
        $serverName = $storeData['store']['name'];
        
        if (isset($storeData['store']['store_domain']) && !empty($storeData['store']['store_domain'])) {
            $serverName = $storeData['store']['store_domain'];
        } 

        return $serverName;
    }

     private function calculateCartInfo(array $item)
    {
        $itemVolume = ($item['product_length'] * $item['product_widht'] * $item['product_height']);
        $itemWeight = $item['product_weight'];
        $this->cubicVolumeArray[$this->requestKey]['volume'][$item['variant_id']] = $itemVolume;
        $this->cubicVolumeArray[$this->requestKey]['weight'][$item['variant_id']] = $itemWeight;
        $cartInfo = [
            'total_volume' => $this->cartInfo[$this->requestKey]['total_volume'] + ($itemVolume * $item['quantity']),
            'total_weight' => $this->cartInfo[$this->requestKey]['total_weight'] + ($itemWeight * $item['quantity'])
        ];
        
        return $cartInfo;
    }

    public static function calculateCubicVolume($pkgItems = [])
    {
        if (empty($pkgItems)) {
            return [];
        }

        $cubicVolumeArr = [];

        foreach ($pkgItems as $items) {
            foreach ($items as $item) {
                $dimensions = array($item['w'], $item['h'], $item['d']);
                $itemVolume = array_product($dimensions);
                $itemWeight = $item['wg'];
                
                $cubicVolumeArr['volume'][] = $itemVolume;
                $cubicVolumeArr['weight'][] = $itemWeight;
            }
        }

        return $cubicVolumeArr;
    }

    public static function getRADsettings($store_id)
    { 
        $Rad_settings = [];
        if (empty($store_id)) {
            return $Rad_settings;
        }

        $resi_settings = ResidentialSetting::where(['store_id' => $store_id])->first();

        if (!empty($resi_settings)) {

            $settings =  json_decode($resi_settings['settings']);

            return $Rad_settings = [
                'autoDetectedResidentialAddresses' => $settings->residential_delivery_auto_detect ?? false,
                'alwaysResidentialDelivery' => $settings->always_quote_residential_delivery ?? false,
                'returRates' => $settings->return_rates ?? false,
                'unconfirmed_address_type' => $settings->unconfirmed_address_type,
                'residentialPickup' => $settings->always_residential_pickup_delivery,
            ];

        } else {
            return $Rad_settings;
        }
    }

    public static function originAssociatedAccNum($origins, $apiInfo, $carrier)
    {
        $locationsDet = [];
        $connPostCode = $apiInfo['api']['physicalZipCode'] ?? $apiInfo['api']['physicalPostalCode'] ?? 
                        $apiInfo['api']['senderZip'] ?? $apiInfo['senderZip'] ?? $apiInfo['api']['originPostalCode'] ?? 
                        $apiInfo['api']['customerZip'] ?? '' ;
        foreach ($origins as $key => $origin) {
            $senderZip = $origin['senderZip'];
            if ($senderZip == $connPostCode) {
                continue;
            }
            $locationId = $origin['locationId'];
            if (array_key_exists($locationId, $locationsDet)) {
                $locationInfo = $locationsDet[$locationId];
            } else {
                $locationInfo = $locationsDet[$locationId] = LocAssociatedAccountNo::getlocAssociatedAccNo($locationId);
            }

            if ($carrier == 'xpo-ltl'){
                $xpoAccountNumber = $locationInfo['xpo-ltl'] ?? null;
                if (blank($xpoAccountNumber)) {
                    continue;
                }
                $origins[$key]['accountNumber'] = $xpoAccountNumber;

            } else if ($carrier == 'odfl-ltl'){
                $odflAccountNumber = $locationInfo['odfl-ltl'] ?? null;
                if (blank($odflAccountNumber)) {
                    continue;
                }
                $origins[$key]['accountNumber'] = $odflAccountNumber;

            } else if ($carrier == 'saia-ltl'){
                $saiaAccountNumber = $locationInfo['saia-ltl'] ?? null;
                if (blank($saiaAccountNumber)) {
                    continue;
                }
                $origins[$key]['accountNumber'] = $saiaAccountNumber;

            } else if ($carrier == 'southeastern-ltl'){
                $seflAccountNumber = $locationInfo['southeastern-ltl'] ?? null;
                if (blank($seflAccountNumber)) {
                    continue;
                }
                $origins[$key]['accountNumber'] = $seflAccountNumber;

            } else if ($carrier == 'purolator-small'){
                $purolatorAccountNumber = $locationInfo['purolator-small'] ?? null;
                if (blank($purolatorAccountNumber)) {
                    continue;
                }
                $origins[$key]['accountNumber'] = $purolatorAccountNumber;

            } else if ($carrier == 'fedex-ltl'){
                $fedexAccountNumber = $locationInfo['fedex-ltl'] ?? null;
                if (blank($fedexAccountNumber)) {
                    continue;
                }
                $origins[$key]['accountNumber'] = $fedexAccountNumber;

            }
            
        }

        return $origins;
    }

    public static function calProductOriginMarkupFee($cost, $shipmentKey, $items, $allOrigins)
    {  
        $variantKeys = [];
        $productFeeMarkup = 0;
        $totalFeeMarkup = 0;
        $symbolicHandlingFee = '';
        $count = 0;
        $originFeeMarkup = 0;

        // Calculate Origins markup fee
        if(!empty($allOrigins)){
            foreach($allOrigins as $key => $origin){
                if($origin['locationId'] == $shipmentKey){
                    $variantKeys[] = $key;
                    if($count > 0){
                        continue;
                    }

                    if (isset($origin['origin_markup'])) {
                        $originFeeMarkup = (float)$origin['origin_markup'] ?? 0;
                        $symbolicHandlingFee = strpos($origin['origin_markup'], '%') ? '%' : '';
                    }

                    if (strlen($originFeeMarkup) > 0) {
                        if ($symbolicHandlingFee === '%') {
                            $percentVal = $originFeeMarkup / 100 * $cost;
                            $totalFeeMarkup += $percentVal;
                        } else {
                            $totalFeeMarkup += $originFeeMarkup;
                        }
                    }
                    $count++;
                }
            }
            $symbolicHandlingFee = '';
        }
        // Calculate Products markup fee
        if (!empty($items) && !empty($variantKeys)) {
            foreach($items as $item){
                $prodQuantity = ($item['piecesOfLineItem'] ?? 0);
                foreach($variantKeys as $variantId){
                    if($variantId == $item['variant_id']){
                        if (isset($item['product_markup'])) {
                            $productFeeMarkup = (float)$item['product_markup'] ?? 0;
                            $symbolicHandlingFee = strpos($item['product_markup'], '%') ? '%' : '';
                        }
                        $prodcost = $prodQuantity * ($item['lineItemPrice'] ?? 0);

                        if (strlen($productFeeMarkup) > 0) {
                            if ($symbolicHandlingFee === '%') {
                                $percentVal = $productFeeMarkup / 100 * $prodcost;
                                $totalFeeMarkup += $percentVal;
                            } else {
                                $totalFeeMarkup += $productFeeMarkup * $prodQuantity;
                            }
                        }
                    } 
                }
            } 
        }
        return $totalFeeMarkup;
    }
    
    public static function productErrorManagment($carriersErrorSettings, $carriersArray, $itemsArr)
    {
        if(!empty($itemsArr)){
            $count = count($itemsArr);
            foreach($itemsArr as $key => $item){
                if(empty($item['lineItemLength']) || ($item['lineItemLength'] == 0) ||
                   empty($item['lineItemWidth'])  || ($item['lineItemWidth'] == 0)  || 
                   empty($item['lineItemHeight']) || ($item['lineItemHeight'] == 0) ||
                   empty($item['lineItemWeight']) || $item['lineItemWeight'] == 0){
                    if(!(empty($item['lineItemWeight']) || $item['lineItemWeight'] == 0) &&
                     !(empty($item['lineItemClass']) || $item['lineItemClass'] == 0)){
                        continue;
                    }
                    if(!(empty($item['lineItemWeight']) || $item['lineItemWeight'] == 0) && ($item['freightClass'] != 'ltl')){
                        continue;
                    }

                    foreach($carriersArray['carriers'] as $carr => $carrier){
                        if($carriersErrorSettings[$carr] == 2 || $count == 1){
                            unset($carriersArray['carriers'][$carr]);
                            unset($itemsArr[$key]);
                        } elseif($carriersErrorSettings[$carr] == 1){
                            unset($carriersArray['carriers'][$carr]['originAddress'][$key]);
                            unset($itemsArr[$key]);    
                        }
                    }$count--;
                }        
            }
        }

        return ['carriersArray' => $carriersArray, 'itemsArr' => $itemsArr];
    }

    public static function suppressParcelRates($carriers, $items, $storeId)
    {
        $proKeyW = $proKeyD = [];
        $warehouseWeight = $dropshipWeight = 0;
            if(!empty($carriers)){
                foreach($carriers as $key => $carrier){
                    $carrierWeightThreshold = isset($carrier['api']['thresholdWeightLimit']) ? $carrier['api']['thresholdWeightLimit'] : null;
                    if($carrierWeightThreshold === null){
                        continue;
                    }
                    foreach($carrier['originAddress'] as $ori => $origin){
                        if(isset($origin['location']) && $origin['location'] === 'warehouse'){
                            if(!in_array($ori, $proKeyW)){
                                $proKeyW[] = $ori;
                            }
                        }elseif(isset($origin['location']) && $origin['location'] === 'dropship'){
                            if(!in_array($ori,$proKeyD)){
                                $proKeyD[] = $ori;
                            }
                        }
                    }
                    if(!empty($proKeyW)){
                        $warehouseWeight = self::calculatItemseWeight($items, $proKeyW);
                        
                    }
                    if(!empty($proKeyD)){
                        $dropshipWeight = self::calculatItemseWeight($items, $proKeyD);
                    }
                    
                    if($warehouseWeight > $carrierWeightThreshold || $dropshipWeight > $carrierWeightThreshold){
                        $ThresholdSettings = WeightThresholdSettings::where('store_id', $storeId)->first();
                        if($ThresholdSettings['parcel_rates'] == 2){
                            return true;
                        }
                    }
                }
            }
        return false;
    }
    
    public static function calculatItemseWeight($items, $proKeys)
    {
        $totalWeight = 0;
        if(!empty($items) && !empty($proKeys)){
            foreach($proKeys as $key => $proKey){
                foreach($items as $item){
                    if($item['variant_id'] == $proKey){
                        $weight = isset($item['lineItemWeight']) ? $item['lineItemWeight'] : 0;
                        $quantity = isset($item['piecesOfLineItem']) ? $item['piecesOfLineItem'] : 0;
                        $totalWeight += $weight * $quantity;   
                    }
                }
            }
        }
        return $totalWeight;

    }

    public static function replace3DBinUrl($url)
    {
        return str_replace(self::$replace3dUrl, self::$repplaceWith3dUrl, $url) ?? $url;
    }
}