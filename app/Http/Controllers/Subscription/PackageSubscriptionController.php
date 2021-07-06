<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Models\Subscription\Package;
use App\Models\Subscription\PackageSubscription;
use App\Models\Subscription\PackageToBeCharge;
use App\Models\Subscription\Subscription;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Charge;
use Stripe\Stripe;

class PackageSubscriptionController extends Controller
{
    public static $addonTypeSBS = 'SBS';
    public static $addonTypeRAD = 'RAD';
    public static $trialSBS = 1;
    public static $disableSBS = 'disable';
    public static $mainSubTrial = 1;
    public static $storeId = 0;
    public static $updateFullSubscription = 2;
    public static $updateToBeChargeonly = 1;
    public static $minSbsPaidPackage = 2;
    public function __construct(){
        Stripe::setApiKey(config('app.stripe_secret'));
    }

    public function getAllPackagesList(Request $request){
        self::$storeId = $request['store_id'];

        return response()->json([
            'error' => false,
            'data' => $this->getSbsDetails(),
            'message' => ''
        ]);
    }
    //***********************************
    // This method deciding which addon type package we need to subscribe
    //***********************************
    public function subscribeToPackage(Request $request){
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
    //***********************************
    // This method handling different cases and end purpose is to subscribe
    // to Trial, SBS package or Change SBS Package then update it to database
    //***********************************
    public function subscribeToSBSPackage($data){
        $chargeResponse = [];
        $chargeId = null;
        $updateSubscription = 0;
        $package = Package::find($data['package']);
        $mainSubscription = DB::table('subscriptions as s')
            ->leftJoin('payment_methods as p','p.store_id','=','s.store_id')
            ->select('s.stripe_id as stripe_customer_id','s.payment_method','s.plan_id','s.created_at','p.id as payment_method_id')
            ->where('s.store_id',self::$storeId)->latest()->first();

        if (is_null($mainSubscription)){
            return [
                'error' => true,
                'data' => [],
                'message' => "You didn't have any Quoting Plan to subscribe to the Addon",
            ];
        }
        if (isset($mainSubscription->plan_id) && $mainSubscription->plan_id == self::$mainSubTrial && $data['package'] != self::$trialSBS){
            return [
                'error' => true,
                'data' => [],
                'message' => "You must subscribe to the Paid Plan for the Quoting to buy the Addon",
            ];
        }
        $paymentMethod = isset($mainSubscription->payment_method_id) ? $mainSubscription->payment_method_id : null;

        $currentPackageSub = PackageSubscription::where('store_id',self::$storeId)->latest()->first();
        //If current subscription is active and it is trial
        if (!is_null($currentPackageSub) && $currentPackageSub->status == 1 && $currentPackageSub->package_id == 1 && Carbon::parse($currentPackageSub->expiry_time) > Carbon::now()){
            $updateSubscription = self::$updateFullSubscription;
        } elseif (!is_null($currentPackageSub) && $currentPackageSub->status == 1 && Carbon::parse($currentPackageSub->expiry_time) > Carbon::now()){
            //If current subscription is active
            $updateSubscription = self::$updateToBeChargeonly;
        }elseif (!is_null($currentPackageSub) && ($currentPackageSub->status != 1 || Carbon::parse($currentPackageSub->expiry_time) < Carbon::now())){
            //If current subscription expired
            $updateSubscription = self::$updateFullSubscription;
        }
        if (($data['package'] != self::$trialSBS && $data['package'] != self::$disableSBS) || $updateSubscription == self::$updateFullSubscription){
            if ($updateSubscription == self::$updateFullSubscription){
                $chargeResponse = $this->createStripeChargeForPackage($package,$mainSubscription);
            }
        }
        if (!empty($chargeResponse['error']) && $chargeResponse['error'] == true){
            return $chargeResponse;
        }else{
            $chargeId = isset($chargeResponse['data']['chargeId']) ? $chargeResponse['data']['chargeId'] : null;
        }
        if ($chargeId == null && $data['package'] == self::$disableSBS){
            $updateSubscription = self::$updateToBeChargeonly;
        }
        if ($updateSubscription == self::$updateToBeChargeonly || $updateSubscription == self::$updateFullSubscription){
            //Updating the current package Subscription in database
            $this->updatePackageSubscriptionInDB($data,$package,$paymentMethod,$chargeId,$currentPackageSub, $updateSubscription);
        }else{
            //Saving a new trial or package Subscription in database
            $this->createPackageSubscriptionInDB($data,$package,$paymentMethod,$chargeId);
        }
        $currentPackageDetails = $this->getSbsDetails();
        return [
            'error' => false,
            'data' => $currentPackageDetails,
            'message' => 'Package has been updated',
        ];
    }
    //***********************************
    // This method updating Package subscription detail and Package to be charge in database
    //***********************************
    public function updatePackageSubscriptionInDB($data,$package,$paymentMethod,$chargeId,$currentPackageSub, $updateSubscription){
        $package_id = isset($data['package']) ? $data['package'] : $currentPackageSub->package_id;
        $currentPackageSub = PackageSubscription::find($currentPackageSub->id);
        if ($updateSubscription == self::$updateFullSubscription){
            $currentPackageSub->update([
                'store_id' => $data['store_id'],
                'package_id' => $package_id,
                'payment_method_id' => ($package_id != self::$trialSBS) ? $paymentMethod : null,
                'status' => 1,
                'subscription_time' => now(),
                'update_time' => now(),
                'expiry_time' => Carbon::now()->addDays(30),
                'total_count' => 0,
                'stripe_charge_id' => $chargeId,
                'charge_cost' => $package->cost,
            ]);
        }

        if ((!isset($data['package']) || (!isset($data['package']) && $data['package'] != self::$disableSBS)) && ($updateSubscription == self::$updateToBeChargeonly || $updateSubscription == self::$updateFullSubscription)){

            PackageToBeCharge::where('subscription_id',$currentPackageSub->id)->update([
                'package_id' => $package_id,
                'status' => ($package_id != self::$trialSBS && $package_id != self::$disableSBS) ? 1 : 0,
                'requested_date' => now(),
            ]);
        }
        if (isset($data['package']) && $data['package'] == self::$disableSBS && $updateSubscription == self::$updateToBeChargeonly){
            PackageToBeCharge::where('subscription_id',$currentPackageSub->id)->update([
                'status' => ($data['package'] != self::$trialSBS && $data['package'] != self::$disableSBS) ? 1 : 0,
                'requested_date' => now(),
            ]);
        }
        if (isset($data['package']) && $data['package'] != self::$disableSBS && ($updateSubscription == self::$updateToBeChargeonly || $updateSubscription == self::$updateFullSubscription)){
            PackageToBeCharge::where('subscription_id',$currentPackageSub->id)->update([
                'package_id' => $data['package'],
                'status' => ($data['package'] != self::$trialSBS && $data['package'] != self::$disableSBS) ? 1 : 0,
                'requested_date' => now(),
            ]);
        }
    }
    //***********************************
    // This method saving Package subscription detail and Package to be charge in database
    //***********************************
    public function createPackageSubscriptionInDB($data,$package,$paymentMethod,$chargeId){
        $packageSub = PackageSubscription::create([
            'store_id' => $data['store_id'],
            'package_id' => $data['package'],
            'payment_method_id' => ($data['package'] != self::$trialSBS) ? $paymentMethod : null,
            'status' => 1,
            'subscription_time' => now(),
            'update_time' => now(),
            'expiry_time' => Carbon::now()->addDays(30),
            'total_count' => 0,
            'stripe_charge_id' => $chargeId,
            'charge_cost' => $package->cost,
        ]);
        PackageToBeCharge::create([
            'subscription_id' => $packageSub->id,
            'package_id' => $data['package'],
            'status' => ($data['package'] != self::$trialSBS) ? $data['package'] : 0,
            'requested_date' => now(),
        ]);
    }
    //***********************************
    // This method creating charge on stripe
    //***********************************
    public function createStripeChargeForPackage($package,$mainSubscription){

        $stripeCustomerId = $mainSubscription->stripe_customer_id;
        try {
            $charge = Charge::create([
                'amount' => bcmul($package->cost, 100),
                'currency' => 'usd',
                'customer' => $stripeCustomerId,
                "description" => 'Real-time Shipping Quotes (SBS) Charge',
                'source' => $mainSubscription->payment_method,
            ]);
            $response = [
                'chargeId' => $charge->id
            ];
        }catch (\Exception $exception){
            return [
                'error' => true,
                'data' => [],
                'message' => $exception->getMessage(),
            ];
        }
        return [
            'error' => false,
            'data' => $response,
            'message' => 'The package has been charged successfully',
        ];
    }
    //***********************************
    // This method is used to decide which Addon Hits are to be consumed
    //***********************************
    public function consumeHits($request){
        self::$storeId = $data['store_id'] = $request['store_id'];
        $data['hits'] = $request['hits'];
        //dd($request->all());
        $addonType = $request['addon_type'];
        if ($addonType == self::$addonTypeSBS){
            $responce = $this->consumeSbsHits($data);
        } elseif($addonType == self::$addonTypeRAD){
            //Do Nothing Yet
        }else{
            $responce = [
                'error' => true,
                'message' => 'Addon type is missing.',
                'data' => $request->all(),
            ];
        }
        return $responce;
    }
    //***********************************
    // This method handling different scnarios and end purpose is to consume required number of hits
    //***********************************
    public function consumeSbsHits($data){
        $updateSubscription = 0;
        $previousPkgRemainingHits = 0;
        $histToBeConsumed = $data['hits'];
        $currentPackageSub = DB::table('package_subscriptions as ps')
        ->leftjoin('package_sub_to_be_charge as pstbc','pstbc.subscription_id','=','ps.id')
        ->leftjoin('packages as p','ps.package_id','=','p.id')
            ->select('ps.id','ps.package_id as package_id','ps.expiry_time','ps.status','ps.created_at','ps.total_count as consumed_hits','p.htis as total_hits','pstbc.status as package_to_to_charge_status')
            ->where('store_id',self::$storeId)->where('p.addon_type',self::$addonTypeSBS)->latest()->first();

        if ($currentPackageSub->status == 0) { //If the package subscription is expired
            return [
                'status' => false,
            ];
        }
        if ($currentPackageSub->status == 3){ //If the package subscription is suspended
            return [
                'status' => false,
            ];
        }
        /*if ($currentPackageSub->status == 2){ //If the Charge has been failed
            return [
                'error' => true,
                'data' => $this->getSbsDetails(),
                'message' => 'Your SBS addon has been expired due to charge failed.',
            ];
        }*/
        //If current subscription is active
        if (!is_null($currentPackageSub) && $currentPackageSub->status == 1 && (($currentPackageSub->consumed_hits+$histToBeConsumed) <= $currentPackageSub->total_hits) && Carbon::parse($currentPackageSub->expiry_time) > Carbon::now()){
            $packageSub = PackageSubscription::where('store_id',self::$storeId)->latest()->first();
            $packageSub->increment('total_count',$histToBeConsumed);
            return [
                'status' => true,
            ];
        } elseif (!is_null($currentPackageSub) && ($currentPackageSub->package_to_to_charge_status == 1 && Carbon::parse($currentPackageSub->expiry_time) < Carbon::now())){
            //If current subscription expired
            $updateSubscription = self::$updateFullSubscription;
        } elseif (!is_null($currentPackageSub) && ($currentPackageSub->package_to_to_charge_status == 1 && ($currentPackageSub->consumed_hits+$histToBeConsumed) > $currentPackageSub->total_hits)){
            //If current subscription hits becomes less to use
            $updateSubscription = self::$updateFullSubscription;
            $previousPkgRemainingHits = $currentPackageSub->total_hits - $currentPackageSub->consumed_hits;
        }elseif (!is_null($currentPackageSub) && ($currentPackageSub->package_to_to_charge_status == 0 && ($currentPackageSub->consumed_hits+$histToBeConsumed) > $currentPackageSub->total_hits)){
            //If current subscription hits becomes less to use and next occaurance is disabled
            return [
                'status' => false,
            ];
        }

        if (!is_null($currentPackageSub) && $currentPackageSub->status == 1 && (($currentPackageSub->consumed_hits+$histToBeConsumed) >= $currentPackageSub->total_hits)){
            $packageSub = PackageSubscription::where('store_id',self::$storeId)->latest()->first();
            //If the trials Hits has consumed then Update the package subscription status to zero
            if ($currentPackageSub->package_id == self::$trialSBS){
                $packageSub->update([
                    'status' => 0
                ]);
                if ($updateSubscription == 0){
                    return [
                        'status' => false,
                    ];
                }
            }
        }
        //Get the main subscription to get the stripe customer ID and Payment method
        $mainSubscription = DB::table('subscriptions as s')
            ->leftJoin('payment_methods as p','p.store_id','=','s.store_id')
            ->select('s.stripe_id as stripe_customer_id','s.payment_method','s.plan_id','s.created_at','p.id as payment_method_id')
            ->where('s.store_id',self::$storeId)->latest()->first();

        $paymentMethod = isset($mainSubscription->payment_method_id) ? $mainSubscription->payment_method_id : null;
        $package = Package::find($currentPackageSub->package_id);

        if ($currentPackageSub->package_id != self::$trialSBS || $updateSubscription == self::$updateFullSubscription){
            $chargeResponse = $this->createStripeChargeForPackage($package,$mainSubscription);
        }
        if (!empty($chargeResponse['error']) && $chargeResponse['error'] == true){
            // If charge is not successfull then update status = 2 to identify (Charge failed)
            $packageSub = PackageSubscription::where('store_id',self::$storeId)->latest()->first();
            //If the trials Hits has consumed then Update the package subscription status to zero
            $packageSub->update([
                'status' => 0
            ]);
            return [
                'status' => false,
            ];
            //return $chargeResponse;
        }else{
            $chargeId = isset($chargeResponse['data']['chargeId']) ? $chargeResponse['data']['chargeId'] : null;
        }
        if ($updateSubscription == self::$updateToBeChargeonly || $updateSubscription == self::$updateFullSubscription){
            //Updating the current package Subscription in database
            $this->updatePackageSubscriptionInDB($data,$package,$paymentMethod,$chargeId,$currentPackageSub, $updateSubscription);
        }
        $histToBeConsumed = $histToBeConsumed - $previousPkgRemainingHits;
        //Consuming Hits after recharge
        $packageSub = PackageSubscription::where('store_id',self::$storeId)->latest()->first();
        $packageSub->increment('total_count',$histToBeConsumed);
        $packageSub->update([
            'subscription_time' => Carbon::now()
        ]);
        //Get the current package details after updating the package subscription
        return [
            'status' => true,
        ];
    }
    //***********************************
    // This method returning the complete details to show on Frontend via route
    //***********************************
    public function getSbsPackageDetails(Request $request){
        self::$storeId = $request['store_id'];
        $currentPackageSub = $this->getSbsDetails();
        if (is_null($currentPackageSub)){
            return response()->json([
                'error' => false,
                'data' => ['status' => 0],
                'message' => 'No current subscribed SBS addon is available'
            ]);
        }else{
            return response()->json([
                'error' => false,
                'data' => $currentPackageSub,
                'message' => 'SBS addon subscription details found'
            ]);
        }
    }
    //***********************************
    // This method returning the complete details to show on Frontend
    //***********************************
    public function getSbsDetails(){
        $currentPackageSub = DB::table('package_subscriptions as ps')
            ->leftjoin('packages as p','ps.package_id','=','p.id')
            ->leftjoin('package_sub_to_be_charge as pstbc','pstbc.subscription_id','=','ps.id')
            ->select('ps.id','ps.package_id as package_id','ps.subscription_time','ps.expiry_time','ps.status','ps.created_at','ps.total_count as consumed_hits','p.htis as total_hits','pstbc.package_id as pacakgeId_to_be_charge','pstbc.status as package_to_be_charge_status')
            ->where('ps.store_id',self::$storeId)->where('p.addon_type',self::$addonTypeSBS)->latest()->first();

        if (!is_null($currentPackageSub)){
            $currentPkg = Package::where('id',$currentPackageSub->package_id)->first();
            $toBeChargepkg = Package::where('id',$currentPackageSub->pacakgeId_to_be_charge)->first();

            //Current package Details
            $currentPackageSub->current_package_name = $currentPkg->name;
            $currentPackageSub->current_package_period = $currentPkg->period;
            $currentPackageSub->current_package_cost = $currentPkg->cost;
            $currentPackageSub->total_allowed_hits = $currentPkg->htis;
            $currentPackageSub->consumed_hits_in_per = ($currentPackageSub->consumed_hits / $currentPackageSub->total_allowed_hits) * 100;
            $currentPackageSub->subscription_start_date = date('M,d,Y', strtotime($currentPackageSub->subscription_time));
            $currentPackageSub->expiry_time = date('M,d,Y', strtotime($currentPackageSub->expiry_time));
            $currentPackageSub->currentPlanText = $currentPkg->htis.'/'.lcfirst(substr($currentPackageSub->current_package_period,0,2)).' ($'.number_format($currentPackageSub->current_package_cost,2).')';
            //To Be Charge package Details
            $currentPackageSub->to_be_charge_package_id = $toBeChargepkg->id;

            if ($currentPackageSub->package_to_be_charge_status == 0){
                $currentPackageSub->package_to_be_charge_status = 'disable';
            }
            if ($currentPackageSub->to_be_charge_package_id == 1){
                $currentPackageSub->package_to_be_charge_status = 'Trial';
            }

            $currentPackageSub->to_be_charge_package_name = $toBeChargepkg->name;
            $currentPackageSub->to_be_charge_package_period = $toBeChargepkg->period;
            $currentPackageSub->to_be_charge_package_cost = $toBeChargepkg->cost;
            $currentPackageSub->total_allowed_hits_in_to_be_charge = $toBeChargepkg->htis;
            $currentPackageSub->toBeChargeDropdownText = $toBeChargepkg->htis.'/'.lcfirst(substr($currentPackageSub->to_be_charge_package_period,0,2)).' ($'.number_format($currentPackageSub->to_be_charge_package_cost,2).')';

            $currentPackageSub->last_update_time = $currentPkg->subscription_time;

        }/*else{
            $currentPackageSub = new \stdClass();
            $currentPackageSub->to_be_charge_package_id = 'disabled';
        }*/
        //$packageSub = PackageSubscription::where('store_id',self::$storeId)->latest()->first();
        if (is_null($currentPackageSub)){
            $sbsPackages = Package::where('addon_type',self::$addonTypeSBS)->get();
        }else{
            $sbsPackages = Package::where('addon_type',self::$addonTypeSBS)->where('id','!=',self::$trialSBS)->get();
        }
        return [
            'allSbsPackages' => $sbsPackages,
            'currentPackage' => $currentPackageSub
        ];
    }
    //***********************************
    // This method is used to decide to suspend the SBS or RAD Addon
    //***********************************
    public function suspendAddonUse(Request $request){
        self::$storeId = $data['store_id'] = $request['store_id'];
        $data['suspend'] = $request['suspend'];
        $addonType = $request['addon_type'];
        if ($addonType == self::$addonTypeSBS){
            $responce = $this->suspendSbsUsage($data);
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
    //***********************************
    // This method is updating the suspend or un-suspend status in DB
    //***********************************
    public function suspendSbsUsage($data){
        //For Suspend = 3
        //For No Suspend = 1
        PackageSubscription::where('store_id',self::$storeId)->update([
            'status' => $data['suspend']
        ]);
        return [
            'error' => false,
            'data' => $this->getSbsDetails(),
            'message' => ($data['suspend'] == 3) ? 'The SBS addon has been suspended' : 'The SBS addon has been reactivated',
        ];
    }
}
