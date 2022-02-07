<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use stdClass;

class ProductSetting extends Model
{
    use HasFactory;

    protected $table = 'product_settings';
    protected $fillable = [
        'settings',
    ];

    public function deleteIfDropProduct($dropshipId)
    {
        $products = self::whereJsonContains('settings', ['dropship_location' => $dropshipId])->get();

        foreach ($products as $product) {
            $settings = json_decode($product->settings, true);
            if (blank($settings)) {
                continue;
            }
            $settings['dropship_enabled'] = false;
            $settings['dropship_location'] = null;
            self::where('id', $product->id)->update(['settings' => $settings]);
        }
    }

    public function saveProduct($product, $storeId)
    {
        if (ProductSetting::where('source_product_id', $product['id'])
            ->where('variant_id', $product['base_variant_id'])
            ->where('store_id', $storeId)->exists()) {
            $saveProduct = ProductSetting::where('source_product_id', $product['id'])
                ->where('variant_id', $product['base_variant_id'])
                ->where('store_id', $storeId)->first();
        } else {
            $saveProduct = new ProductSetting();
        }
        $saveProduct->name = $product['name'];
        $saveProduct->source_product_id = $product['id'];
        $saveProduct->variant_id = $product['base_variant_id'];
        $saveProduct->image_src = '';
        $saveProduct->product_type = $product['type'];
        $saveProduct->sku = $product['sku'];
        $saveProduct->weight = $product['weight'];
        $saveProduct->length = $product['depth'];
        $saveProduct->width = $product['width'];
        $saveProduct->height = $product['height'];
        $saveProduct->price = $product['price'];
        $product_settings = new stdClass();
        $product_settings->insurance = false;
        $product_settings->freight_enabled = false;
        //$saveProduct->settings = json_encode($product_settings);
        $saveProduct->store_id = $storeId;
        $saveProduct->save();
    }
}
