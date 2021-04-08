<?php

namespace App\CustomClasses;

use App\Constants\Constant;
use App\CustomClasses\CompileQuotes;
use Illuminate\Support\Facades\Log;

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
    public function collectRates($request, $storeData, $connectionSettings)
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
        $this->isHazmatMaterial($package['items']);
        if ($this->isHazmat == 'Y') {
            $carriersArray['api']['lineItemHazmatInfo'] = [
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

// Genearting final request Array
        $requestArr = $generateReqData->generateRequestArray($request, $carriersArray, $package['items']);

      /*  echo json_encode($requestArr);die();*/


        /* $requestArr['carriers']['wweLTL']['licenseKey'] = $requestArr['carriers']['wweLTL']['licenseKey']['license_key'];*/
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
        $finalQuotes = $this->compileQuotes->newGetQuotesResults($quotes, $connectionSettings, $package['origin']);
        $resp = $this->setCarrierRates($finalQuotes);
        return $resp;
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
        foreach ($items as $item) {
            if (isset($item['isHazmatLineItem']) && $item['isHazmatLineItem'] == 'Y') {
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
