<?php

namespace App\Http\Controllers;

use App\Models\Locations;
use Illuminate\Http\Request;
use App\CustomClasses\Origin;
use App\Models\ProductSetting;

class GetRatesController extends Controller
{
    /*
     * returnRates will use to parse request
     */

    public function returnRates(Request $request){
        $formatedReq = $this->formateRequest($request->all());
        $originWarehouse = new Origin();
        $originWarehouse->getNearestWarehouse($formatedReq);
    }

    public function formateRequest($data){
        $product_settings = (array) json_decode($this->getProductSetting($data));
        return [
            'destination' => [
                'street_1'      => $data['base_options']['destination']['street_1'] ?? '',
                'street_2'      => $data['base_options']['destination']['street_2'] ?? '',
                'zip'           => $data['base_options']['destination']['zip'] ?? '',
                'city'          => $data['base_options']['destination']['city'] ?? '',
                'state_iso2'    => $data['base_options']['destination']['state_iso2'] ?? '',
                'country_iso2'  => $data['base_options']['destination']['country_iso2'] ?? '',
                'address_type'  => $data['base_options']['destination']['address_type'] ?? '',
                'product_id'    => $data['base_options']['items']['product_id'] ?? '',
                'variant_id'    => $data['base_options']['items']['variant_id'] ?? '',
                'sku'           => $data['base_options']['items']['sku'] ?? '',
                'quantity'      => $data['base_options']['items']['quantity'] ?? '',
            ],
            'dimensions' => [
                'length'        => $data['base_options']['items']['length']['value'] ?? '',
                'width'         => $data['base_options']['items']['width']['value'] ?? '',
                'height'        => $data['base_options']['items']['height']['value'] ?? '',
                'weight'        => $this->convertWeight( $data['base_options']['items']['weight']['value'], strtolower($data['base_options']['items']['weight']['units']) ),
            ],
            'settings' => [
                'freight_enabled'   => $product_settings['freight_enabled'] ?? '',
                'freight_class'     => $product_settings['freight_class'] ?? '',
                'hazardous_enabled' => $product_settings['hazardous_enabled'] ?? '',
                'dropship_enabled'  => $product_settings['dropship_enabled'] ?? '',
                'dropship'          => $product_settings['dropship'] ?? '',
                'insurance'         => $product_settings['insurance'] ?? '',
            ]
        ];
    }

    public function getProductSetting($data){
        $productSetting = ProductSetting::select('settings')
            ->where('source_product_id', $data['base_options']['items']['product_id'])
            ->where('variant_id', $data['base_options']['items']['variant_id'])
            ->first()->toArray();
        return $productSetting['settings'];
    }

    public function convertWeight($value, $unit){
        switch ($unit) {
            case 'oz' :
                return $value/16;
                break;
            default:
                return $value;
        }
    }
}
