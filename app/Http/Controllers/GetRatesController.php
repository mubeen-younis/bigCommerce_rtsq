<?php

namespace App\Http\Controllers;

use App\CustomClasses\Functions;
use App\CustomClasses\WweLTLShipmentPackage;
use App\Helpers\Helpers;
use App\Models\AdditionalCarrierTabSetting;
use App\Models\Connection;
use App\Models\InstalledAddon;
use App\Models\InstalledCarrier;
use App\Models\Locations;
use App\Models\QuoteSetting;
use App\Models\Store;
use App\Models\Subscription\PackageSubscription;
use Carbon\Carbon;
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
        Log::info('Request ' . json_encode($request->all()));
        $storeHash = $request->base_options['store_id'] ?? null;
        $storeData = $this->getStoreData($storeHash);
        /*Setting Stripe APi key
        Bug fix of plan auto renews
        */
        $isTestStore = Helpers::checkIsTestStore($storeHash);
        Helpers::setStripeAPiKey($isTestStore);

        //echo "<pre>"; print_r($storeData['store']['id']); exit;
        if ($storeData == null) {
            return [];
        }
        if (!$this->storePlanStatus($storeData['store']['id'])) {
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


    }

    function testQuotes()
    {
        $resp = '{"quote_id":"9","messages":[],"carrier_quotes":[{"carrier_info":{"code":"usps_pitney_bowes","display_name":"Freight"},"quotes":[{"code":"upsltl","rate_id":"upsltlidx+01631074742","display_name":"Freight","cost":{"currency":"USD","amount":"181.78"},"dispatch_date":"2021-09-08T04:19:02-00:00"},{"code":"AVG+LG","rate_id":"AVG+LGidx+11631074742","display_name":"Freight","cost":{"currency":"USD","amount":"288.884"},"dispatch_date":"2021-09-08T04:19:02-00:00"},{"code":"fredf","rate_id":"freights21631074742","display_name":"Freight","cost":{"currency":"USD","amount":"10"},"dispatch_date":"2021-09-08T04:19:02-00:00"}]}]}';

        Log::info('testQuotes ' . $resp);
        return json_decode($resp);
    }

    /*
     * Check plan status of store to process quote request
     * **/

    public function storePlanStatus($store_id)
    {
        $subsciption = Subscription::where('store_id', $store_id)->latest()->first();
        if (empty($subsciption) || $subsciption->status === 3) { // not plan or expired plan
            Log::info('Expired Subscription ' . json_encode($subsciption));
            return false;
        }
        if ($subsciption->status === 2) { // not plan or expired plan
            Log::info('Expired Subscription with status 2' . json_encode($subsciption));
            return false;
        }
        if (Functions::isExpiredSubscription($subsciption->ends_at)) { // Expiry date is less then current date
            Log::info('Expired Subscription due to expiry date' . json_encode($subsciption));
            return false;
        }
        return true;

    }


    public function formatRequest($data, $storeData)
    {
        $storeId = $storeData['store']['id'];
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
        $variantKeys = [];
        $wareHouseShipmentExist = false;
        if (count($data['base_options']['items'])) {
            foreach ($data['base_options']['items'] as $productKey => $product) {
                $product_settings = $this->getProductSetting($product['product_id'], $product['variant_id'],$storeId);
                $product_price = $this->getProductPrice($product['product_id'], $product['variant_id']);
                $weight = (isset($product['weight']['value']) && isset($product['weight']['units'])) ? $this->convertWeight($product['weight']['value'], strtolower($product['weight']['units'])) : 0;
                $ltlCheck = $product_settings['freight_enabled'] ?? false;
                $shipBinAlone = (isset($product_settings['ship_multiple_package']) && $product_settings['ship_multiple_package'])
                || (isset($product_settings['ship_own_package']) && $product_settings['ship_own_package']) ? 1 : 0;
                $key = $product['variant_id'] ?? $product['product_id'];
                /*Added this block of code for catering an item with diff product rules*/
                if (!empty($variantKeys) && array_key_exists($key, $variantKeys)) {
                    $key = $key . $productKey;
                }
                $variantKeys[$key] = $key;

                // $originAddress = $this->shipmentPkg->wweLTLOriginAddress($details, $product_settings, $details['destination']['zip'], $storeData, $this->connectionSettings);

                $dropshipEnabled = $product_settings['dropship_enabled'] ?? false;
                if ($dropshipEnabled) {
                    $originAddress = $this->shipmentPkg->getDropshipLocationDetail($product_settings['dropship_location'], $details['destination']['zip']);
                    if (blank($originAddress)) {
                        Log::info('No dropship Location Found');
                        return null;
                    }
                } else {
                    $originAddress = 'warehouse';
                    $wareHouseShipmentExist = true;
                }

                $details['origin'][$key] = $originAddress;


                $details['items'][$key] = [
                    'id' => $product_settings['id'] ?? '',
                    'product_id' => $product['product_id'] ?? '',
                    'variant_id' => $product['variant_id'] ?? '',
                    'sku' => $product['sku'] ?? '',
                    'piecesOfLineItem' => $product['quantity'] ?? '',
                    'originalPiecesOfLineItem' => $product['quantity'] ?? '',
                    'shipMultiplePackage' => $product_settings['ship_multiple_package'] ?? 0,
                    'shipBinAlone' => $shipBinAlone,
                    'lineItemId' => $product['product_id'] ?? '',
                    'lineItemPrice' => $product_price ?? 0,
                    'lineItemName' => $product['name'] ?? '',
                    'lineItemLength' => $product['length']['value'] ? number_format($product['length']['value'], 2, '.', '') : '',
                    'lineItemWidth' => $product['width']['value'] ? number_format($product['width']['value'], 2, '.', '') : '',
                    'lineItemHeight' => $product['height']['value'] ? number_format($product['height']['value'], 2, '.', '') : '',
                    'lineItemWeight' => number_format($weight, 2, '.', ''),
                    'freight_enabled' => isset($product_settings['freight_enabled']) && $product_settings['freight_enabled'] ? 'Y' : 'N',

                    'vertical_rotation' => isset($product_settings['allow_vertical']) && $product_settings['allow_vertical'] ? '1' : '0',
                    'isHazmatLineItem' => isset($product_settings['hazardous_enabled']) && $product_settings['hazardous_enabled'] ? 'Y' : 'N',
                    'dropship_enabled' => isset($product_settings['dropship_enabled']) && $product_settings['dropship_enabled'] ? 'Y' : 'N',
                    'dropship' => $product_settings['dropship'] ?? '',
                    'product_insurance_active' => isset($product_settings['insurance']) && $product_settings['insurance'] ? 1 : 0,
                    'freightClass' => $this->isLTL($weight, $ltlCheck) ? 'ltl' : '', //ltl for testing
                    //'freightClass' => '',
                    'lineItemClass' => isset($product_settings['freight_class']) ? $this->getLineItemClass($product_settings['freight_class']) : '',
                    'shipping_group' => $product_settings['shipping_group'] ?? null,
                    'exclude_packaging' => 0,
                    'quote_as_local' => $product_settings['quote_as_local'] ?? false
                ];


                if (!$details['items'][$key]['shipMultiplePackage']) {
                    if (
                        (blank($details['items'][$key]['lineItemLength']) || $details['items'][$key]['lineItemLength'] <= 0) ||
                        (blank($details['items'][$key]['lineItemWidth']) || $details['items'][$key]['lineItemWidth'] <= 0) ||
                        (blank($details['items'][$key]['lineItemHeight']) || $details['items'][$key]['lineItemHeight'] <= 0)
                    ) {
                        $details['items'][$key]['exclude_packaging'] = 1;
                        $details['items'][$key]['shipBinAlone'] = 1;
                    }
                }

            }
        }


        if ($wareHouseShipmentExist) {
            $originAddress = $this->shipmentPkg->getNearestWarehouse($details, $details['destination']['zip'], $storeData, $this->connectionSettings);
            if (blank($originAddress)) {
                Log::info('No warehouse added');
                return null;
            }
            $originAddress = $this->getAddressForQuotes($originAddress);
            foreach ($details['origin'] as $key => $origin) {
                if ($origin == "warehouse") {
                    $details['origin'][$key] = $originAddress;
                }
            }
        }
        return ['lineItemData' => $details];
    }


    public function getAddressForQuotes($originAddress)
    {

        $locationAdditionalDetail = Locations::getLocationAdditionalDetail($originAddress['locationId']);
        if (is_string($locationAdditionalDetail) && $locationAdditionalDetail == "default") {
            return $originAddress;
        }
        if (is_string($locationAdditionalDetail) && $locationAdditionalDetail == "suppress") {
            $originAddress['InstorPickupLocalDelivery']['suppress'] = 1;
            return $originAddress;
        }
        return $this->changeOriginDetail($originAddress, $locationAdditionalDetail);

    }


    public function changeOriginDetail($originAddress, $locationAdditionalDetail)
    {
        $originAddress['originForIPLDFlag'] = 1;
        $originAddress['instorSenderCity'] = $originAddress['senderCity'];
        $originAddress['instorSenderState'] = $originAddress['senderState'];
        $originAddress['instorSenderZip'] = $originAddress['senderZip'];
        $originAddress['instorSenderCountryCode'] = $originAddress['senderCountryCode'];
        $originAddress['instore_and_loc_id'] = $locationAdditionalDetail['id'];
        // $originAddress['locationId'] = $originAddress['locationId'];
        $originAddress['senderZip'] = $locationAdditionalDetail['zip_code'];
        $originAddress['senderCity'] = $locationAdditionalDetail['city'];
        $originAddress['senderState'] = $locationAdditionalDetail['state'];
        $originAddress['senderCountryCode'] = $locationAdditionalDetail['country'];
        return $originAddress;
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

    public function getProductSetting($productId, $variantId,$storeId)
    {
        $settings = [];
        $productSetting = ProductSetting::select('settings', 'id', 'dropship_enabled', 'dropship_location', 'shipping_group', 'ship_multiple_package')
            ->where(['source_product_id' => $productId, 'variant_id' => $variantId,'store_id'=>$storeId])
            ->first();
        if (!empty($productSetting)) {
            $productSetting->toArray();
            $settings = isset($productSetting['settings']) ? json_decode($productSetting['settings'], true) : [];
            $settings['id'] = $productSetting['id'];
            $settings['dropship_enabled'] = $productSetting['dropship_enabled'];
            $settings['dropship_location'] = $productSetting['dropship_location'];
            $settings['ship_multiple_package'] = $productSetting['ship_multiple_package'];
            $settings['shipping_group'] = $productSetting['shipping_group'];
        }
        return $settings;
    }

    private function getProductPrice($productId, $variantId)
    {
        return ProductSetting::where(['source_product_id' => $productId, 'variant_id' => $variantId])
            ->pluck('price')->first();
    }

    public function convertWeight($value, $unit)
    {
        switch ($unit) {
            case 'oz' :
                return $value / 16;
            case 'kg':
                return $value / 0.45359237;
            case 'g':
                return $value / 453.59237;
            case 't':
                return $value / 0.00045359237;
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

                    'addons.short_code' => 'SBS',
                ])
                ->exists();
            $enabledAddonSbs = false;
            if ($installedAddonSbs) {
                $addonSbs = PackageSubscription::leftJoin('packages as p', 'package_subscriptions.package_id', '=', 'p.id')
                    ->where('store_id', $store->id)
                    ->where('addon_type', 'SBS')
                    ->where('package_subscriptions.status', '!=', 3)
                    ->select('package_subscriptions.id', 'package_subscriptions.status', 'package_subscriptions.created_at')
                    ->latest()->first();
                if (!blank($addonSbs)) {
                    $enabledAddonSbs = true;
                }
            }

            $installedAddonRad = InstalledAddon::join('addons', 'addons.id', 'installed_addons.addon_id')
                ->where(['installed_addons.store_id' => $store->id,
                    'installed_addons.is_enabled' => 1,
                    //'installed_addons.is_suspend' => 0,
                    //'installed_addons.is_expired' => 0,
                    'addons.short_code' => 'RAD',
                ])
                ->exists();
            $enabledAddonRad = false;
            if ($installedAddonRad) {
                $addonRad = PackageSubscription::leftJoin('packages as p', 'package_subscriptions.package_id', '=', 'p.id')
                    ->where('store_id', $store->id)
                    ->where('addon_type', 'RAD')
                    ->where('package_subscriptions.status', '!=', 3)
                    ->select('package_subscriptions.id', 'package_subscriptions.status', 'package_subscriptions.created_at')
                    ->latest()->first();
                if (!blank($addonRad)) {
                    $enabledAddonRad = true;
                }
            }
            if (!empty($installedCarriers) && count($installedCarriers)) {
                return [
                    'installed_carriers' => $installedCarriers,
                    'installed_addons' => $installedAddons,
                    'store' => $store,
                    'installed_addon_sbs' => $installedAddonSbs,
                    'installed_addon_rad' => $installedAddonRad,
                    'enabled_addon_sbs' => $enabledAddonSbs,
                    'enabled_addon_rad' => $enabledAddonRad
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
