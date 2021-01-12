<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductSettingController extends Controller
{
    //
    public function getAllProducts(Request $request)
    {
        $this->getProductsFromBC();
    }

    public function getProductsFromBC()
    {
        $client = new Client();
        $mainController = new MainController();
        //https://api.bigcommerce.com/stores/{$$.env.store_hash}/v3/catalog/products

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.bigcommerce.com/stores/uann2u/v3/catalog/products");
        //curl_setopt($ch, CURLOPT_GET, 1);
        //curl_setopt($ch, CURLOPT_POSTFIELDS);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Auth-Token' => 'fo9l40uqj8najktk0t01klrl7dioiuc',
            'X-Auth-Client' => $mainController->getAppClientId()
        ]);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $output = curl_exec($ch);
        $curlInfo = curl_getinfo($ch);
        curl_close($ch);

        echo '<pre>';
        print_r($output);
        print_r(json_decode($output));
        echo '</pre>';
        exit();

        $result = $client->request('GET', 'https://api.bigcommerce.com/stores/uann2u/v3/catalog/products', [
            'headers' => [
                'X-Auth-Token' => 'fo9l40uqj8najktk0t01klrl7dioiuc'
            ],
            'on_stats' => function (TransferStats $stats) {
                // You must check if a response was received before using the
                // response object.
                if ($stats->hasResponse()) {
                    echo $stats->getResponse()->getStatusCode();
                    dd($stats->getResponse());
                } else {
                    // Error data is handler specific. You will need to know what
                    // type of error data your handler uses before using this
                    // value.
                    var_dump($stats->getHandlerErrorData());
                }
            }
        ]);
        /*$store = DB::table('access_tokens')->get()->toArray();
        $storeHash = explode('/', $store[0]->store_hash);
        Bigcommerce::configure(array(
            'client_id' => env('BC_APP_CLIENT_ID'),
            'auth_token' => $store[0]->access_token,
            'store_hash' => $storeHash[1]
        ));
        $products = Bigcommerce::getProducts();
        //Debug point added by @Azeem
        echo '$products';
        echo '<pre>';
        print_r($products);
        echo '</pre>';
        exit();*/
    }
}
