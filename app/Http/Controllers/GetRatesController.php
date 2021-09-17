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
use App\Models\Subscription\Subscription;
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
        //echo "<pr>"; print_r($request->all()); exit;
        Log::info('Request ' . json_encode($request->all()));
        $storeHash = $request->base_options['store_id'] ?? null;
        $storeData = $this->getStoreData($storeHash);
        //echo "<pre>"; print_r($storeData['store']['id']); exit;

        if ($storeData == null) {
            return [];
        }
        if(!$this->storePlanStatus($storeData['store']['id'])){
            return [];
        }
        //echo "<pre>"; print_r($storeData['installed_carriers'][0]['store_id']); exit;
        $cartInfo['cartId'] = $request->base_options['request_context']['reference_values'][0]['value'] ?? 0;
        $cartInfo['store_id'] = $storeData['installed_carriers'][0]['store_id'] ?? 0;
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
        $quotes = $this->shipping->collectRates($formatReq, $storeData, $this->connectionSettings, $cartInfo);
        return $quotes;
       // return $this->generateQuoteFormatResponse($quotes);
        exit;
        $originWarehouse = new Origin();
        $originWarehouse->getNearestWarehouse($formatReq);
    }

    /*
     * Check plan status of store to process quote request
     * **/

    public function storePlanStatus($store_id){
        $subsciption = Subscription::where('store_id', $store_id)->latest()->first();
        if(empty($subsciption) || $subsciption->status === 3){ // not plan or expired plan
            return false;
        }else{
            return true;
        }
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
                $product_price = $this->getProductPrice($product['product_id'], $product['variant_id']);
                $weight = (isset($product['weight']['value']) && isset($product['weight']['units'])) ? $this->convertWeight($product['weight']['value'], strtolower($product['weight']['units'])) : 0;
                // $weight=148;

                $ltlCheck = $product_settings['freight_enabled'] ?? false;

                $originAddress = $this->shipmentPkg->wweLTLOriginAddress($details, $product_settings, $details['destination']['zip'], $storeData, $this->connectionSettings);

                $key = $product['variant_id'] ?? $product['product_id'];
                $details['origin'][$key] = $originAddress;
                $details['items'][$key] = [
                    'product_id' => $product['product_id'] ?? '',
                    'variant_id' => $product['variant_id'] ?? '',
                    'sku' => $product['sku'] ?? '',
                    'piecesOfLineItem' => $product['quantity'] ?? ''
                    ,
                    'lineItemId' => $product['product_id'] ?? '',
                    'lineItemPrice' => $product_price ?? 0,
                    'lineItemName' => $product['name'] ?? '',
                    'lineItemLength' => $product['length']['value'] ? number_format($product['length']['value'], 2, '.', '') : '',
                    'lineItemWidth' => $product['width']['value'] ? number_format($product['width']['value'], 2, '.', '') : '',
                    'lineItemHeight' => $product['height']['value'] ? number_format($product['height']['value'], 2, '.', '') : '',
                    'lineItemWeight' => number_format($weight, 2, '.', ''),
                    'freight_enabled' => isset($product_settings['freight_enabled']) && $product_settings['freight_enabled'] ? 'Y' : 'N',
                    'shipBinAlone' => isset($product_settings['ship_bin_alone']) && $product_settings['ship_bin_alone'] ? '1' : '0',
                    'vertical_rotation' => isset($product_settings['vertical_rotation']) && $product_settings['vertical_rotation'] ? '1' : '0',
                    'isHazmatLineItem' => isset($product_settings['hazardous_enabled']) && $product_settings['hazardous_enabled'] ? 'Y' : 'N',
                    'dropship_enabled' => isset($product_settings['dropship_enabled']) && $product_settings['dropship_enabled'] ? 'Y' : 'N',
                    'dropship' => $product_settings['dropship'] ?? '',
                    'product_insurance_active' => isset($product_settings['insurance']) && $product_settings['insurance'] ? 1 : 0,
                    'freightClass' => $this->isLTL($weight, $ltlCheck) ? 'ltl' : '', //ltl for testing
                    //'freightClass' => '',
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
            $freightClass = true;
        } else {
            $freightClass = false;
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
            ->where(['source_product_id' => $productId , 'variant_id' => $variantId])
            ->first();
        if (!empty($productSetting)) {
            $productSetting->toArray();
            $settings = isset($productSetting['settings']) ? json_decode($productSetting['settings'], true) : [];
        }
        return $settings;
    }

    private function getProductPrice($productId, $variantId){
        return ProductSetting::where(['source_product_id' => $productId , 'variant_id' => $variantId])
            ->pluck('price')->first();
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
            $installedAddonSbs = InstalledAddon::join('addons', 'addons.id', 'installed_addons.addon_id')
                ->where(['installed_addons.store_id' => $store->id,
                    'installed_addons.is_enabled' => 1,
                    //'installed_addons.is_suspend' => 0,
                    //'installed_addons.is_expired' => 0,
                    'addons.short_code' => 'SBS',
                ])
                ->exists();
            $installedAddonRad = InstalledAddon::join('addons', 'addons.id', 'installed_addons.addon_id')
                ->where(['installed_addons.store_id' => $store->id,
                    'installed_addons.is_enabled' => 1,
                    //'installed_addons.is_suspend' => 0,
                    //'installed_addons.is_expired' => 0,
                    'addons.short_code' => 'RAD',
                ])
                ->exists();
            if (!empty($installedCarriers) && count($installedCarriers)) {
                return [
                    'installed_carriers' => $installedCarriers,
                    'installed_addons' => $installedAddons,
                    'store' => $store,
                    'installed_addon_sbs' => $installedAddonSbs,
                    'installed_addon_rad' => $installedAddonRad
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
