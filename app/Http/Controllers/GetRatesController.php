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
use App\Models\CountryState;
use App\Models\Subscription\PackageSubscription;
use Illuminate\Http\Request;
use App\Models\ProductSetting;
use App\CustomClasses\Shipping;
use App\Models\Subscription\Subscription;
use Illuminate\Support\Facades\Log;
use App\CustomClasses\CompareRates;
use App\Constants\Constant;
use App\Models\ShippingRule;

class GetRatesController extends Controller
{
    public $shipping = null;
    public $quoteSettings = [];
    public $connectionSettings = [];
    public $installedCarriers = [];
    public $installedAddons = [];
    public $updatedWarehouses = [];
    public $setRulePriority = null;

    /**
     * @var WweLTLShipmentPackage
     */
    private $shipmentPkg;
    /**
     * @var WweLTLShipmentPackage
     */
    private $isDbscInstalled;

    public function __construct()
    {
        $this->shipping = new Shipping();
        $this->shipmentPkg = new WweLTLShipmentPackage();
        $this->isDbscInstalled = false;
    }

    /*
     * returnRates will use to parse request
     */

    public function returnRates(Request $request, $count = null)
    {
        Log::info('Request ' . json_encode($request->all()));
        $storeHash = $request->base_options['store_id'] ?? null;
        $storeData = $this->getStoreData($storeHash);
        /*Setting Stripe APi key
        Bug fix of plan auto renews
        */
        $isTestStore = Helpers::checkIsTestStore($storeHash);
        Helpers::setStripeAPiKey($isTestStore);
        if ($storeData == null) {
            return [];
        }
        if (!$this->storePlanStatus($storeData['store']['id'])) {
            return [];
        }
        // Getting cart id and store id of the store.
        $refValue = $request->base_options['request_context']['reference_values'] ?? [];
        $cartID = "";
        foreach ($refValue as $value) {
            if ($value['name'] === 'cart_id') {
                $cartID = $value['value'] ?? "";
            }
        }

        $cartInfo['is_draft_order']=!empty($cartID) ? false: true;
        $cartInfo['cartId'] = !empty($cartID) ? $cartID : "draft_" . time() . "_" . $storeData['store']['id'];
        $cartInfo['store_id'] = $storeData['installed_carriers'][0]['store_id'] ?? 0;
        // Getting installed carriers there quote settings and services
        $this->getCarrierSettings($storeData['installed_carriers']);

        $this->formatReq = $this->formatRequest($request->all(), $storeData);
        if (
            $this->formatReq['lineItemData']['destination']['zip'] == null ||
            $this->formatReq['lineItemData']['destination']['country'] == null ||
            count($this->connectionSettings) == 0
        ) {
            if (!$this->isDbscInstalled) {
                return [];
            }
        }
        
        if($this->isShippingRule($storeData, $this->formatReq)){
            return [];
        }

        $quotes = $this->shipping->collectRates($this->formatReq, $storeData, $this->connectionSettings, $cartInfo, $this->isDbscInstalled);

        return $quotes;


    }

