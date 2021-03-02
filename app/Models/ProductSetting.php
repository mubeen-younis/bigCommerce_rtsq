<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductSetting extends Model
{
    use HasFactory;
    protected $table = 'product_settings';
    protected $fillable = [
        'settings'
    ];

    public function saveProduct($product, $storeId)
    {
        if (ProductSetting::where('source_product_id', $product['id'])
            ->where('variant_id', $product['base_variant_id'])
            ->where('store_id', $storeId)->exists()) {
            $saveProduct = ProductSetting::where('source_product_id', $product['id'])->first();
        } else {
            $saveProduct = new ProductSetting();
        }
        $saveProduct->name = $product['name'];
        $saveProduct->source_product_id = $product['id'];
        $saveProduct->variant_id = $product['base_variant_id'];
        $saveProduct->image_src = $product['custom_url']['url'];
        $saveProduct->product_type = $product['type'];
        $saveProduct->sku = $product['sku'];
        $saveProduct->weight = $product['weight'];
        $saveProduct->length = $product['depth'];
        $saveProduct->width = $product['width'];
        $saveProduct->height = $product['height'];
        $saveProduct->price = $product['price'];
        $saveProduct->settings = json_encode($saveProduct);
        $saveProduct->store_id = $storeId;
        $saveProduct->save();
    }
}
