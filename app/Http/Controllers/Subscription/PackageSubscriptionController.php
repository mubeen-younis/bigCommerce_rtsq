<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Models\Subscription\Package;
use App\Models\Subscription\PackageSubscription;
use App\Models\Subscription\PackageToBeCharge;
use App\Models\Subscription\Subscription;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Stripe\Charge;
use Stripe\Stripe;

class PackageSubscriptionController extends Controller
{
    public static $addonTypeSBS = 'SBS';
    public static $addonTypeRAD = 'RAD';
    public static $trialSBS = 1;
    public static $storeId = 0;
    public static $minSbsPaidPackage = 2;
    public function __construct(){
        Stripe::setApiKey(config('app.stripe_secret'));
    }
    public function subscribeToPackage(Request $request){
        /*if ($request['package'] != self::$trialSBS){
            $data = [
                'card_number' => preg_replace("/\s+/", "", $request['card_number']),
                'exp_month' => $request['exp_month'],
                'exp_year' => $request['exp_year'],
                'cvc' => $request['cvc'],
                'card_name' => $request['card_name'],
                'address' => $request['address'],
                'city' => $request['city'],
                'state' => $request['state'],
                'zip' => $request['zip'],
                'country'=> $request['country'],
            ];
        }*/
        self::$storeId = $data['store_id'] = $request['store_id'];
        $data['package'] = $request['package'];
        $data['email'] = $request['email'];
        $addonType = $request['addon_type'];

        if ($addonType == self::$addonTypeSBS){
            $responce = $this->subscribeToSBSPackage($data);
        } elseif($addonType == self::$addonTypeRAD){
            //Do Nothing Yet
        }else{
            $responce = [
                'error' => true,
                'message' => 'Addon type is missing.',
                'data' => $request->all(),
            ];
        }
        return response()->json($responce);
    }

    public function subscribeToSBSPackage($data){
        if ($data['package'] != self::$trialSBS){
            $this->createStripeChargeForPackage($data);
        }
        $packageSub = PackageSubscription::create([
                        'store_id' => $data['store_id'],
                        'package_id' => $data['package'],
                        'status' => 1,
                        'subscription_time' => now(),
                        'update_time' => now(),
                        'expiry_time' => Carbon::now()->addDays(30),
                        'total_count' => 0
                    ]);
        PackageToBeCharge::create([
            'subscription_id' => $packageSub->id,
            'package_id' => $data['package'],
            'status' => ($data['package'] != self::$trialSBS) ? $data['package'] : 0,
            'requested_date' => now(),
        ]);
        return [
            'error' => false,
            'data' => [],
            'message' => 'Trial has been activated',
        ];
    }

    public function createStripeChargeForPackage($data){
        $package = Package::find($data['package']);
        $mainSubscription = Subscription::where('store_id',self::$storeId)->latest()->first();
        $stripeCustomerId = $mainSubscription->stripe_id;
        $charge = Charge::create([
            'amount' => bcmul($package->cost, 100),
            'currency' => 'usd',
            'customer' => $stripeCustomerId,
            "description" => 'Real-time Shipping Quotes (SBS) Charge',
            'source' => $stripeData['source'],
        ]);
        dd($data);
    }
}