    public function getCompareRates(Request $request)
    {
        Log::info('Compare Rates Request ' . json_encode($request->all()));
        $CompareRates = new CompareRates();
        $storeHash = $request['store_hash'] ?? null;
        $storeData = $CompareRates->getData($storeHash);

        $isTestStore = Helpers::checkIsTestStore($storeHash);
        Helpers::setStripeAPiKey($isTestStore);

        if ($storeData == null) {
            return [];
        }
        if (!$this->storePlanStatus($storeData['store']['id'])) {
            return [];
        }

        $this->getCarrierSettings($storeData['installed_carriers']);

        $CompareRates = new CompareRates();

        $formatCompareRateRequest = $CompareRates->formatCompareRateRequest($request->all(), $storeData, $this->connectionSettings, $request['carriers']);

        $url = Constant::QUOTES_URL;
        $quotes = $this->sendCurlRequest($url, $formatCompareRateRequest['requestArr']);
        
        $finalCompareRates = $CompareRates->getCompareRates($quotes, $this->connectionSettings);
        
        if(!empty($finalCompareRates) && gettype($finalCompareRates) !== 'string'){
            return $response = [
                'error' => false,
                'message' => 'Quotes are successfully updated.',
                'data' => $finalCompareRates,
            ];  
        }
        return $response = [
            'error' => true,
            'message' => $finalCompareRates,
            'data' => [],
        ];
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
        if ($subsciption->status === 2 && Functions::isExpiredSubscription($subsciption->ends_at)) { // not plan or expired plan
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
                'city' => str_replace("'", '', $data['base_options']['destination']['city']) ?? null,
                'state' => $data['base_options']['destination']['state_iso2'] ?? null,
                'country' => $data['base_options']['destination']['country_iso2'] ?? null,
                'address_type' => $data['base_options']['destination']['address_type'] ?? null,
            ]
        ];
        $variantKeys = [];
        $wareHouseShipmentExist = false;
        if (count($data['base_options']['items'])) {
            foreach ($data['base_options']['items'] as $productKey => $product) {
                $product_settings = $this->getProductSetting($product['product_id'], $product['variant_id'], $storeId);
                $productBrandId = $this->getProductBrand($product['product_id'], $product['variant_id'], $storeId);
                $categoriesId = $this->getProductCategories($product['product_id'], $product['variant_id'], $storeId);
                $product_price = $this->getProductPrice($product['product_id'], $product['variant_id'], $storeId);
                $weight = (isset($product['weight']['value']) && isset($product['weight']['units'])) ? $this->convertWeight($product['weight']['value'], strtolower($product['weight']['units'])) : 0;
                $length = (isset($product['length']['value']) && isset($product['length']['units'])) ? $this->convertDimensionUnit($product['length']['value'], strtolower($product['length']['units'])) : 0;
                $width = (isset($product['width']['value']) && isset($product['width']['units'])) ? $this->convertDimensionUnit($product['width']['value'], strtolower($product['width']['units'])) : 0;
                $height = (isset($product['height']['value']) && isset($product['height']['units'])) ? $this->convertDimensionUnit($product['height']['value'], strtolower($product['height']['units'])) : 0;
                $ltlCheck = $product_settings['freight_enabled'] ?? false;
                $shipBinAlone = (isset($product_settings['ship_multiple_package']) && $product_settings['ship_multiple_package'])
                || (isset($product_settings['ship_own_package']) && $product_settings['ship_own_package']) ? 1 : 0;

                $key = $product['variant_id'] ?? $product['product_id'];
                /*Added this block of code for catering an it 56yuk  g5 E
                 ship_own_package0YUJHZQA\  578em with diff product rules*/
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
                    'brand_id' => $productBrandId ?? '',
                    'categories_id' => json_decode($categoriesId) ?? [],
                    'sku' => $product['sku'] ?? '',
                    'piecesOfLineItem' => $product['quantity'] ?? '',
                    'originalPiecesOfLineItem' => $product['quantity'] ?? '',
                    'shipMultiplePackage' => $product_settings['ship_multiple_package'] ?? 0,
                    'shipBinAlone' => $shipBinAlone,
                    'lineItemId' => $product['product_id'] ?? '',
                    'lineItemPrice' => $product_price ?? 0,
                    'lineItemName' => $product['name'] ?? '',
                    'lineItemLength' => number_format($length, 2, '.', ''),
                    'lineItemWidth' => number_format($width, 2, '.', ''),
                    'lineItemHeight' => number_format($height, 2, '.', ''),
                    'lineItemWeight' => number_format($weight, 2, '.', ''),
                    'freight_enabled' => isset($product_settings['freight_enabled']) && $product_settings['freight_enabled'] ? 'Y' : 'N',

                    'vertical_rotation' => isset($product_settings['allow_vertical']) && $product_settings['allow_vertical'] ? '1' : '0',
                    'isHazmatLineItem' => isset($product_settings['hazardous_enabled']) && $product_settings['hazardous_enabled'] ? 'Y' : 'N',
                    'dropship_enabled' => isset($product_settings['dropship_enabled']) && $product_settings['dropship_enabled'] ? 'Y' : 'N',
                    'dropship' => $product_settings['dropship'] ?? '',
                    'product_insurance_active' => isset($product_settings['insurance']) && $product_settings['insurance'] ? 1 : 0,
                    'freightClass' => $this->isLTL($weight, $ltlCheck) ? 'ltl' : '',
                    'lineItemClass' => isset($product_settings['freight_class']) ? $this->getLineItemClass($product_settings['freight_class']) : '',
                    'shipping_group' => $product_settings['shipping_group'] ?? null,
                    'shipping_class' => $product_settings['shipping_class'] ?? null,
                    'exclude_packaging' => 0,
                    'quote_as_local' => $product_settings['quote_as_local'] ?? false,
                    'pallet_vertical_rotation' => isset($product_settings['pallet_vertical_rotation']) && $product_settings['pallet_vertical_rotation'] ? '1' : '0',
                    'own_pallet' => isset($product_settings['own_pallet']) && $product_settings['own_pallet'] ? '1' : '0',
                    'product_markup' => isset($product_settings['product_markup']) && !empty($product_settings['product_markup']) ? $product_settings['product_markup'] : '',
                    'lineItemNMFC' => isset($product_settings['nmfc']) && !empty($product_settings['nmfc']) ? $product_settings['nmfc'] : '',
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
            $originAddress = $this->shipmentPkg->getNearestWarehouse($details, $details['destination']['zip'], $storeData, $this->connectionSettings, []);
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

    public function getProductSetting($productId, $variantId, $storeId)
    {
        $settings = [];
        $productSetting = ProductSetting::select('settings', 'id', 'dropship_enabled', 'dropship_location', 'shipping_group', 'shipping_class', 'ship_multiple_package', 'pallet_vertical_rotation', 'own_pallet', 'product_markup')
            ->where(['source_product_id' => $productId, 'variant_id' => $variantId, 'store_id' => $storeId])
            ->first();
        if (!empty($productSetting)) {
            $productSetting->toArray();
            $settings = isset($productSetting['settings']) ? json_decode($productSetting['settings'], true) : [];
            $settings['id'] = $productSetting['id'];
            $settings['dropship_enabled'] = $productSetting['dropship_enabled'];
            $settings['dropship_location'] = $productSetting['dropship_location'];
            $settings['ship_multiple_package'] = $productSetting['ship_multiple_package'];
            $settings['shipping_group'] = $productSetting['shipping_group'];
            $settings['shipping_class'] = ($productSetting['shipping_class'] == 0 || $productSetting['shipping_class'] == null) ? null : $productSetting['shipping_class'];
            $settings['pallet_vertical_rotation'] = $productSetting['pallet_vertical_rotation'] ?? 0;
            $settings['own_pallet'] = $productSetting['own_pallet'] ?? 0;
            $settings['product_markup'] = $productSetting['product_markup'] ?? 0;
        }
        return $settings;
    }

    public function getProductBrand($productId, $variantId, $storeId)
    {
        $productBrand = ProductSetting::select('brand_id')
            ->where(['source_product_id' => $productId, 'variant_id' => $variantId, 'store_id' => $storeId])
            ->first();
            
        if (!empty($productBrand) && !empty($productBrand->toArray())) {
            return $productBrand['brand_id'];
        }
        return null;
    }

    public function getProductCategories($productId, $variantId, $storeId)
    {
        $productCategories = ProductSetting::select('categories_id')
            ->where(['source_product_id' => $productId, 'variant_id' => $variantId, 'store_id' => $storeId])
            ->first();

        if (!empty($productCategories) && !empty($productCategories->toArray())) {
            return $productCategories['categories_id'];
        }
        return null;
    }

    private function getProductPrice($productId, $variantId, $storeId)
    {
        return ProductSetting::where(['store_id' => $storeId, 'source_product_id' => $productId, 'variant_id' => $variantId])
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

    public function convertDimensionUnit($value, $unit)
    {
        switch ($unit) {
            case 'cm' :
                return $value / 2.54;
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
            $installedCarriers = InstalledCarrier::join('carriers', 'carriers.id', 'installed_carriers.carrier_id')
                ->where(['store_id' => $store->id, 'is_enabled' => 1])
                ->select('installed_carriers.*', 'carriers.slug')
                ->get();
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

            // Pallet Packaging Addon
            $installedAddonPallet = InstalledAddon::join('addons', 'addons.id', 'installed_addons.addon_id')
                ->where(['installed_addons.store_id' => $store->id,
                    'installed_addons.is_enabled' => 1,
                    'addons.short_code' => 'PLT',
                ])
                ->exists();
            $enabledAddonPallet = false;
            if ($installedAddonPallet) {
                $addonPallet = PackageSubscription::leftJoin('packages as p', 'package_subscriptions.package_id', '=', 'p.id')
                    ->where('store_id', $store->id)
                    ->where('addon_type', 'PLT')
                    ->where('package_subscriptions.status', '!=', 3)
                    ->select('package_subscriptions.id', 'package_subscriptions.status', 'package_subscriptions.created_at')
                    ->latest()->first();
                if (!blank($addonPallet)) {
                    $enabledAddonPallet = true;
                }
            }

            if (!empty($installedCarriers) && count($installedCarriers)) {
                return [
                    'installed_carriers' => $installedCarriers,
                    'installed_addons' => $installedAddons,
                    'store' => $store,
                    'installed_addon_sbs' => $installedAddonSbs,
                    'installed_addon_rad' => $installedAddonRad,
                    'installed_addon_pallet' => $installedAddonPallet,
                    'enabled_addon_sbs' => $enabledAddonSbs,
                    'enabled_addon_rad' => $enabledAddonRad,
                    'enabled_addon_pallet' => $enabledAddonPallet,
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
                if ($installedCarrier->slug == Functions::$dbscSlug) {
                    $this->isDbscInstalled = true;
                    continue;
                }
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

    public function importProductCsvJob(Request $request)
    {
        $ExportImportProducts = new ExportImportProducts();
        $ExportImportProducts->importProductCsvJob($request->all());
    }

    public function sendCurlRequest(
        $url,
        $postData
    )
    {
        Log::info('compare rates postData ' . json_encode($postData));
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
            Log::info('$output ' . $output);
            return json_decode($output, true);
        } catch (\Throwable $e) {
            $result = [];
        }
        return $result;
    }

    public function isShippingRule($storeData, $formatReq)
    {    
        $isRestriction = false;
        $storeId = $storeData['store']['id'];
        $this->storeData = $storeData ?? [];
        
        $this->applyRestrictOriginLocationsRule($storeId, $formatReq);

        $shippingRules = ShippingRule::getStoreShippingRules($storeId);
        if (!empty($shippingRules)){

            $destination = isset($formatReq['lineItemData']['destination']) ? $formatReq['lineItemData']['destination'] : [];
            $origins = isset($formatReq['lineItemData']['origin']) ? $formatReq['lineItemData']['origin'] : [];
            $cartItems = isset($formatReq['lineItemData']['items']) ? $formatReq['lineItemData']['items'] : [];
            $statesProvinces = CountryState::getCountryStatesProvinces($destination['country']);

            foreach($shippingRules as $key => $rule){

                if(isset($rule['rule_type']) && $rule['rule_type'] == 5){
                    continue;
                }

                $isAvailable = $rule['available'] ?? false;
                $applyRuleTo = $rule['apply_rule_to'] ?? 1;
                if($isAvailable){
                    switch ($applyRuleTo) {
                        case 1:
                            $isRestriction = $this->applyRuleOnCategories($rule, $cartItems, $origins, $destination, $statesProvinces);
                            break;
                        case 2:
                            $isRestriction = $this->applyRuleOnBrands($rule, $cartItems, $origins, $destination, $statesProvinces);
                            break;
                        case 3:
                            $isRestriction = $this->applyRuleOnProducts($rule, $cartItems, $origins, $destination, $statesProvinces);
                            break;
                        default:
                            break;
                    }
                }
            }
            return $isRestriction;
        }
        return false;
    }

    public function applyRuleOnCategories($rule, $cartItems, $origins, $destination, $statesProvinces)
    {
        
        $restrictedCategories = isset($rule['categories']) ? $rule['categories'] : [];
        $stateProvince = isset($rule['filter_state_province']) && !empty($rule['filter_state_province']) ? $rule['filter_state_province'] : [];

        if(!empty($restrictedCategories)){
            $statesCode = CountryState::getStateCode($statesProvinces, $stateProvince);
            $categoriesIds = array_column($cartItems, 'categories_id');
            $flattenedCategoriesIds = array_values(array_merge(...$categoriesIds)) ?? [];
            $istrue = false;
  
            $filterCategories = collect($restrictedCategories)->intersect($flattenedCategoriesIds) ?? [];
            foreach($filterCategories as $categoryId){
                $categoriesProducts = collect($cartItems)->filter(function ($item) use ($categoryId) {
                    return in_array($categoryId , $item['categories_id']);
                })->toArray() ?? [];

                if(!empty($categoriesProducts)){
                    $istrue = $istrue || $this->checkRuleRestriction($rule, $origins, $destination, $statesCode, $categoriesProducts);
                }
            }
            return $istrue;
        }
        return false;
    }

    public function applyRuleOnBrands($rule, $cartItems, $origins, $destination, $statesProvinces)
    {
        $restrictedBrands = isset($rule['brands']) ? $rule['brands'] : [];
        $stateProvince = isset($rule['filter_state_province']) && !empty($rule['filter_state_province']) ? $rule['filter_state_province'] : [];
        $istrue = false;
        if(!empty($restrictedBrands)){
            $statesCode = CountryState::getStateCode($statesProvinces, $stateProvince);
            foreach($restrictedBrands as $rpKey => $brandId){

                $filterBrands = collect($cartItems)->where('brand_id', $brandId)->all() ?? [];
                
                if(!empty($filterBrands)){
                    $istrue = $istrue || $this->checkRuleRestriction($rule, $origins, $destination, $statesCode, $filterBrands);
                }
            }
            return $istrue;
        }
        return false;
    }

    public function applyRuleOnProducts($rule, $cartItems, $origins, $destination, $statesProvinces)
    {
        $restrictedProducts = isset($rule['products']) ? $rule['products'] : [];
        $stateProvince = isset($rule['filter_state_province']) && !empty($rule['filter_state_province']) ? $rule['filter_state_province'] : [];
        $istrue = false;

        if(!empty($restrictedProducts)){
            $statesCode = CountryState::getStateCode($statesProvinces, $stateProvince);
            foreach($restrictedProducts as $rpKey => $productId){

                $filterProducts = collect($cartItems)->where('product_id', $productId['key'])->all() ?? [];
                
                if(!empty($filterProducts)){
                    $istrue = $istrue || $this->checkRuleRestriction($rule, $origins, $destination, $statesCode, $filterProducts);
                }
            }
            return $istrue;
        }
        return false;
    }

    public function checkRuleRestriction($rule, $origins, $destination, $statesCode, $products)
    {
        $filterCountry = isset($rule['filter_country']) ? $rule['filter_country'] : '';
        $postalCodes = isset($rule['filter_postal_code']) ? $rule['filter_postal_code'] : '';
        $warehouses = isset($rule['warehouses']) ? $rule['warehouses'] : [];
        $isSameOrigin = false;

        $isSameCountry = $destination['country'] == $filterCountry ?? false;
        $isSameState = in_array($destination['state'] , $statesCode) ?? false;
        $isSamePostalCode = CountryState::isSamePostalCode($destination['zip'], $postalCodes) ?? false;
        if(!empty($origins)){
            foreach($origins as $origin){         
                $isSameOrigin = in_array($origin['senderZip'] , $warehouses) ?? false;
            }
        }

        Log::info('Shipping rule applied: ' . json_encode($rule));

        if ($isSameCountry && $isSameState && $isSamePostalCode && isset($rule['rule_type']) && $rule['rule_type'] == 4){
            return false;
        } elseif ($isSameCountry && $isSameState && isset($rule['rule_type']) && $rule['rule_type'] == 3){
            return false;
        } elseif ($isSameCountry && isset($rule['rule_type']) && $rule['rule_type'] == 1){
            return false;
        } else {
            return true;
        }
    }

    public function applyRestrictOriginLocationsRule($storeId, $formatReq){
        $shippingRules = ShippingRule::getStoreShippingRules($storeId, 5);
        $cartItems = isset($formatReq['lineItemData']['items']) ? $formatReq['lineItemData']['items'] : [];
        $destination = isset($formatReq['lineItemData']['destination']) ? $formatReq['lineItemData']['destination'] : [];

        if (!empty($shippingRules) && !empty($cartItems)){
            foreach($cartItems as $item){
                $warehouses = [];
                foreach($shippingRules as $rule){
                    $isAvailable = $rule['available'] ?? false;

                    if($isAvailable){
                        if(isset($rule['apply_rule_to']) && $rule['apply_rule_to'] == 3 && isset($item['product_id']) && !empty($item['product_id'])){
                            $isProductExist = collect($rule['products'])->where('value', $item['product_id'])->all() ?? [];
                        
                            if(!(empty($isProductExist))){
                                $warehouses = array_merge($warehouses, $rule['warehouses']);
                            }
                        } else if(isset($rule['apply_rule_to']) && $rule['apply_rule_to'] == 2 && isset($item['brand_id']) && !empty($item['brand_id'])){
                            $isProductExist = in_array($item['brand_id'], $rule['brands']);
    
                            if($isProductExist){
                                $warehouses = array_merge($warehouses, $rule['warehouses']);
                            }
                        } else if(isset($rule['apply_rule_to']) && $rule['apply_rule_to'] == 1 && isset($item['categories_id']) && !empty($item['categories_id'])){
                            $isProductExist = array_intersect($item['categories_id'], $rule['categories']);
    
                            if($isProductExist){
                                $warehouses = array_merge($warehouses, $rule['warehouses']);
                            }
                        }
                    }
                }

                if(!empty($warehouses) && isset($this->connectionSettings['ups-ltl']) || isset($this->connectionSettings['xpo-ltl']) || isset($this->connectionSettings['odfl-ltl']) || isset($this->connectionSettings['ups-small'])){

                    $origins = isset($this->formatReq['lineItemData']['origin']) ? $this->formatReq['lineItemData']['origin'] : [];
                    foreach($origins as $key => $origin){
                        if($key == $item['variant_id'] && isset($origin['location']) && $origin['location'] === 'warehouse'){
                            // check: if multiple warehouses defined then find nearest origin from the warehouses list
                            $originAddress = $this->shipmentPkg->getNearestWarehouse($this->formatReq['lineItemData'], $destination['zip'], $this->storeData, [], $warehouses);
                            if (blank($originAddress)) {
                                Log::info('No warehouse added');
                                return false;
                            }
                            $originAddress = $this->getAddressForQuotes($originAddress);
                            // Check: if origin already assign then skip the origin assignment
                            if($originAddress['senderZip'] == $this->formatReq['lineItemData']['origin'][$key]['senderZip']){
                                continue;
                            }
                            $this->formatReq['lineItemData']['origin'][$key] = $originAddress;
                        }
                    }
                }
            }
        }
    }
}
 