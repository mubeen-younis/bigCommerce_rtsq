<?php

namespace App\Http\Controllers;

use App\CustomClasses\WweLTLShipmentPackage;
use App\Models\AdditionalCarrierTabSetting;
use App\Models\Connection;
use App\Models\InstalledAddon;
use App\Models\InstalledCarrier;
use App\Models\Locations;
use App\Models\QuoteSetting;
use App\Models\Store;
use Illuminate\Http\Request;
use App\CustomClasses\Origin;
use App\Models\ProductSetting;
use App\CustomClasses\Shipping;
use Illuminate\Support\Facades\Log;

class GetRatesController extends Controller
{
    public $shipping = null;
    public $quoteSettings = [];
    public $connectionSettings = [];
    public $installedCarriers = [];
    public $installedAddons = [];

    /**
     * @var WweLTLShipmentPackage
     */
    private $shipmentPkg;

    public function __construct()
    {
        $this->shipping = new Shipping();
        $this->shipmentPkg = new WweLTLShipmentPackage();
    }

    /*
     * returnRates will use to parse request
     */

    public function returnRates(Request $request)
    {
        $storeHash = $request->base_options['store_id'] ?? null;
        $storeData = $this->getStoreData($storeHash);

        if ($storeData == null) {
            return [];
        }
// Getting installed carriers there quote settings and services
        $this->getCarrierSettings($storeData['installed_carriers']);
        $formatReq = $this->formatRequest($request->all(), $storeData);

        if (
            $formatReq['lineItemData']['destination']['zip'] == null ||
            $formatReq['lineItemData']['destination']['state'] == null ||
            $formatReq['lineItemData']['destination']['country'] == null ||
            //$formatReq['lineItemData']['destination']['city'] == null ||
            count($this->connectionSettings) == 0
        ) {

            return [];
        }

        $quotes = $this->shipping->collectRates($formatReq, $storeData, $this->connectionSettings);

        return $this->generateQuoteFormatResponse($quotes);
        exit;
        $originWarehouse = new Origin();
        $originWarehouse->getNearestWarehouse($formatReq);
    }

    public function generateQuoteFormatResponse($quotes)
    {

        if (!empty(array_filter($quotes))) {
            $resp['quote_id'] = "2";// need to change
            $resp['messages'] = [];// need to change
            $resp['carrier_quotes'][0] = ['carrier_info' => ['code' => 'usps_pitney_bowes', 'display_name' => $quotes[0]['title'] ?? '']];
           // dd($quotes);
            foreach ($quotes as $key => $quote) {
                $resp['carrier_quotes'][0]['quotes'][$key] = [
                    'code' => $quote['code'],
                    'rate_id' => '9vcV1JfckPJZW2pjeNXcKP5y',
                    'display_name' => $quote['title'],
                    'cost' => ['currency' => 'USD', 'amount' => $quote['rate']],
                    'transit_time' => ['units' => 'BUSINESS_DAYS', 'duration' => 1],
                    // TODO: Will be set
                    'dispatch_date' => '2021-03-19T00:00:00-05:00'
                ];
            }
        } else {
            $resp = [];
        }
        /*$resp = array (
            'quote_id' => '2',
            'messages' =>
                array (

                ),
            'carrier_quotes' =>
                array (
                    0 =>
                        array (
                            'carrier_info' =>
                                array (
                                    'code' => 'usps_pitney_bowes',
                                    'display_name' => 'USPS',
                                ),
                            'quotes' =>
                                array (
                                    0 =>
                                        array (
                                            'code' => 'ODFL',
                                            'rate_id' => '9vcV1JfckPJZW2pjeNXcKP5y',
                                            'display_name' => 'Freight online',
                                            'cost' =>
                                                array (
                                                    'currency' => 'USD',
                                                    'amount' => 1000,
                                                ),
                                            'transit_time' =>
                                                array (
                                                    'units' => 'BUSINESS_DAYS',
                                                    'duration' => 1,
                                                ),
                                            'dispatch_date' => '2021-03-19T00:00:00-05:00',
                                        ),

                                ),
                        ),

                ),
        );*/
      /*  echo "<pre>";
        print_r($resp);
        exit;*/
        return $resp;
    }

