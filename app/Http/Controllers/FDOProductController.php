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

}
