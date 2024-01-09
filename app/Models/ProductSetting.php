<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use stdClass;
use App\CustomClasses\BigCommerceFunctions;
use Illuminate\Support\Facades\Log;
use App\CurlRequest;
use Illuminate\Support\Facades\DB;

class ProductSetting extends Model
{
    use HasFactory;

    protected $table = 'product_settings';
    protected $fillable = [
        'settings', 'dropship_location', 'dropship_enabled', 'shipping_group', 'shipping_group_enabled'
    ];

    public static function deleteIfDropProduct($dropshipId)
    {
        self::where('dropship_location', $dropshipId)->update(['dropship_location' => null, 'dropship_enabled' => false]);
        /*    $products = self::where('settings', '!=', null)->whereJsonContains('settings', ['dropship_location' => "" . $dropshipId])->get();
            foreach ($products as $product) {
                $settings = json_decode($product->settings, true);
                if (blank($settings)) {
                    continue;
                }
                $settings['dropship_enabled'] = false;
                $settings['dropship_location'] = null;
                self::where('id', $product->id)->update(['settings' => $settings]);
            }*/
    }

    public static function updateShippingGroupProduct($shippingGroupId)
    {
        self::where('shipping_group', $shippingGroupId)->update(['shipping_group' => null, 'shipping_group_enabled' => false]);
    }

    /**
     * Saves or Updates product from import product in DB
     * @param $product
     * @param $storeId
     * @return void|null
     */
    public function saveProduct($product, $storeId, $scope = null)
    {
        try {
            DB::beginTransaction();
            
            if ($scope == "store/product/created" && ProductSetting::where('source_product_id', $product['id'])
                    ->where('variant_id', $product['base_variant_id'])
                    ->where('store_id', $storeId)->exists()) {
                return null;
            }

            if ($product['base_variant_id'] == null && ProductSetting::where('source_product_id', $product['id'])
                    ->where('store_id', $storeId)->exists()) {
                return null;
            }
            
            $saveProduct = ProductSetting::where('source_product_id', $product['id'])
                ->where('variant_id', $product['base_variant_id'])
                ->where('store_id', $storeId)->first();

            if (blank($saveProduct)) {
                $saveProduct = new ProductSetting();
                $storeSettings = $this->getStoreSettings($storeId);
                $prodWeight = $this->convertWeight(isset($product['weight']) ? $product['weight'] : '', isset($storeSettings['weight_units']) ? strtolower($storeSettings['weight_units']) : 'lbs') ?? 0;
                /*Start - Added FOr Default Quoting Method*/
                $productSettings = new stdClass();
                if (!empty($product['weight']) && $prodWeight > 150) {
                    $productSettings->freight_enabled = true;
                    $productSettings->parcel_enabled = false;
                } else {
                    $productSettings->freight_enabled = false;
                    $productSettings->parcel_enabled = true;
                }

                $saveProduct->settings = json_encode($productSettings);
            }


            $saveProduct->name = $product['name'] ?? '';
            $saveProduct->source_product_id = $product['id'];
            $saveProduct->variant_id = $product['base_variant_id'];
            $saveProduct->image_src = '';
            $saveProduct->product_type = $product['type'] ?? '';
            $saveProduct->sku = $product['sku'];
            $saveProduct->weight = $product['weight'];
            $saveProduct->length = $product['depth'];
            $saveProduct->width = $product['width'];
            $saveProduct->height = $product['height'];
            $saveProduct->price = $product['price'];
            $saveProduct->store_id = $storeId;
            Log::info('Test logs');
            // $saveProduct->brand_id = $product['brand_id'] ?? null;
            // $saveProduct->categories_id = json_encode($product['categories']) ?? '';
            $saveProduct->save();
            DB::commit();

        } catch (\Exception $exception) {
            DB::rollBack();
            Log::info('Exception on saving Products Detail ' . json_encode([
                'line' => $exception->getLine(),
                'message' => $exception->getMessage()
            ]));
        }

    }

