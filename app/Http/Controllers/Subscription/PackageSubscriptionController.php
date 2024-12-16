<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Mail\AddonPackageUpdateMail;
use App\Models\Subscription\Package;
use App\Models\Subscription\PackageSubscription;
use App\Models\Subscription\PackageToBeCharge;
use App\Models\Subscription\Subscription;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Charge;
use Stripe\Stripe;
use function GuzzleHttp\Promise\all;
use App\Models\InstalledAddon;
use App\Models\AddonSettings;
use App\CustomClasses\Functions;
use App\Models\SubscriptionStripePayments;

class PackageSubscriptionController extends Controller
{
    public static $addonTypeSBS = 'SBS';
    public static $addonTypePLT = 'PLT';
    public static $addonTypeRAD = 'RAD';
    public static $trialSBS = 1;
    public static $dynamicTrial = '';
    public static $disableAddon = 'disable';
    public static $mainSubTrial = 1;
    public static $storeId = 0;
    public static $updateFullSubscription = 2;
    public static $updateToBeChargeonly = 1;
    public static $minSbsPaidPackage = 2;
    public static $palletPkgDynamicTrial = 15;
    public static $dynamicDevPlan = '';
    public static $SBSPkgDynamicDev = 21;
    public static $RadPkgDynamicDev = 22;
    public static $palletPkgDynamicDev = 23;

    public function __construct()
    {

    }

