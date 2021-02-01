<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\CustomClasses\Origin;
use App\Models\Locations;

class GetRatesController extends Controller
{
    /*
     * returnRates will use to parse request
     */

    public function returnRates(Request $request)
    {
        $formatedReq = $this->formateRequest($request->all());
        $originWarehouse = new Origin();
        $originWarehouse->getNearestWarehouse($formatedReq);
    }

    public function formateRequest($data){
        return [
            'street_1'      => $data['base_options']['destination']['street_1'] ?? '',
            'street_2'      => $data['base_options']['destination']['street_2'] ?? '',
            'zip'           => $data['base_options']['destination']['zip'] ?? '',
            'city'          => $data['base_options']['destination']['city'] ?? '',
            'state_iso2'    => $data['base_options']['destination']['state_iso2'] ?? '',
            'country_iso2'  => $data['base_options']['destination']['country_iso2'] ?? '',
            'address_type'  => $data['base_options']['destination']['address_type'] ?? '',
            'product_name'  => $data['base_options']['items']['name'] ?? '',
            'product_id'    => $data['base_options']['items']['product_id'] ?? '',
            'variant_id'    => $data['base_options']['items']['variant_id'] ?? '',
            'sku'           => $data['base_options']['items']['sku'] ?? '',
            'quantity'      => $data['base_options']['items']['quantity'] ?? '',
            'length'        => [
                            'units' => $data['base_options']['items']['length']['units'] ?? '',
                            'value' => $data['base_options']['items']['length']['value'] ?? '',
            ],
            'width'         => [
                            'units' => $data['base_options']['items']['width']['units'] ?? '',
                            'value' => $data['base_options']['items']['width']['value'] ?? '',
            ],
            'height'        => [
                            'units' => $data['base_options']['items']['height']['units'] ?? '',
                            'value' => $data['base_options']['items']['height']['value'] ?? '',
            ],
            'weight'        => [
                            'units' => $data['base_options']['items']['weight']['units'] ?? '',
                            'value' => $data['base_options']['items']['weight']['value'] ?? '',
            ],
            'discounted_price'=> [
                            'currency' => $data['base_options']['items']['discounted_price']['currency'] ?? '',
                            'amount' => $data['base_options']['items']['discounted_price']['amount'] ?? '',
            ],
            'declared_value' => [
                            'currency' => $data['base_options']['items']['declared_value']['currency'] ?? '',
                            'amount' => $data['base_options']['items']['declared_value']['amount'] ?? '',
            ],
        ];
    }
}
