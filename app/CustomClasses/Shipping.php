<?php

namespace App\CustomClasses;

use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;
use App\CustomClasses\WweLTL\WweLTLGenerateRequestData;
use Illuminate\Support\Facades\Log;

class Shipping {

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
    public function collectRates($request, $storeData, $connectionSettings, $quoteSettings)
    {
        $generateReqData = new WweLTLGenerateRequestData();
        $generateReqData->_init($quoteSettings, $connectionSettings, $storeData);
        $request1 = $request;
        $package = $request['lineItemData'];
        $wweLtlArr = $generateReqData->generateEnitureArray();
        $this->isHazmatMaterial($package['items']);
        if ($this->isHazmat == 'Y'){
            $wweLtlArr['api']['lineItemHazmatInfo'] = [
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

        $wweLtlArr['originAddress'] = $package['origin'];

        $requestArr = $generateReqData->generateRequestArray($request, $wweLtlArr, $package['items']);
        $requestArr['carriers']['wweLTL']['licenseKey'] = $requestArr['carriers']['wweLTL']['licenseKey']['license_key'];
        if (empty($requestArr)) {
            return false;
        }
        $url = Constant::QUOTES_URL;

        $quotes = $this->sendCurlRequest($url, $requestArr);
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
        $finalQuotes = $this->compileQuotes->getQuotesResults($quotes, $quoteSettings, $package['origin']);
        $resp = $this->setCarrierRates($finalQuotes);
        return $resp;
    }

    /**
     * to enable hazmat property for Api
     */
     public function isHazmatMaterial($items){
         foreach($items as $item){
             Log::info('$item->isHazmatLineItem '. $item->isHazmatLineItem);
             if(isset($item->isHazmatLineItem) && $item->isHazmatLineItem == 'Y'){
                 $this->isHazmat = 'Y';
                 break;
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
            return json_decode($output, true);
        } catch (\Throwable $e) {
            $result = [];
        }
        return $result;
    }
}
