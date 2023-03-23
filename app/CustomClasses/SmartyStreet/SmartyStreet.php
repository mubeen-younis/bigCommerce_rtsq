<?php


namespace App\CustomClasses\SmartyStreet;
use App\Constants\Constant;
use App\Http\Controllers\Subscription\PackageSubscriptionController;
use App\Models\AdditionalCarrierTabSetting;
use App\Models\BinRequestLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SmartyStreet
{
    private $authId = Constant::SMARTY_AUTH_ID;

    /**
     * Property contains API key.
     * @var string
     */
    private $token = Constant::SMARTY_TOKEN;

    /**
     * Property contains URL we hit for 3dBin API.
     * @var  string
     */
    private $endURL = Constant::SMARTY_URL;

    public function getSmartyResponse($storeId, $address){
        $radStatus = $this->consumeHits($storeId);
        if(!$radStatus['status']){
            return "N";
        }
        $addressStatus = $this->address_validated($address);
        if($addressStatus == "n"){
            $addonSettings = DB::table('addon_settings')->select('addon_settings.value')
                ->join('installed_addons', 'installed_addons.id', '=', 'addon_settings.installed_addon_id')
                ->join('addons', 'addons.id', '=', 'installed_addons.addon_id')
                ->where('installed_addons.store_id', $storeId)
                ->where('addons.short_code','RAD')->first();
            if(!empty($addonSettings)) {
                $addonSettings = json_decode(($addonSettings->value))->unconfirmed_default ?? 1;
                $addressStatus = ($addonSettings === 1) ? "r" : "c";
            }else{
                //default set address to residentials
                $addressStatus = "r";
            }
        }
        $addressStatus = $addressStatus == 'r' ? 'Y' : 'N';
        return $addressStatus;
    }

    private function consumeHits($storeId){
        $PackageSubscriptionController = new PackageSubscriptionController();
        $param = ['store_id' => $storeId, 'hits'=>1, 'addon_type'=>'RAD'];
        $resp = $PackageSubscriptionController->consumeHits($param);
        return $resp;
    }

    private function set_address($address)
    {
        $street = $address['street_1'] ?? '';
        $city = $address['city'] ?? '';
        $state = $address['state'] ?? '';
        $zip = $address['zip'] ?? '';
        return $address = $street . ' ' . $city . ' ' . $state . ' ' . $zip;
    }
    /* Function return value behalf of address type if Commercial return 'c', or Residential return 'r' or not valid return 'n' */

    public function getSmartyAddress($address)
    {
        $addressStatus = $this->address_validated($address);

        return $addressStatus;
    }
    private function address_validated($address)
    {
        $address = $this->set_address($address);
        /*
          @Address: //Provide Street Address with zip code //Pattern: '123 Main St 52001'

          Each address submitted must have non-blank values for one of the following field combinations to be eligible for a positive address match:
          street + city + state
          street + zipcode
          street (entire address in the street field - what we call a "freeform" input)
         */
        $addressArray = array(
            'street' => $address,
            'auth-id' => $this->authId, //Smarty Streets Auth ID
            'auth-token' => $this->token  //Smarty Streets Auth Token
        );

        $request = http_build_query($addressArray);

        $req = $this->endURL."?" . $request;

        $response = file_get_contents($req);
        $data = json_decode($response, true);
        //when address valid API return Address detail array
        if (!empty($data)) {
            if (isset( $data[0]['metadata']['rdi']) && $data[0]['metadata']['rdi'] == 'Commercial') {

                $res = 'c';     //Address is Commercial

            } elseif ((isset($data[0]['metadata']['rdi']) && $data[0]['metadata']['rdi'] == 'Residential') && (isset($data[0]['analysis']['dpv_match_code']) && $data[0]['analysis']['dpv_match_code'] == 'Y') && (isset($data[0]['analysis']['active']) && $data[0]['analysis']['active'] == 'Y')) {

                $res = 'r';     //Address is Residential
            } else {

                $res = 'n';
            }
        } else {
            $res = 'n';
        }

        return $res;
    }

}
