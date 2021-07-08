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
use function GuzzleHttp\Promise\all;

class PackageSubscriptionController extends Controller
{
    public static $addonTypeSBS = 'SBS';
    public static $addonTypeRAD = 'RAD';
    public static $trialSBS = 1;
    public static $dynamicTrial = '';
    public static $disableAddon = 'disable';
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
        $addonType = $request['addon_type'];
        $error = false;
        $message = '';
        if ($addonType == self::$addonTypeSBS){
            self::$dynamicTrial = 1;
            $data = $this->getPkgDetails($addonType);
        } elseif($addonType == self::$addonTypeRAD){
            self::$dynamicTrial = 7;
            $data = $this->getPkgDetails($addonType);
        }else{
            $error = true;
            $data = $request->all();
            $message = 'Addon type is missing.';
        }
        return response()->json([
            'error' => $error,
            'data' => $data,
            'message' => $message
        ]);

    }

    //***********************************
    // This method returning the complete details to show on Frontend
    //***********************************
    public function getPkgDetails($addonType){

        $currentPackageSub = DB::table('package_subscriptions as ps')
            ->leftjoin('packages as p','ps.package_id','=','p.id')
            ->leftjoin('package_sub_to_be_charge as pstbc','pstbc.subscription_id','=','ps.id')
            ->select('ps.id','ps.package_id as package_id','ps.subscription_time','ps.update_time','ps.expiry_time','ps.status','ps.created_at','ps.total_count as consumed_hits','p.htis as total_hits','pstbc.package_id as pacakgeId_to_be_charge','pstbc.status as package_to_be_charge_status')
            ->where('ps.store_id',self::$storeId)->where('p.addon_type',$addonType)->latest()->first();

        if (!is_null($currentPackageSub)){
            $currentPkg = Package::where('id',$currentPackageSub->package_id)->first();
            $toBeChargepkg = Package::where('id',$currentPackageSub->pacakgeId_to_be_charge)->first();
            //Current package Details
            $currentPackageSub->current_package_name = $currentPkg->name;
            $currentPackageSub->current_package_period = $currentPkg->period;
            $currentPackageSub->current_package_cost = $currentPkg->cost;
            $currentPackageSub->total_allowed_hits = $currentPkg->htis;
            $currentPackageSub->consumed_hits_in_per = number_format(($currentPackageSub->consumed_hits / $currentPackageSub->total_allowed_hits) * 100,2);
            if ($addonType == self::$addonTypeRAD && $currentPackageSub->current_package_name == 'Extreme'){
                $currentPackageSub->total_allowed_hits = 'Unlimited';
                $currentPackageSub->consumed_hits_in_per = '';
            }
            $currentPackageSub->subscription_start_date = date('M,d,Y', strtotime($currentPackageSub->subscription_time));
            $currentPackageSub->expiry_time = date('M,d,Y', strtotime($currentPackageSub->expiry_time));
            $currentPackageSub->currentPlanText = $currentPackageSub->total_allowed_hits.'/'.lcfirst(substr($currentPackageSub->current_package_period,0,2)).' ($'.number_format($currentPackageSub->current_package_cost,2).')';
            //To Be Charge package Details
            $currentPackageSub->to_be_charge_package_id = $toBeChargepkg->id;

            if ($currentPackageSub->package_to_be_charge_status == 0){
                $currentPackageSub->package_to_be_charge_status = 'disable';
            }
            if ($currentPackageSub->to_be_charge_package_id == self::$dynamicTrial){
                $currentPackageSub->package_to_be_charge_status = 'Trial';
            }
            $currentPackageSub->to_be_charge_package_name = $toBeChargepkg->name;
            $currentPackageSub->to_be_charge_package_period = $toBeChargepkg->period;
            $currentPackageSub->to_be_charge_package_cost = $toBeChargepkg->cost;
            $currentPackageSub->total_allowed_hits_in_to_be_charge = $toBeChargepkg->htis;
            if ($addonType == self::$addonTypeRAD && $currentPackageSub->to_be_charge_package_name == 'Extreme'){
                $currentPackageSub->total_allowed_hits_in_to_be_charge = 'Unlimited';
                $currentPackageSub->consumed_hits_in_per = '';
            }

            $currentPackageSub->toBeChargeDropdownText = $currentPackageSub->total_allowed_hits_in_to_be_charge.'/'.lcfirst(substr($currentPackageSub->to_be_charge_package_period,0,2)).' ($'.number_format($currentPackageSub->to_be_charge_package_cost,2).')';

            $currentPackageSub->last_update_time = $currentPackageSub->update_time;

        }/*else{
            $currentPackageSub = new \stdClass();
            $currentPackageSub->to_be_charge_package_id = 'disabled';
        }*/
        //$packageSub = PackageSubscription::where('store_id',self::$storeId)->latest()->first();
        if (is_null($currentPackageSub)){
            $addonPackages = Package::where('addon_type',$addonType)->orderBy('sort_by','ASC')->get();
        }else{
            $addonPackages = Package::where('addon_type',$addonType)->where('id','!=',self::$dynamicTrial)->orderBy('sort_by','ASC')->get();
        }
        $addonPkgParam = 'allSbsPackages';
        if ($addonType == self::$addonTypeRAD){
            $addonPackages->where('name','Extreme')->first()->htis = 'Unlimited';
            $addonPkgParam = 'allRadPackages';
        }

        return [
            $addonPkgParam => $addonPackages,
            'currentPackage' => $currentPackageSub
        ];
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
            self::$dynamicTrial = 1;
            $responce = $this->subscribeToAddonPackage($data,$addonType);
        } elseif($addonType == self::$addonTypeRAD){
            self::$dynamicTrial = 7;
            $responce = $this->subscribeToAddonPackage($data,$addonType);
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
    public function subscribeToAddonPackage($data, $addonType){
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
                'message' => "You didn't have any Real-time Shipping Quotes Plan to subscribe to the Addon",
            ];
        }
        if (isset($mainSubscription->plan_id) && $mainSubscription->plan_id == self::$mainSubTrial && $data['package'] != self::$dynamicTrial){
            return [
                'error' => true,
                'data' => [],
                'message' => "You must subscribe to the paid plan for the Real-time Shipping Quotes to buy the Addon",
            ];
        }
        $paymentMethod = isset($mainSubscription->payment_method_id) ? $mainSubscription->payment_method_id : null;

        $currentPackageSub = PackageSubscription::leftJoin('packages as p','package_subscriptions.package_id','=','p.id')
            ->where('store_id',self::$storeId)->where('addon_type',$addonType)
            ->select('package_subscriptions.id','package_subscriptions.created_at','package_subscriptions.package_id','package_subscriptions.payment_method_id','package_subscriptions.status','package_subscriptions.subscription_time','package_subscriptions.update_time','package_subscriptions.expiry_time','package_subscriptions.total_count','package_subscriptions.stripe_charge_id','package_subscriptions.charge_cost')
            ->latest()->first();

        //If current subscription is active and it is trial
        if (!is_null($currentPackageSub) && $currentPackageSub->status == 1 && $currentPackageSub->package_id == self::$dynamicTrial && Carbon::parse($currentPackageSub->expiry_time) > Carbon::now()){
            $updateSubscription = self::$updateFullSubscription;
        } elseif (!is_null($currentPackageSub) && $currentPackageSub->status == 1 && Carbon::parse($currentPackageSub->expiry_time) > Carbon::now()){
            //If current subscription is active
            $updateSubscription = self::$updateToBeChargeonly;
        }elseif (!is_null($currentPackageSub) && ($currentPackageSub->status == 0 || Carbon::parse($currentPackageSub->expiry_time) < Carbon::now())){
            //If current subscription expired
            $updateSubscription = self::$updateFullSubscription;
        }elseif (!is_null($currentPackageSub) && ($currentPackageSub->status == 3 || Carbon::parse($currentPackageSub->expiry_time) < Carbon::now())){
            //If current subscription suspended
            $updateSubscription = self::$updateToBeChargeonly;
        }elseif (is_null($currentPackageSub) && isset($data['package']) && $data['package'] != self::$dynamicTrial && $data['package'] != self::$disableAddon){
            // if No Current subscription exist and selected package is not a trial or disable
            $updateSubscription = self::$updateFullSubscription;
        }
        if (($data['package'] != self::$dynamicTrial && $data['package'] != self::$disableAddon) || $updateSubscription == self::$updateFullSubscription){
            if ($updateSubscription == self::$updateFullSubscription){
                $chargeResponse = $this->createStripeChargeForPackage($package,$mainSubscription,$addonType);
            }
        }
        if (!empty($chargeResponse['error']) && $chargeResponse['error'] == true){
            return $chargeResponse;
        }else{
            $chargeId = isset($chargeResponse['data']['chargeId']) ? $chargeResponse['data']['chargeId'] : null;
        }
        if ($chargeId == null && $data['package'] == self::$disableAddon){
            $updateSubscription = self::$updateToBeChargeonly;
        }
        if (!is_null($currentPackageSub) && ($updateSubscription == self::$updateToBeChargeonly || $updateSubscription == self::$updateFullSubscription)){
            //Updating the current package Subscription in database
            $this->updatePackageSubscriptionInDB($data,$package,$paymentMethod,$chargeId,$currentPackageSub, $updateSubscription);
        }else{
            //Saving a new trial or package Subscription in database
            $this->createPackageSubscriptionInDB($data,$package,$paymentMethod,$chargeId);
        }
        $currentPackageDetails = $this->getPkgDetails($addonType);
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
      //  $package_id = isset($data['package']) ? $data['package'] : $currentPackageSub->package_id;
        $package_id = isset($data['package']) ? $data['package'] : $package->id;
        $addDays = (isset($data['package']) && $data['package'] == self::$dynamicTrial) ? 15 : 30;
        $currentPackageSub = PackageSubscription::find($currentPackageSub->id);
        if ($updateSubscription == self::$updateFullSubscription){
            $currentPackageSub->update([
                'store_id' => $data['store_id'],
                'package_id' => $package_id,
                'payment_method_id' => ($package_id != self::$dynamicTrial) ? $paymentMethod : null,
                'status' => 1,
                'subscription_time' => now(),
                'update_time' => now(),
                'expiry_time' => Carbon::now()->addDays($addDays),
                'total_count' => 0,
                'stripe_charge_id' => $chargeId,
                'charge_cost' => $package->cost,
            ]);
        }

        if ((!isset($data['package']) || (!isset($data['package']) && $data['package'] != self::$disableAddon)) && ($updateSubscription == self::$updateToBeChargeonly || $updateSubscription == self::$updateFullSubscription)){

            PackageToBeCharge::where('subscription_id',$currentPackageSub->id)->update([
                'package_id' => $package_id,
                'status' => ($package_id != self::$dynamicTrial && $package_id != self::$disableAddon) ? 1 : 0,
                'requested_date' => now(),
            ]);
        }
        if (isset($data['package']) && $data['package'] == self::$disableAddon && $updateSubscription == self::$updateToBeChargeonly){
            PackageToBeCharge::where('subscription_id',$currentPackageSub->id)->update([
                'status' => ($data['package'] != self::$dynamicTrial && $data['package'] != self::$disableAddon) ? 1 : 0,
                'requested_date' => now(),
            ]);
        }
        if (isset($data['package']) && $data['package'] != self::$disableAddon && ($updateSubscription == self::$updateToBeChargeonly || $updateSubscription == self::$updateFullSubscription)){

            PackageToBeCharge::where('subscription_id',$currentPackageSub->id)->update([
                'package_id' => $data['package'],
                'status' => ($data['package'] != self::$dynamicTrial && $data['package'] != self::$disableAddon) ? 1 : 0,
                'requested_date' => now(),
            ]);
        }
    }
    //***********************************
    // This method saving Package subscription detail and Package to be charge in database
    //***********************************
    public function createPackageSubscriptionInDB($data,$package,$paymentMethod,$chargeId){
        $addDays = (isset($data['package']) && $data['package'] == self::$dynamicTrial) ? 15 : 30;
        $packageSub = PackageSubscription::create([
            'store_id' => $data['store_id'],
            'package_id' => $data['package'],
            'payment_method_id' => ($data['package'] != self::$dynamicTrial) ? $paymentMethod : null,
            'status' => 1,
            'subscription_time' => now(),
            'update_time' => now(),
            'expiry_time' => Carbon::now()->addDays($addDays),
            'total_count' => 0,
            'stripe_charge_id' => $chargeId,
            'charge_cost' => $package->cost,
        ]);
        PackageToBeCharge::create([
            'subscription_id' => $packageSub->id,
            'package_id' => $data['package'],
            'status' => ($data['package'] != self::$dynamicTrial) ? $data['package'] : 0,
            'requested_date' => now(),
        ]);
    }
    //***********************************
    // This method creating charge on stripe
    //***********************************
    public function createStripeChargeForPackage($package,$mainSubscription, $addonType){

        $stripeCustomerId = $mainSubscription->stripe_customer_id;
        try {
            $charge = Charge::create([
                'amount' => bcmul($package->cost, 100),
                'currency' => 'usd',
                'customer' => $stripeCustomerId,
                "description" => 'Real-time Shipping Quotes (BigCommerce '.$addonType.') Charge',
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

        $addonType = $request['addon_type'];
        if ($addonType == self::$addonTypeSBS){
            self::$dynamicTrial = 1;
            $responce = $this->consumeAddonHits($data,$addonType);
        } elseif($addonType == self::$addonTypeRAD){
            self::$dynamicTrial = 7;
            $responce = $this->consumeAddonHits($data,$addonType);
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
    public function consumeAddonHits($data,$addonType){
        $updateSubscription = 0;
        $previousPkgRemainingHits = 0;
        $histToBeConsumed = $data['hits'];
        $currentPackageSub = DB::table('package_subscriptions as ps')
        ->leftjoin('package_sub_to_be_charge as pstbc','pstbc.subscription_id','=','ps.id')
        ->leftjoin('packages as p','ps.package_id','=','p.id')
            ->select('ps.id','ps.package_id as package_id','ps.expiry_time','ps.status','ps.created_at','ps.total_count as consumed_hits','p.htis as total_hits','pstbc.status as package_to_to_charge_status','pstbc.package_id as to_be_charge_package_id')
            ->where('store_id',self::$storeId)->where('p.addon_type',$addonType)->latest()->first();
        if ($currentPackageSub->status == 0 || $currentPackageSub->status == 3) { //If the package subscription is expired || suspended
            return [
                'status' => false,
            ];
        }
        /*if ($currentPackageSub->status == 2){ //If the Charge has been failed
            return [
                'error' => true,
                'data' => $this->getPkgDetails(),
                'message' => 'Your SBS addon has been expired due to charge failed.',
            ];
        }*/
        //If current subscription is active
        if (!is_null($currentPackageSub) && $currentPackageSub->status == 1 && (($currentPackageSub->consumed_hits+$histToBeConsumed) <= $currentPackageSub->total_hits) && Carbon::parse($currentPackageSub->expiry_time) > Carbon::now()){
            //$packageSub = PackageSubscription::where('store_id',self::$storeId)->latest()->first();
            $packageSub = PackageSubscription::leftJoin('packages as p','package_subscriptions.package_id','=','p.id')
                ->where('store_id',self::$storeId)->where('addon_type',$addonType)
                ->select('package_subscriptions.id','package_subscriptions.created_at','package_subscriptions.package_id','package_subscriptions.payment_method_id','package_subscriptions.status','package_subscriptions.subscription_time','package_subscriptions.update_time','package_subscriptions.expiry_time','package_subscriptions.total_count','package_subscriptions.stripe_charge_id','package_subscriptions.charge_cost')
                ->latest()->first();
            $packageSub->increment('total_count',$histToBeConsumed);
            $packageSub->update([
                'update_time' => Carbon::now()
            ]);
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
            //$packageSub = PackageSubscription::where('store_id',self::$storeId)->latest()->first();
            $packageSub = PackageSubscription::leftJoin('packages as p','package_subscriptions.package_id','=','p.id')
                ->where('store_id',self::$storeId)->where('addon_type',$addonType)
                ->select('package_subscriptions.id','package_subscriptions.created_at','package_subscriptions.package_id','package_subscriptions.payment_method_id','package_subscriptions.status','package_subscriptions.subscription_time','package_subscriptions.update_time','package_subscriptions.expiry_time','package_subscriptions.total_count','package_subscriptions.stripe_charge_id','package_subscriptions.charge_cost')
                ->latest()->first();
            //If the trials Hits has consumed then Update the package subscription status to zero
            if ($currentPackageSub->package_id == self::$dynamicTrial){
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

        $package = Package::find($currentPackageSub->to_be_charge_package_id);

        if ($currentPackageSub->package_id != self::$dynamicTrial || $updateSubscription == self::$updateFullSubscription){
            $chargeResponse = $this->createStripeChargeForPackage($package,$mainSubscription,$addonType);
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
        //$packageSub = PackageSubscription::where('store_id',self::$storeId)->latest()->first();
        $packageSub = PackageSubscription::leftJoin('packages as p','package_subscriptions.package_id','=','p.id')
            ->select('package_subscriptions.id','package_subscriptions.created_at','package_subscriptions.package_id','package_subscriptions.update_time','package_subscriptions.total_count')
            ->where('store_id',self::$storeId)->where('addon_type',$addonType)
            ->latest()->first();

        $packageSub->increment('total_count',$histToBeConsumed);
        $packageSub->update([
            'update_time' => Carbon::now()
        ]);
        //Get the current package details after updating the package subscription
        return [
            'status' => true,
        ];
    }
    //***********************************
    // This method returning the complete details to show on Frontend via route
    //***********************************
    public function getAddonPackageDetails(Request $request){
        self::$storeId = $request['store_id'];
        $addonType = $request['addon_type'];

        if ($addonType == self::$addonTypeSBS){
            self::$dynamicTrial = 1;
        }elseif ($addonType == self::$addonTypeRAD){
            self::$dynamicTrial = 7;
        }
        $currentPackageSub = $this->getPkgDetails($addonType);
        if (is_null($currentPackageSub)){
            return response()->json([
                'error' => false,
                'data' => ['status' => 0],
                'message' => 'No current subscribed '.$addonType.' addon is available'
            ]);
        }else{
            return response()->json([
                'error' => false,
                'data' => $currentPackageSub,
                'message' => $addonType.' addon subscription details found'
            ]);
        }
    }

    //***********************************
    // This method is used to decide to suspend the SBS or RAD Addon
    //***********************************
    public function suspendAddonUse(Request $request){
        self::$storeId = $data['store_id'] = $request['store_id'];
        $data['suspend'] = $request['suspend'];
        $addonType = $request['addon_type'];
        if ($addonType == self::$addonTypeSBS){
            self::$dynamicTrial = 1;
            $responce = $this->suspendUsage($data,$addonType);
        } elseif($addonType == self::$addonTypeRAD){
            self::$dynamicTrial = 7;
            $responce = $this->suspendUsage($data,$addonType);
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
    public function suspendUsage($data,$addonType){
        //For Suspend = 3
        //For No Suspend = 1
        $currentPackageSub = PackageSubscription::leftJoin('packages as p','package_subscriptions.package_id','=','p.id')
            ->where('store_id',self::$storeId)->where('addon_type',$addonType)
            ->select('package_subscriptions.id','package_subscriptions.created_at','package_subscriptions.package_id','package_subscriptions.payment_method_id','package_subscriptions.status','package_subscriptions.subscription_time','package_subscriptions.update_time','package_subscriptions.expiry_time','package_subscriptions.total_count','package_subscriptions.stripe_charge_id','package_subscriptions.charge_cost')
            ->latest()->first();
        $currentPackageSub->status = $data['suspend'];
        $currentPackageSub->save();
        return [
            'error' => false,
            'data' => $this->getPkgDetails($addonType),
            'message' => ($data['suspend'] == 3) ? 'The '.$addonType.' addon has been suspended' : 'The '.$addonType.' addon has been reactivated',
        ];
    }
}