    public function getAllPackagesList(Request $request)
    {

        self::$storeId = $request['store_id'];
        $addonType = $request['addon_type'];
        $error = false;
        $message = '';
        if ($addonType == self::$addonTypeSBS) {
            self::$dynamicTrial = 1;
            self::$dynamicDevPlan = self::$SBSPkgDynamicDev;
            $data = $this->getPkgDetails($addonType);
            $data['binPackMode'] = $this->getBinMode($request);
        } elseif ($addonType == self::$addonTypeRAD) {
            self::$dynamicTrial = 7;
            self::$dynamicDevPlan = self::$RadPkgDynamicDev;
            $data = $this->getPkgDetails($addonType);
        } elseif ($addonType == self::$addonTypePLT) {
            self::$dynamicTrial = self::$palletPkgDynamicTrial;
            self::$dynamicDevPlan = self::$palletPkgDynamicDev;
            $data = $this->getPkgDetails($addonType);
        } else {
            $error = true;
            $data = $request->all();
            $message = 'Add-on type is missing.';
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
    public function getPkgDetails($addonType)
    {

        $currentPackageSub = DB::table('package_subscriptions as ps')
            ->leftjoin('packages as p', 'ps.package_id', '=', 'p.id')
            ->leftjoin('package_sub_to_be_charge as pstbc', 'pstbc.subscription_id', '=', 'ps.id')
            ->select('ps.id', 'ps.package_id as package_id', 'ps.subscription_time', 'ps.update_time', 'ps.expiry_time', 'ps.status', 'ps.created_at', 'ps.total_count as consumed_hits', 'p.htis as total_hits', 'pstbc.package_id as pacakgeId_to_be_charge', 'pstbc.status as package_to_be_charge_status')
            ->where('ps.store_id', self::$storeId)->where('p.addon_type', $addonType)->latest()->first();

        if (!is_null($currentPackageSub)) {
            $currentPkg = Package::where('id', $currentPackageSub->package_id)->first();
            $toBeChargepkg = Package::where('id', $currentPackageSub->pacakgeId_to_be_charge)->first();
            //Current package Details
            $currentPackageSub->current_package_name = $currentPkg->name;
            $currentPackageSub->current_package_period = $currentPkg->period;
            $currentPackageSub->current_package_cost = number_format($currentPkg->cost, 2);
            $currentPackageSub->total_allowed_hits = $currentPkg->htis;
            $currentPackageSub->consumed_hits_in_per = number_format(($currentPackageSub->consumed_hits / $currentPackageSub->total_allowed_hits) * 100, 2);
            if ($addonType == self::$addonTypeRAD && $currentPackageSub->current_package_name == 'Extreme') {
                $currentPackageSub->total_allowed_hits = 'Unlimited';
                $currentPackageSub->consumed_hits_in_per = '';
            }
            $currentPackageSub->subscription_start_date = date('M,d,Y', strtotime($currentPackageSub->subscription_time));
            $currentPackageSub->expiry_time = date('M,d,Y', strtotime($currentPackageSub->expiry_time));
            $currentPackageSub->currentPlanText = $currentPackageSub->total_allowed_hits . '/' . lcfirst(substr($currentPackageSub->current_package_period, 0, 2)) . ' ($' . $currentPackageSub->current_package_cost . ')';
            //To Be Charge package Details
            $currentPackageSub->to_be_charge_package_id = $toBeChargepkg->id ?? '';

            if ($currentPackageSub->package_to_be_charge_status == 0) {
                $currentPackageSub->package_to_be_charge_status = 'disable';
            }
            if ($currentPackageSub->to_be_charge_package_id == self::$dynamicTrial) {
                $currentPackageSub->package_to_be_charge_status = 'Trial';
            }
            if ($currentPackageSub->to_be_charge_package_id == self::$dynamicDevPlan) {
                $currentPackageSub->package_to_be_charge_status = 'Development Plan';
            }
            $currentPackageSub->to_be_charge_package_name = $toBeChargepkg->name ?? '';
            $currentPackageSub->to_be_charge_package_period = $toBeChargepkg->period ?? '';
            $currentPackageSub->to_be_charge_package_cost = $toBeChargepkg->cost ?? '';
            $currentPackageSub->total_allowed_hits_in_to_be_charge = $toBeChargepkg->htis ?? '';
            if ($addonType == self::$addonTypeRAD && $currentPackageSub->to_be_charge_package_name == 'Extreme') {
                $currentPackageSub->total_allowed_hits_in_to_be_charge = 'Unlimited';
                //$currentPackageSub->consumed_hits_in_per = '';
            }
            $currentPackageSub_to_be_charge_package_cost = isset($currentPackageSub->to_be_charge_package_cost) && $currentPackageSub->to_be_charge_package_cost != "" ? $currentPackageSub->to_be_charge_package_cost : 0.00;

            $currentPackageSub->toBeChargeDropdownText = $currentPackageSub->total_allowed_hits_in_to_be_charge . '/' . lcfirst(substr($currentPackageSub->to_be_charge_package_period, 0, 2)) . ' ($' . number_format($currentPackageSub_to_be_charge_package_cost, 2) . ')';

            $currentPackageSub->last_update_time = $currentPackageSub->update_time;

        }/*else{
            $currentPackageSub = new \stdClass();
            $currentPackageSub->to_be_charge_package_id = 'disabled';
        }*/
        //$packageSub = PackageSubscription::where('store_id',self::$storeId)->latest()->first();
        if (is_null($currentPackageSub)) {
            $addonPackages = Package::where('addon_type', $addonType)->orderBy('sort_by', 'ASC')->get();
        } else {
            $addonPackages = Package::where('addon_type', $addonType)->where('id', '!=', self::$dynamicTrial)->where('id', '!=', self::$dynamicDevPlan)->orderBy('sort_by', 'ASC')->get();
        }
        $addonPkgParam = $addonType == self::$addonTypeSBS ? 'allSbsPackages' : 'allPalletPackages';
        if ($addonType == self::$addonTypeRAD) {
            $addonPackages->where('name', 'Extreme')->first()->htis = 'Unlimited';
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
    public function subscribeToPackage(Request $request)
    {
        self::$storeId = $data['store_id'] = $request['store_id'];
        $data['package'] = $request['package'];
        $data['email'] = $request['email'];
        $addonType = $request['addon_type'];
        if ($addonType == self::$addonTypeSBS) {
            self::$dynamicTrial = 1;
            self::$dynamicDevPlan = self::$SBSPkgDynamicDev;
            $responce = $this->subscribeToAddonPackage($data, $addonType);
            $responce['data']['binPackMode'] = $this->getBinMode($request);
        } elseif ($addonType == self::$addonTypeRAD) {
            self::$dynamicTrial = 7;
            self::$dynamicDevPlan = self::$RadPkgDynamicDev;
            $responce = $this->subscribeToAddonPackage($data, $addonType);
            //Do Nothing Yet
        } elseif ($addonType == self::$addonTypePLT) {
            self::$dynamicTrial = self::$palletPkgDynamicTrial;
            self::$dynamicDevPlan = self::$palletPkgDynamicDev;
            $responce = $this->subscribeToAddonPackage($data, $addonType);
        } else {
            $responce = [
                'error' => true,
                'message' => 'Add-on type is missing.',
                'data' => $request->all(),
            ];
        }

        return response()->json($responce);
    }
    //***********************************
    // This method handling different cases and end purpose is to subscribe
    // to Trial, SBS package or Change SBS Package then update it to database
    //***********************************
    public function subscribeToAddonPackage($data, $addonType)
    {
        $chargeResponse = [];
        $chargeId = null;


        $updateSubscription = 0;
        $package = Package::find($data['package']);
        $mainSubscription = DB::table('subscriptions as s')
            ->leftJoin('payment_methods as p', 'p.store_id', '=', 's.store_id')
            ->select('s.stripe_id as stripe_customer_id', 's.payment_method', 's.plan_id', 's.email', 's.created_at', 'p.id as payment_method_id')
            ->where('s.store_id', self::$storeId)->latest()->first();


        if (is_null($mainSubscription)) {
            return [
                'error' => true,
                'data' => [],
                'message' => "You don't have any Real-time Shipping Quotes Plan to subscribe the Addon",
            ];
        }
        if (isset($mainSubscription->plan_id) && $mainSubscription->plan_id == self::$mainSubTrial && ($data['package'] != self::$dynamicTrial && $data['package'] != self::$dynamicDevPlan)) {
            return [
                'error' => true,
                'data' => [],
                'message' => "You must subscribe to the paid plan for the Real-time Shipping Quotes to buy the Add-on",
            ];
        }
        $paymentMethod = isset($mainSubscription->payment_method_id) ? $mainSubscription->payment_method_id : null;

        $currentPackageSub = PackageSubscription::leftJoin('packages as p', 'package_subscriptions.package_id', '=', 'p.id')
            ->where('store_id', self::$storeId)->where('addon_type', $addonType)
            ->select('package_subscriptions.id', 'package_subscriptions.created_at', 'package_subscriptions.package_id', 'package_subscriptions.payment_method_id', 'package_subscriptions.status', 'package_subscriptions.subscription_time', 'package_subscriptions.update_time', 'package_subscriptions.expiry_time', 'package_subscriptions.total_count', 'package_subscriptions.stripe_charge_id', 'package_subscriptions.charge_cost')
            ->latest()->first();

        //Setting either to update subscription,create charge and create subscription
        // self::$updateToBeChargeonly means we only need t update the package_to_be_charge table
        // self::$updateFullSubscription means we will update both current package and  update the package_to_be_charge table as well
        //If current subscription is active and it is trial
        if (!is_null($currentPackageSub) && $currentPackageSub->status == 1 && $currentPackageSub->package_id == self::$dynamicTrial && Carbon::parse($currentPackageSub->expiry_time) > Carbon::now()) {
            //Earlier when it was trial and whenever customer selects the paid plan, it was updating that to the paid plan rather just updating the auto-renewal
            //it was set to $updateSubscription = $updateFullSubscription;
            $updateSubscription = self::$updateToBeChargeonly;
        } elseif (!is_null($currentPackageSub) && $currentPackageSub->status == 1 && Carbon::parse($currentPackageSub->expiry_time) > Carbon::now()) {
            //If current subscription is active
            $updateSubscription = self::$updateToBeChargeonly;
        } elseif (!is_null($currentPackageSub) && ($currentPackageSub->status == 0 || Carbon::parse($currentPackageSub->expiry_time) < Carbon::now())) {
            //If current subscription expired
            $updateSubscription = self::$updateFullSubscription;
        } elseif (!is_null($currentPackageSub) && ($currentPackageSub->status == 3 || Carbon::parse($currentPackageSub->expiry_time) < Carbon::now())) {
            //If current subscription suspended
            $updateSubscription = self::$updateToBeChargeonly;
        } elseif (is_null($currentPackageSub) && isset($data['package']) && ($data['package'] != self::$dynamicTrial && $data['package'] != self::$dynamicDevPlan) && $data['package'] != self::$disableAddon) {
            // if No Current subscription exist and selected package is not a trial or disable
            $updateSubscription = self::$updateFullSubscription;
        }


        if ((($data['package'] != self::$dynamicTrial && $data['package'] != self::$dynamicDevPlan) && $data['package'] != self::$disableAddon) && $updateSubscription == self::$updateFullSubscription) {
            $chargeResponse = $this->createStripeChargeForPackage($package, $mainSubscription, $addonType);
        }

        if (!empty($chargeResponse['error']) && $chargeResponse['error']) {
            return $chargeResponse;
        } else {
            $chargeId = isset($chargeResponse['data']['chargeId']) ? $chargeResponse['data']['chargeId'] : null;
        }

        //Means customer want to disable the auto-renewal
        if ($chargeId == null && $data['package'] == self::$disableAddon) {
            $updateSubscription = self::$updateToBeChargeonly;
        }


        if (!is_null($currentPackageSub) && ($updateSubscription == self::$updateToBeChargeonly || $updateSubscription == self::$updateFullSubscription)) {
            //Updating the current package Subscription in database
            $this->updatePackageSubscriptionInDB($data, $package, $paymentMethod, $chargeId, $currentPackageSub, $updateSubscription);
        } else {
            //Saving a new trial or package Subscription in database
            $this->createPackageSubscriptionInDB($data, $package, $paymentMethod, $chargeId);
        }

        $currentPackageDetails = $this->getPkgDetails($addonType);

        if ($updateSubscription == self::$updateFullSubscription &&
            !empty($mainSubscription->email) &&
            (isset($data['package']) && ($data['package'] != self::$dynamicTrial && $data['package'] != self::$dynamicDevPlan))
        ) {
            Mail::to($mainSubscription->email)->send(new AddonPackageUpdateMail($addonType, $currentPackageDetails['currentPackage']));
        }

        return [
            'error' => false,
            'data' => $currentPackageDetails,
            'message' => 'Package has been updated',
        ];
    }
    //***********************************
    // This method updating Package subscription detail and Package to be charge in database
    //***********************************
    public function updatePackageSubscriptionInDB($data, $package, $paymentMethod, $chargeId, $currentPackageSub, $updateSubscription)
    {
        $packageID = $data['package'] ?? $package->id;
        $addDays = (isset($data['package']) && $data['package'] == self::$dynamicTrial) ? 15 : ((isset($data['package']) && $data['package'] == self::$dynamicDevPlan) ? 1825 : 30);
        $currentPackageSub = PackageSubscription::find($currentPackageSub->id);

        if ($updateSubscription == self::$updateFullSubscription) {
            $currentPackageSub->update([
                'store_id' => $data['store_id'],
                'package_id' => $packageID,
                'payment_method_id' => ($packageID != self::$dynamicTrial && $packageID != self::$dynamicDevPlan) ? $paymentMethod : null,
                'status' => 1,
                'subscription_time' => now(),
                'update_time' => now(),
                'expiry_time' => Carbon::now()->addDays($addDays),
                'total_count' => 0,
                'stripe_charge_id' => $chargeId,
                'charge_cost' => $package->cost,
            ]);
        }

        if ((!isset($data['package'])) &&
            ($updateSubscription == self::$updateToBeChargeonly || $updateSubscription == self::$updateFullSubscription)) {
            PackageToBeCharge::where('subscription_id', $currentPackageSub->id)->update([
                'package_id' => $packageID,
                'status' => ($packageID != self::$dynamicTrial && $packageID != self::$disableAddon && $packageID != self::$dynamicDevPlan ) ? 1 : 0,
                'requested_date' => now(),
            ]);
        }
        //Customer wants to disable auto-renewal
        if (isset($data['package']) && $data['package'] == self::$disableAddon && $updateSubscription == self::$updateToBeChargeonly) {
            PackageToBeCharge::where('subscription_id', $currentPackageSub->id)->update([
                'status' => ($packageID != self::$dynamicTrial && $packageID != self::$disableAddon && $packageID != self::$dynamicDevPlan ) ? 1 : 0,
                'requested_date' => now(),
            ]);
        }

        if (isset($data['package']) && $data['package'] != self::$disableAddon &&
            ($updateSubscription == self::$updateToBeChargeonly || $updateSubscription == self::$updateFullSubscription)) {

            PackageToBeCharge::where('subscription_id', $currentPackageSub->id)->update([
                'package_id' => $data['package'],
                'status' => ($packageID != self::$dynamicTrial && $packageID != self::$disableAddon && $packageID != self::$dynamicDevPlan ) ? 1 : 0,
                'requested_date' => now(),
            ]);
        }
    }
    //***********************************
    // This method saving Package subscription detail and Package to be charge in database
    //***********************************
    public function createPackageSubscriptionInDB($data, $package, $paymentMethod, $chargeId)
    {
        $addDays = (isset($data['package']) && $data['package'] == self::$dynamicTrial) ? 15 : ((isset($data['package']) && $data['package'] == self::$dynamicDevPlan) ? 1825 : 30);
        $packageSub = PackageSubscription::create([
            'store_id' => $data['store_id'],
            'package_id' => $data['package'],
            'payment_method_id' => ($data['package'] != self::$dynamicTrial && $data['package'] != self::$dynamicDevPlan) ? $paymentMethod : null,
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
            'status' => ($data['package'] != self::$dynamicTrial && $data['package'] != self::$dynamicDevPlan) ? 1 : 0,
            'requested_date' => now(),
        ]);
    }
    //***********************************
    // This method creating charge on stripe
    //***********************************
    public function createStripeChargeForPackage($package, $mainSubscription, $addonType)
    {
        $stripeCustomerId = $mainSubscription->stripe_customer_id;
        try {
            $packageCost = bcmul($package->cost, 100);
        } catch (\Exception|\Throwable $exception) {
            $packageCost = $package->cost * 100;

        }
        try {
            $chargeData = [
                'amount' => $packageCost,
                'currency' => 'usd',
                'customer' => $stripeCustomerId,
                "description" => 'Real-time Shipping Quotes (BigCommerce ' . $addonType . ') Charge',
                'source' => $mainSubscription->payment_method
            ];
            $charge = Charge::create($chargeData);
            if (isset($charge['error']) && $charge['error']) {
                return $charge;
            }
            //Saving charge details to display in payments tab
            SubscriptionStripePayments::addOrUpdateAddonsPayment($package, $charge, $stripeCustomerId);
            $response = [
                'chargeId' => $charge->id
            ];
        } catch (\Exception $exception) {
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
    public function consumeHits($request)
    {
        self::$storeId = $data['store_id'] = $request['store_id'];
        $data['hits'] = $request['hits'];

        $addonType = $request['addon_type'];
        if ($addonType == self::$addonTypeSBS) {
            self::$dynamicTrial = 1;
            self::$dynamicDevPlan = self::$SBSPkgDynamicDev;
            $responce = $this->consumeAddonHits($data, $addonType);
        } elseif ($addonType == self::$addonTypeRAD) {
            self::$dynamicTrial = 7;
            self::$dynamicDevPlan = self::$RadPkgDynamicDev;
            $responce = $this->consumeAddonHits($data, $addonType);
        } elseif ($addonType == self::$addonTypePLT) {
            self::$dynamicTrial = self::$palletPkgDynamicTrial;
            self::$dynamicDevPlan = self::$palletPkgDynamicDev;
            $responce = $this->consumeAddonHits($data, $addonType);
        } else {
            $responce = [
                'error' => true,
                'message' => 'Add-on type is missing.',
                'data' => $request->all(),
            ];
        }
        return $responce;
    }
    //***********************************
    // This method handling different scnarios and end purpose is to consume required number of hits
    //***********************************
    public function consumeAddonHits($data, $addonType)
    {
        $updateSubscription = 0;
        $previousPkgRemainingHits = 0;
        $histToBeConsumed = $data['hits'];
        $currentPackageSub = DB::table('package_subscriptions as ps')
            ->leftjoin('package_sub_to_be_charge as pstbc', 'pstbc.subscription_id', '=', 'ps.id')
            ->leftjoin('packages as p', 'ps.package_id', '=', 'p.id')
            ->select('ps.id', 'ps.package_id as package_id', 'ps.expiry_time', 'ps.status', 'ps.created_at', 'ps.total_count as consumed_hits', 'p.htis as total_hits', 'pstbc.status as package_to_to_charge_status', 'pstbc.package_id as to_be_charge_package_id')
            ->where('store_id', self::$storeId)->where('p.addon_type', $addonType)->latest()->first();
        if (empty($currentPackageSub) || ($currentPackageSub->status == 0 || $currentPackageSub->status == 3)) { //If the package subscription is expired || suspended
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
        if (!is_null($currentPackageSub) && $currentPackageSub->status == 1 && (($currentPackageSub->consumed_hits + $histToBeConsumed) <= $currentPackageSub->total_hits) && Carbon::parse($currentPackageSub->expiry_time) > Carbon::now()) {
            //$packageSub = PackageSubscription::where('store_id',self::$storeId)->latest()->first();
            $packageSub = PackageSubscription::leftJoin('packages as p', 'package_subscriptions.package_id', '=', 'p.id')
                ->where('store_id', self::$storeId)->where('addon_type', $addonType)
                ->select('package_subscriptions.id', 'package_subscriptions.created_at', 'package_subscriptions.package_id', 'package_subscriptions.payment_method_id', 'package_subscriptions.status', 'package_subscriptions.subscription_time', 'package_subscriptions.update_time', 'package_subscriptions.expiry_time', 'package_subscriptions.total_count', 'package_subscriptions.stripe_charge_id', 'package_subscriptions.charge_cost')
                ->latest()->first();

            $packageSub->increment('total_count', $histToBeConsumed);
            $packageSub->update([
                'update_time' => Carbon::now()
            ]);
            return [
                'status' => true,
            ];
        } elseif (!is_null($currentPackageSub) && ($currentPackageSub->package_to_to_charge_status == 1 && Carbon::parse($currentPackageSub->expiry_time) < Carbon::now())) {
            //If current subscription expired
            $updateSubscription = self::$updateFullSubscription;
        } elseif (!is_null($currentPackageSub) && ($currentPackageSub->package_to_to_charge_status == 1 && ($currentPackageSub->consumed_hits + $histToBeConsumed) > $currentPackageSub->total_hits)) {
            //If current subscription hits becomes less to use

            $updateSubscription = self::$updateFullSubscription;
            $previousPkgRemainingHits = $currentPackageSub->total_hits - $currentPackageSub->consumed_hits;
        } elseif (!is_null($currentPackageSub) && ($currentPackageSub->package_to_to_charge_status == 0 && ($currentPackageSub->consumed_hits + $histToBeConsumed) > $currentPackageSub->total_hits)) {
            //If current subscription hits becomes less to use and next occaurance is disabled
            return [
                'status' => false,
            ];
        }


        if (!is_null($currentPackageSub) && $currentPackageSub->status == 1 && (($currentPackageSub->consumed_hits + $histToBeConsumed) >= $currentPackageSub->total_hits)) {
            //$packageSub = PackageSubscription::where('store_id',self::$storeId)->latest()->first();
            $packageSub = PackageSubscription::leftJoin('packages as p', 'package_subscriptions.package_id', '=', 'p.id')
                ->where('store_id', self::$storeId)->where('addon_type', $addonType)
                ->select('package_subscriptions.id', 'package_subscriptions.created_at', 'package_subscriptions.package_id', 'package_subscriptions.payment_method_id', 'package_subscriptions.status', 'package_subscriptions.subscription_time', 'package_subscriptions.update_time', 'package_subscriptions.expiry_time', 'package_subscriptions.total_count', 'package_subscriptions.stripe_charge_id', 'package_subscriptions.charge_cost')
                ->latest()->first();
            //If the trials Hits has consumed then Update the package subscription status to zero
            if ($currentPackageSub->package_id == self::$dynamicTrial || $currentPackageSub->package_id == self::$dynamicDevPlan) {
                $packageSub->update([
                    'status' => 0
                ]);
                if ($updateSubscription == 0) {
                    return [
                        'status' => false,
                    ];
                }
            }
        }
        //Get the main subscription to get the stripe customer ID and Payment method
        $mainSubscription = DB::table('subscriptions as s')
            ->leftJoin('payment_methods as p', 'p.store_id', '=', 's.store_id')
            ->select('s.stripe_id as stripe_customer_id', 's.payment_method', 's.email', 's.plan_id', 's.created_at', 'p.id as payment_method_id')
            ->where('s.store_id', self::$storeId)->latest()->first();

        $paymentMethod = isset($mainSubscription->payment_method_id) ? $mainSubscription->payment_method_id : null;

        $package = Package::find($currentPackageSub->to_be_charge_package_id);

        if (($currentPackageSub->package_id != self::$dynamicTrial && $currentPackageSub->package_id != self::$dynamicDevPlan) || $updateSubscription == self::$updateFullSubscription) {
            $chargeResponse = $this->createStripeChargeForPackage($package, $mainSubscription, $addonType);
        }
        if (!empty($chargeResponse['error']) && $chargeResponse['error'] == true) {
            // If charge is not successfull then update status = 2 to identify (Charge failed)
            $packageSub = PackageSubscription::where('store_id', self::$storeId)->latest()->first();
            //If the trials Hits has consumed then Update the package subscription status to zero
            $packageSub->update([
                'status' => 0
            ]);
            return [
                'status' => false,
            ];
            //return $chargeResponse;
        } else {
            $chargeId = isset($chargeResponse['data']['chargeId']) ? $chargeResponse['data']['chargeId'] : null;
        }
        if ($updateSubscription == self::$updateToBeChargeonly || $updateSubscription == self::$updateFullSubscription) {
            //Updating the current package Subscription in database
            $this->updatePackageSubscriptionInDB($data, $package, $paymentMethod, $chargeId, $currentPackageSub, $updateSubscription);
        }

        $histToBeConsumed = $histToBeConsumed - $previousPkgRemainingHits;
        //Consuming Hits after recharge
        //$packageSub = PackageSubscription::where('store_id',self::$storeId)->latest()->first();
        $packageSub = PackageSubscription::leftJoin('packages as p', 'package_subscriptions.package_id', '=', 'p.id')
            ->select('package_subscriptions.id', 'package_subscriptions.created_at', 'package_subscriptions.package_id', 'package_subscriptions.update_time', 'package_subscriptions.total_count')
            ->where('store_id', self::$storeId)->where('addon_type', $addonType)
            ->latest()->first();
        $packageSub->increment('total_count', $histToBeConsumed);
        $packageSub->update([
            'update_time' => Carbon::now()
        ]);
        //Get the current package details after updating the package subscription
        $currentPackageDetails = $this->getPkgDetails($addonType);
        if ($updateSubscription == self::$updateFullSubscription && !empty($mainSubscription->email)) {
            Mail::to($mainSubscription->email)->send(new AddonPackageUpdateMail($addonType, $currentPackageDetails['currentPackage']));
        }
        return [
            'status' => true,
        ];
    }
    //***********************************
    // This method returning the complete details to show on Frontend via route
    //***********************************
    public function getAddonPackageDetails(Request $request)
    {
        self::$storeId = $request['store_id'];
        $addonType = $request['addon_type'];

        if ($addonType == self::$addonTypeSBS) {
            self::$dynamicTrial = 1;
            self::$dynamicDevPlan = self::$SBSPkgDynamicDev;
        } elseif ($addonType == self::$addonTypeRAD) {
            self::$dynamicTrial = 7;
            self::$dynamicDevPlan = self::$RadPkgDynamicDev;
        }
        $currentPackageSub = $this->getPkgDetails($addonType);
        if (is_null($currentPackageSub)) {
            return response()->json([
                'error' => false,
                'data' => ['status' => 0],
                'message' => 'No current subscribed ' . $addonType . ' add-on is available'
            ]);
        } else {
            return response()->json([
                'error' => false,
                'data' => $currentPackageSub,
                'message' => $addonType . ' add-on subscription details found'
            ]);
        }
    }

    //***********************************
    // This method is used to decide to suspend the SBS or RAD Addon
    //***********************************
    public function suspendAddonUse(Request $request)
    {
        self::$storeId = $data['store_id'] = $request['store_id'];
        $data['suspend'] = $request['suspend'];
        $addonType = $request['addon_type'];
        if ($addonType == self::$addonTypeSBS) {
            self::$dynamicTrial = 1;
            self::$dynamicDevPlan = self::$SBSPkgDynamicDev;
            $responce = $this->suspendUsage($data, $addonType);
            $responce['data']['binPackMode'] = $this->getBinMode($request);
        } elseif ($addonType == self::$addonTypeRAD) {
            self::$dynamicTrial = 7;
            self::$dynamicDevPlan = self::$RadPkgDynamicDev;
            $responce = $this->suspendUsage($data, $addonType);
        } elseif ($addonType == self::$addonTypePLT) {
            self::$dynamicTrial = self::$palletPkgDynamicTrial;
            self::$dynamicDevPlan = self::$palletPkgDynamicDev;
            $responce = $this->suspendUsage($data, $addonType);
        } else {
            $responce = [
                'error' => true,
                'message' => 'Add-on type is missing.',
                'data' => $request->all(),
            ];
        }
        return response()->json($responce);
    }
    //***********************************
    // This method is updating the suspend or un-suspend status in DB
    //***********************************
    public function suspendUsage($data, $addonType)
    {
        //For Suspend = 3
        //For No Suspend = 1
        $currentPackageSub = PackageSubscription::leftJoin('packages as p', 'package_subscriptions.package_id', '=', 'p.id')
            ->where('store_id', self::$storeId)->where('addon_type', $addonType)
            ->select('package_subscriptions.id', 'package_subscriptions.created_at', 'package_subscriptions.package_id', 'package_subscriptions.payment_method_id', 'package_subscriptions.status', 'package_subscriptions.subscription_time', 'package_subscriptions.update_time', 'package_subscriptions.expiry_time', 'package_subscriptions.total_count', 'package_subscriptions.stripe_charge_id', 'package_subscriptions.charge_cost')
            ->latest()->first();
        $currentPackageSub->status = $data['suspend'];
        $currentPackageSub->save();
        $addonName = $addonType === self::$addonTypeSBS ? 'Standard Box Sizes' : ($addonType === self::$addonTypePLT ? 'Pallet Packaging' : 'Residential Address Detection');
        return [
            'error' => false,
            'data' => $this->getPkgDetails($addonType),
            'message' => ($data['suspend'] == 3) ? 'The ' . $addonName . ' add-on has been suspended' : 'The ' . $addonName . ' add-on has been reactivated',
        ];
    }

    public function binsPackagingMode(Request $request)
    {

        $installedAddonId = Functions::getSBSInstalledAddon($request);
        if (empty($installedAddonId)) {
            return [
                "error" => true,
                "data" => $installedAddonId,
                'message' => "Add-on Id is missing.",
            ];
        }

        $binsPackMode = isset($request->bin_pack_mode) && !empty($request->bin_pack_mode) ? $request->bin_pack_mode : 0;

        $installed_addon_settings = AddonSettings::firstOrNew(['installed_addon_id' => $installedAddonId]);

        $installed_addon_settings->bins_pack_mode = $binsPackMode;
        $installed_addon_settings->installed_addon_id = $installedAddonId;
        $installed_addon_settings->save();

        return [
            "error" => false,
            "data" => $installed_addon_settings->bins_pack_mode,
            "message" => "Box Packaging Mode has been updated.",
        ];

    }

    public function getBinMode($request)
    {
        $installedAddonId = Functions::getSBSInstalledAddon($request);
        if (empty($installedAddonId)) {
            return [
                "error" => true,
                "data" => $installedAddonId,
                'message' => "Add-on Id is missing.",
            ];
        }
        $getSBSAddonSettings = AddonSettings::where('installed_addon_id', $installedAddonId)->first();

        return isset($getSBSAddonSettings->bins_pack_mode) ? $getSBSAddonSettings->bins_pack_mode : 0;
    }

}