    public function formatRequest($data, $storeData)
    {
        $details = [
            'destination' => [
                'street_1' => $data['base_options']['destination']['street_1'] ?? null,
                'street_2' => $data['base_options']['destination']['street_2'] ?? null,
                'zip' => $data['base_options']['destination']['zip'] ?? null,
                'city' => $data['base_options']['destination']['city'] ?? null,
                'state' => $data['base_options']['destination']['state_iso2'] ?? null,
                'country' => $data['base_options']['destination']['country_iso2'] ?? null,
                'address_type' => $data['base_options']['destination']['address_type'] ?? null,
            ]
        ];

        if (count($data['base_options']['items'])) {
            foreach ($data['base_options']['items'] as $product) {
                $product_settings = $this->getProductSetting($product['product_id'], $product['variant_id']);
                $weight = (isset($product['weight']['value']) && isset($product['weight']['units'])) ? $this->convertWeight($product['weight']['value'], strtolower($product['weight']['units'])) : 0;
                $ltlCheck = $product_settings['freight_enabled'] ?? false;

                $originAddress = $this->shipmentPkg->wweLTLOriginAddress($details, $product_settings, $details['destination']['zip'], $storeData, $this->connectionSettings);

                $details['origin'][$product['product_id']] = $originAddress;
                $details['items'][$product['product_id']] = [
                    'product_id' => $product['product_id'] ?? '',
                    'variant_id' => $product['variant_id'] ?? '',
                    'sku' => $product['sku'] ?? '',
                    'piecesOfLineItem' => /*$product['quantity'] ?? ''*/
                        2,
                    'lineItemId' => $product['product_id'] ?? '',
                    'lineItemName' => $product['name'] ?? '',
                    'lineItemLength' => $product['length']['value'] ?? '',
                    'lineItemWidth' => $product['width']['value'] ?? '',
                    'lineItemHeight' => $product['height']['value'] ?? '',
                    'lineItemWeight' => /*$weight*/
                        80,
                    'freight_enabled' => isset($product_settings['freight_enabled']) && $product_settings['freight_enabled'] ? 'Y' : 'N',
                    'isHazmatLineItem' => isset($product_settings['hazardous_enabled']) && $product_settings['hazardous_enabled'] ? 'Y' : 'N',
                    'dropship_enabled' => isset($product_settings['dropship_enabled']) && $product_settings['dropship_enabled'] ? 'Y' : 'N',
                    'dropship' => $product_settings['dropship'] ?? '',
                    'product_insurance_active' => isset($product_settings['insurance']) && $product_settings['insurance'] ? 'Y' : 'N',
                    //'freightClass' => $this->isLTL($weight, $ltlCheck) ? $this->isLTL($weight, $ltlCheck) : 'ltl', //ltl for testing
                    'freightClass' => '',
                    'lineItemClass' => isset($product_settings['freight_class']) ? $this->getLineItemClass($product_settings['freight_class']) : '',
                ];
            }
        }

        return ['lineItemData' => $details];
    }

    /**
     * @param $weight
     * @param $ltlCheck
     * @return string
     */
    private function isLTL($weight, $ltlCheck)
    {
        //$weightConfigExceedOpt = $this->quoteSettings[''];
        if ($ltlCheck || ($weight > 150)) { // && $weightConfigExceedOpt
            $freightClass = 'ltl';
        } else {
            $freightClass = '';
        }

        return $freightClass;
    }

    /**
     * @param $lineItemClass
     * @return float|int|string
     */
    private function getLineItemClass($lineItemClass)
    {
        switch ($lineItemClass) {
            case 77:
                $lineItemClass = 77.5;
                break;
            case 92:
                $lineItemClass = 92.5;
                break;
            case 1:
                $lineItemClass = 'DensityBased';
                break;
            default:
                break;
        }
        return $lineItemClass;
    }

    public function getProductSetting($productId, $variantId)
    {
        $settings = [];
        $productSetting = ProductSetting::select('settings')
            ->where(['source_product_id' => $productId /*, 'variant_id' => $variantId*/])
            ->first();
        if (!empty($productSetting)) {
            $productSetting->toArray();
            $settings = isset($productSetting['settings']) ? json_decode($productSetting['settings'], true) : [];
        }
        return $settings;
    }

    public function convertWeight($value, $unit)
    {
        switch ($unit) {
            case 'oz' :
                return $value / 16;
                break;
            default:
                return $value;
        }
    }

    public function getStoreData($storeHash)
    {
        if ($storeHash == null) {
            return null;
        }
        /* $storeHash = explode('/', $storeHash)[1] ?? null;
         if ($storeHash == null){
             return null;
         }*/
        $store = Store::where(['hash' => $storeHash, 'app_status' => 1])->first();
        if (!empty($store)) {
            $installedCarriers = InstalledCarrier::where(['store_id' => $store->id, 'is_enabled' => 1])->get();
            $installedAddons = InstalledAddon::where(['store_id' => $store->id, 'is_enabled' => 1])->get();
            if (!empty($installedCarriers) && count($installedCarriers)) {
                return [
                    'installed_carriers' => $installedCarriers,
                    'installed_addons' => $installedAddons,
                    'store' => $store
                ];
            }
        }
        return null;
    }

    public function getInstalledCarriers($storeHash)
    {

    }

    public function getCarrierSettings($installedCarriers)
    {
        if (!empty($installedCarriers)) {
            foreach ($installedCarriers as $installedCarrier) {
                $connectionSettings = Connection::join('installed_carriers', 'installed_carriers.id', 'connection_settings.installed_carrier_id')
                    ->join('carriers', 'carriers.id', 'installed_carriers.carrier_id')
                    ->select('carriers.slug', 'connection_settings.id', 'connection_settings.installed_carrier_id',
                        'connection_settings.value')
                    ->where('connection_settings.installed_carrier_id', $installedCarrier->id)->first();
                Log::info('id ' . json_encode($installedCarrier->id));
                if ($connectionSettings !== null) {

                    $this->connectionSettings[$connectionSettings->slug]['creds'] = json_decode($connectionSettings->value, true);

                    $quoteSettings = QuoteSetting::where('installed_carrier_id', $installedCarrier->id)->first();
                    if (isset($quoteSettings->value)) {
                        $this->connectionSettings[$connectionSettings->slug]['quote_settings'] = json_decode($quoteSettings->value, true);
                    }
                    $carrierServices = AdditionalCarrierTabSetting::where('installed_carrier_id', $installedCarrier->id)->first();
                    if (isset($carrierServices->value)) {
                        //$installedCarrier->carrier_id
                        $this->connectionSettings[$connectionSettings->slug]['carrier_services'] = json_decode($carrierServices->value, true);
                    }
                }
            }
        }
    }

    public function getQuoteSettings()
    {

    }
}
