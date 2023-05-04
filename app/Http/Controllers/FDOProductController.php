<?php

namespace App\Http\Controllers;

use App\Helpers\Helpers;
use App\Models\ProductSetting;
use Illuminate\Http\Request;

class FDOProductController extends Controller
{
    //

    public function getVariantDetail(Request $request, $variantID)
    {
        if (empty($variantID)) {
            return Helpers::sendJsonResponseFdo(true, 'No variant ID');
        }
        $product = ProductSetting::where('variant_id', $variantID)
            ->first();
        if ($product === null) {
            return Helpers::sendJsonResponseFdo(true, 'No Variant found');
        }
        return Helpers::sendJsonResponseFdo(false, '', $product);
    }

    public function getProductDetails(Request $request)
    {
        if (empty($request->product_id)) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Product Id',
            ], 404);
        }
        $products = ProductSetting::where('source_product_id', $request->product_id)
            ->where('store_id', $request->store_id)
            ->get();
        if ($products->isEmpty()) {
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'No Products Available',
            ], 200);
        }
        return response()->json(['error' => false,
            'data' => $products,
            'message' => '',
        ], 200);
    }
}
