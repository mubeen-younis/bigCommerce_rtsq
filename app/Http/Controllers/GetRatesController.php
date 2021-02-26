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
        if ($storeData == null){
            return [];
        }
        $this->getCarrierSettings($storeData['installed_carriers']);
        $formatReq = $this->formatRequest($request->all(), $storeData);
        if (
            $formatReq['lineItemData']['destination']['zip'] == null ||
            $formatReq['lineItemData']['destination']['state'] == null ||
            $formatReq['lineItemData']['destination']['country'] == null ||
            $formatReq['lineItemData']['destination']['city'] == null ||
            count($this->connectionSettings) == 0 ||
            count($this->quoteSettings) == 0
        ) {
            return [];
        }
        $this->shipping->collectRates($formatReq, $storeData, $this->connectionSettings, $this->quoteSettings);
        $originWarehouse = new Origin();
        $originWarehouse->getNearestWarehouse($formatReq);
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
                    'piecesOfLineItem' => $product['quantity'] ?? '',
                    'lineItemId' => $product['product_id'] ?? '',
                    'lineItemName' => $product['name'] ?? '',
                    'lineItemLength' => $product['length']['value'] ?? '',
                    'lineItemWidth' => $product['width']['value'] ?? '',
                    'lineItemHeight' => $product['height']['value'] ?? '',
                    'lineItemWeight' => $weight,
                    'freight_enabled' => $product_settings['freight_enabled'] ?? '',
                    'isHazmatLineItem' => $product_settings['hazardous_enabled'] ?? '',
                    'dropship_enabled' => $product_settings['dropship_enabled'] ?? '',
                    'dropship' => $product_settings['dropship'] ?? '',
                    'product_insurance_active' => $product_settings['insurance'] ?? '',
                    'freightClass' => $this->isLTL($weight, $ltlCheck),
                    'lineItemClass' => $this->getLineItemClass($product_settings['freight_class']),
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
            ->where(['source_product_id' => $productId, 'variant_id' => $variantId])
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
        if (!empty($installedCarriers)){
            foreach ($installedCarriers as $installedCarrier) {
                $connectionSettings = Connection::where('installed_carrier_id', $installedCarrier->id)->first();
                if (isset($connectionSettings->value)){
                    //$installedCarrier->carrier_id
                    $this->connectionSettings['WweLtl'] = json_decode($connectionSettings->value,true);
                }
                $quoteSettings = QuoteSetting::where('installed_carrier_id', $installedCarrier->id)->first();
                if (isset($quoteSettings->value)){
                    //$installedCarrier->carrier_id
                    $this->quoteSettings['WweLtl'] = json_decode($quoteSettings->value,true);
                }
                $carrierServices = AdditionalCarrierTabSetting::where('installed_carrier_id', $installedCarrier->id)->first();
                if (isset($carrierServices->value)){
                    //$installedCarrier->carrier_id
                    $this->quoteSettings['WweLtl']['carrier_services'] = json_decode($carrierServices->value,true);
                }
            }
        }
    }

    public function getQuoteSettings()
    {

    }
}
