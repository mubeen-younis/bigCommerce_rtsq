<?php

namespace App\Http\Controllers;

use App\Models\HubSpot;
use App\Models\Store;
use Illuminate\Http\Request;
use App\Constants\Constant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HubSpotController extends Controller
{


    public function createUpdateHubSpotUser($storeId, $user = [], $status = [], $contact_id = '')
    {
        $product_name = 'Bigcommerce Real Time Shipping Quotes';
        $product_trials = isset($status['product_trials']) && $status['product_trials'] ? $product_name :'';
        $products_purchased = isset($status['products_purchased']) && $status['products_purchased'] ? $product_name :'';
        $products_lost = isset($status['products_lost']) && $status['products_lost'] ? $product_name :'';
        $store = Store::find($storeId)->get()->toArray();
        $data = array (
            'contactProperties' =>
                array (
                    'company' => $store['name'] ?? '',
                    'website' => $store['url'] ?? '',
                    'email' => $user['email'] ?? '', // required
                    'firstname' => $user['firstname'] ?? '',
                    'lastname' => $user['lastname'] ?? '',
                    'city' => $user['city'] ?? '',
                    'state' => $user['state'] ?? '',
                    'email_company_domains_match' => 'No',
                    'zip' => $user['zip'] ?? '',
                    'country' => $user['country'] ?? 'US',
                    'address' => $user['address'] ?? '',
                    'phone' => $user['phone'] ?? '',
                    //'shipment_per_week' => '',
                    //'hs_lead_status' => 'Lost', // Active(Trial and purchased) or Lost
                    'lifecyclestage' => 'customer',
                    'product_trials' => $product_trials, // if trial activated
                    'products_purchased' => $products_purchased, // if purchased
                    'products_lost' => $products_lost, //
                    'contact_id' => $contact_id, // don't send if user created
                ),
        );
        $url = Constant::HUB_SPOT_URL;
        try {
            set_time_limit(0);
            $soap_do = curl_init();
            curl_setopt($soap_do, CURLOPT_URL, $url);
            curl_setopt($soap_do, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($soap_do, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($soap_do, CURLOPT_POSTFIELDS, http_build_query($data));
            $result = curl_exec($soap_do);
            curl_close($soap_do);
            $result = json_decode($result, true);
        } catch (\Throwable $e) {
            $result = [];
        }
        if(isset($result['ERROR']) || blank($result)){
            return false;
        }
        $CONTACTID = $result['CONTACTID'] ?? '';
        if($product_trials != ''){
            $data = [
                'store_id' => $storeId,
                'hubspot_id' => $CONTACTID,
                'status' => 1,
                'email' => $user['email']
            ];
        }else if($products_purchased != ''){
            $data = [
                'store_id' => $storeId,
                'hubspot_id' => $CONTACTID,
                'status' => 2,
                'email' => $user['email']
            ];
        }else if($products_lost != ''){
            $data = [
                'store_id' => $storeId,
                'hubspot_id' => $CONTACTID,
                'status' => 3,
                'email' => $user['email']
            ];
        }
        if(HubSpot::where('store_id', $storeId)->exists()){
            HubSpot::where('store_id', $storeId)->update($data);
        }else{
            HubSpot::insert($data);
        }

    }


}