    public function setVariantNullProduct($product, $storeId)
    {
        if (ProductSetting::where('source_product_id', $product['id'])
                ->where('store_id', $storeId)->exists() && !(ProductSetting::where('source_product_id', $product['id'])
                ->where('variant_id', null)
                ->where('store_id', $storeId)->exists())) 
            {   
                $updateproduct = ProductSetting::where('source_product_id', $product['id'])
                    ->where('store_id', $storeId)->update(['variant_id' => null]);
            }
    }

    public function deleteNullVariantProduct($product, $storeId)
    {
        if (ProductSetting::where('source_product_id', $product['id'])
                ->where('store_id', $storeId)->exists()) 
            {   
                $updateproduct = ProductSetting::where('source_product_id', $product['id'])
                    ->where('variant_id', null)
                    ->where('store_id', $storeId)->delete();
            }
    }

    public function saveProductFromSync($product, $storeId)
    {
        try {

            $saveProduct = ProductSetting::where('source_product_id', $product['id'])
                ->where('variant_id', $product['base_variant_id'])
                ->where('store_id', $storeId)->first();

            if (blank($saveProduct)) {
                $saveProduct = new ProductSetting();
                $storeSettings = $this->getStoreSettings($storeId);
                $prodWeight = $this->convertWeight(isset($product['weight']) ? (float)$product['weight'] : '', isset($storeSettings['weight_units']) ? strtolower($storeSettings['weight_units']) : 'lbs') ?? 0;
                /*Start - Added FOr Default Quoting Method*/
                $productSettings = new stdClass();
                if (!empty($product['weight']) && $prodWeight > 150) {
                    $productSettings->freight_enabled = true;
                    $productSettings->parcel_enabled = false;
                } else {
                    $productSettings->freight_enabled = false;
                    $productSettings->parcel_enabled = true;
                }

                $saveProduct->settings = json_encode($productSettings);
            }


            $saveProduct->name = $product['name'] ?? '';
            $saveProduct->source_product_id = $product['id'];
            $saveProduct->variant_id = $product['base_variant_id'];
            $saveProduct->image_src = '';
            $saveProduct->product_type = $product['type'] ?? '';
            $saveProduct->sku = $product['sku'];
            $saveProduct->weight = $product['weight'];
            $saveProduct->length = $product['depth'];
            $saveProduct->width = $product['width'];
            $saveProduct->height = $product['height'];
            $saveProduct->price = $product['price'];
            $saveProduct->store_id = $storeId;
            Log::info('Test logs');
            // $saveProduct->brand_id = $product['brand_id'] ?? null;
            // $saveProduct->categories_id = json_encode($product['categories']) ?? '';
            $saveProduct->save();

        } catch (\Exception $exception) {
            Log::info('Exception on saving Products Detail ' . json_encode([
                'line' => $exception->getLine(),
                'message' => $exception->getMessage()
            ]));
        }

    }

    public function getStoreSettings($storeId)
    {

        try {
            $store = Store::where('id', $storeId)->first();
            if (empty($store)) {
                return [];
            }
            $storeDetails = BigCommerceFunctions::getStoreSettings($store['hash']);
            $storeDetails = (new CurlRequest())->enSingleCurlRequest($storeDetails['endpoint'],
                $storeDetails['request'], $storeDetails['headers'], $storeDetails['method'], false);
            $response = json_decode($storeDetails['response'], true);

            return $response;

        } catch (\Exception $exception) {
            Log::info('Exception on getting Store Details ' . $exception->getMessage());
            return [];
        }

    }

    public function convertWeight($value, $unit)
    {
        $value = (float)$value;
        switch ($unit) {
            case 'ounces' :
                return $value / 16;
            case 'kgs':
                return $value / 0.45359237;
            case 'grams':
                return $value / 453.59237;
            case 'tonnes':
                return $value / 0.00045359237;
            default:
                return $value;
        }
    }
}
