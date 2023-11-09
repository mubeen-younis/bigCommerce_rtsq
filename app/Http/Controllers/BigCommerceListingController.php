<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Subscription\Plan;
use App\Models\Subscription\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Helpers\Helpers;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Subscription\SubscriptionController;
use App\Models\InstalledCarrier;
use App\Models\Subscription\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Models\Subscription\CarrierCount;
use App\Mail\PaymentFailedByWebHookEmail;
use Stripe\Charge;
use Stripe\Stripe;
use Carbon\Carbon;


class BigCommerceListingController extends Controller
{

    public static $plansData = [];
    public static $email = '';
    public static $trial = 1;
    private $storeId = null;

    /**
     * List BigCOmmerce Stores
     * @param Request $request
     * @return JsonResponse
     */
    public function listCustomers(Request $request)
    {
        Log::info("List Customer" . json_encode($request->all()));
        $limit = $request->limit ?? 10;
        $search = $request->search ?? null;
        $customerListing = Store::getStoreListing($limit, $search);
        
        return Helpers::toSendJsonResponse(true, '', $customerListing);
    }

    /**
     * Edit customer licenses
     * @param Request $request
     * @return JsonResponse
     */
    public function editBigCommerceSubscription(Request $request)
    {
        $uuid = $request->uuid;
        if (blank($uuid)) {
            return Helpers::toSendJsonResponse(false, 'No product id or uuid.');
        }

        return Helpers::toSendJsonResponse(true, '', Subscription::getSubscriptionDetails($uuid));
    }

    /**
     * Updates customer information
     * @param Request $request
     * @return JsonResponse
     */
    public function updateBCSubscription(Request $request)
    { 
        Log::info('update BC Subscription request ' . json_encode($request->all()));
        try { 
            $uuid = isset($request->uuid) ? $request->uuid : null;
            if (blank($uuid)) {
                return Helpers::toSendJsonResponse(false, 'Invalid Request.');
            }

            $currentSubscriptionDetail = $this->subscriptionDetailFromDB($uuid);
            $currentPlan = isset($currentSubscriptionDetail->plan_id) ? $currentSubscriptionDetail->plan_id : null;
            $data['plan'] = isset($request['plan_id']) ? $request['plan_id'] : null;
            self::$email = $data['email'] = isset($request['email']) ? $request['email'] : null;
            $isSamePlan = $currentPlan == $data['plan'];
            $inputDate = Carbon::parse($request->ends_at);
            $currentDate = Carbon::now();
            $isfuture = $inputDate->isFuture();
            $isfuture ? $status = 1 : $status = $currentSubscriptionDetail->status;

            //Added check: if plan is same but update ends date
            if ($isSamePlan) {
                $storeDetail = Store::where('id', $this->storeId)->first();
                if (!blank($storeDetail)) {
                        
                    Subscription::where('id', $uuid)->update([
                        'ends_at' => date('Y-m-d', strtotime($request->ends_at)),
                        'status' => $status,
                    ]);

                    if($data['plan'] == 1){
                        Store::where('id', $this->storeId)->update([
                            'is_trial_completed' => 0
                        ]);
                    }

                    return Helpers::toSendJsonResponse(true, 'Your subscription is updated successfully at the end of the period on ' . $request->ends_at . '.');
                }
            }
            //END:Check
            //Check: If current carriers installed are more than the choosed plan then return with message            
            $store = Store::getStoreDetailsFromStoreId($this->storeId);
            $isTestStore = Helpers::checkIsTestStore($store['hash']);
            Helpers::setStripeAPiKey($isTestStore);
            self::getPlansDetails($data['plan'], $isTestStore);   //Getting Plan detail from DB
            $newPlanAllowedCarriers = self::$plansData['carrier_count'];

            
            if (!is_null($currentSubscriptionDetail) && $currentSubscriptionDetail->total_installed_carriers > $newPlanAllowedCarriers) {
                return Helpers::toSendJsonResponse(false, 'You enabled more carriers than the allowed carriers limit (' . self::$plansData['carrier_count'] . ') in ' . self::$plansData['name'] . ' Plan. So, you need to disabled some carriers to downgrade your subscription plan.');
            }

            $stripePlanId = self::$plansData['stripe_plan_id'];

            //If the payment method already exists then retrieve it
            $paymentMethod = PaymentMethod::where('store_id', $this->storeId)->first();
            $paymentMethodId = isset($paymentMethod->id) ? $paymentMethod->id : null;
            //If there is already a subscription exists for the store_id then retrieve it
            $oldSubscription = Subscription::where('store_id', $this->storeId)->latest()->first();

            //Start: Upgrade or DownGrade Plans
            if (!is_null($paymentMethod) && !is_null($oldSubscription) && $stripePlanId != null && $oldSubscription->subscription_id != null) {

                if ($oldSubscription->status == 2) { //If the previous subscription is expired
                    // TODO: We can remove previous subscription from here
                    $updateSubResponse = $this->createnewSubscriptionPlan($oldSubscription->stripe_id, $stripePlanId);

                } else { //If the previous subscription is active
                    $updateSubResponse = $this->updateSubscriptionPlan($oldSubscription->subscription_id, $stripePlanId);
                }

                if ($updateSubResponse['status'] == false) {
                    return response()->json($updateSubResponse);
                }


                $uuid = $this->updateSubscriptionInDB($updateSubResponse['data'], $oldSubscription, $isTestStore, $status);
                //Getting Current Plan Detail
                $updateSubResponse['data'] = $this->subscriptionDetailFromDB($uuid);

                $mailToSend = isset($data['email']) && !empty($data['email']) ? $data['email'] : (isset($oldSubscription->email) && !empty($oldSubscription->email) ? $oldSubscription->email : null);
                if (!empty($mailToSend)) {
                    $emailData = array(
                        'receiverEmail' => $mailToSend,
                        'productName' => 'Real-time Shipping Quotes',
                        'planName' => self::$plansData['name'],
                        'endsAt' => $updateSubResponse['data']->ends_at ?? null,
                        'action' => 'IPF'       // Invoice Payment Failed
                    );
                    Mail::to($emailData['receiverEmail'])->send(new PaymentFailedByWebHookEmail($emailData, 1));
                }
                return response()->json($updateSubResponse, 200);
            }
            return Helpers::toSendJsonResponse(false, 'Your subscription is not updated, Please verify again.');
        } catch (\Exception $exception) {
            Log::info('Exception on subscribing plan ' . json_encode($exception->getTraceAsString()));
            return Helpers::toSendJsonResponse(false, 'Something went wrong on subscribing plan.');
        }
    }

    /**
     * cancel customer Subscription
     * @param Request $request
     * @return JsonResponse
     */
    public function cancelBCSubscription(Request $request)
    {
        $Subscription = new SubscriptionController();
        $uuid = $request->uuid ?? null;
        if (blank($uuid)) {
            return Helpers::toSendJsonResponse(false, 'Invalid Request.');
        }

        $dbSub = Subscription::where('id', $uuid)->latest()->first();

        if(!empty($dbSub)){
            $store = Store::getStoreDetailsFromStoreId($dbSub->store_id);
            $isTestStore = Helpers::checkIsTestStore($store['hash']);
            Helpers::setStripeAPiKey($isTestStore);

            if (isset($request['flag']) && $request['flag'] == 1) {
                $res = $this->cencelStripeSubscription($dbSub->subscription_id);
                Log::info("Cancel Stripe Subscription" . json_encode($res));
                if (isset($res['status']) && $res['status'] == true) {
                    //Because of simaltaneous execution of stripe and DB
                    Subscription::where('id', $dbSub->id)->update([
                     'status' => 2
                    ]);
                }
            } else {
                $subId = $dbSub->subscription_id;
                $planId = (int)$dbSub->plan_id;
                $plan = Plan::find($planId);
                $stripePlanId = $isTestStore ? $plan->stripe_sandbox_plan_id : $plan->stripe_plan_id;
                $res = $this->reActivateSubscriptionPlan($subId, $stripePlanId);
                Log::info("Reactivate Stripe Subscription Plan" . json_encode($res));
                if (isset($res['status']) && $res['status'] == true) {
                    //Because of simaltaneous execution of stripe and DB
                    Subscription::where('id', $dbSub->id)->update([
                        'status' => 1
                    ]);
                }
            }
        }

        
        $subscriptionDetail = $this->subscriptionDetailFromDB($uuid);
        $res['data'] = $subscriptionDetail;


        return Helpers::toSendJsonResponse($res['status'], $res['message'], $res['data']);
    }

    private function cencelStripeSubscription($subscriptionId)
    {
        try {

            $responce = \Stripe\Subscription::update(
                $subscriptionId, [
                    'cancel_at_period_end' => true,
                ]
            );
            //array('at_period_end' => true)
            $ends_at = gmdate("M-d-Y", $responce->cancel_at);

            $responce = [
                'status' => true,
                'message' => 'Your subscription will be cancelled automatically at the end of the period on ' . $ends_at . '.',
                'data' => $responce,
            ];

            return $responce;
        } catch (\Exception $e) {
            $responce = [
                'status' => false,
                'data' => [],
                'message' => $e->getMessage()
            ];
        }
        return $responce;
    }

    private function reActivateSubscriptionPlan($subId, $planId)
    {

        try {
            $subscription = \Stripe\Subscription::retrieve($subId);
            $subscription->plan = $planId;
            $subscription->cancel_at_period_end = false;
            $subscriptionRes = $subscription->save();
            $responce = [
                'status' => true,
                'data' => $subscriptionRes,
                'message' => 'The subscription reactivated successfully.'
            ];
        } catch (\Exception $e) {
            $responce = [
                'status' => false,
                'data' => [],
                'message' => $e->getMessage()
            ];
        }
        return $responce;
    }

    public function subscriptionDetailFromDB($id)
    {
        $data = DB::table('subscriptions as s')
            ->leftJoin('carriers_counts as cc', 'cc.store_id', '=', 's.store_id')
            ->leftJoin('plans as pl', 'pl.id', '=', 's.plan_id')
            ->leftJoin('payment_methods as pm', 'pm.store_id', '=', 's.store_id')
            ->select('s.id as subscription_id', 's.store_id', 's.status', 's.ends_at', 's.plan_id', 's.created_at', 'cc.carrier_counts as total_remaining_carriers', 's.amount_charged', 'pl.name', 'pl.carrier_count as total_allowed_carriers', 'pm.last4', 'pm.is_default as is_default_payment_method')
            ->where('s.id', $id)->latest()->first();
        if (blank($data)) {
            return null;
        }
        try {
            $data->last4 = decrypt($data->last4);
        } catch (\Exception $exception) {
            $data->last4 = '****';
            Log::info('Card Decrypt Exception' . $exception->getMessage());
        }

        $this->storeId = isset($data->store_id) ? $data->store_id : null;

        // Added this block of code for the bug of carrier count issue
        // Bug of enabling carriers according to plan
        $totalEnabledCarriersCount = InstalledCarrier::where('store_id', $this->storeId)->where('is_enabled', 1)->count();
        $data->total_remaining_carriers = $data->total_allowed_carriers - $totalEnabledCarriersCount;
        ////////////////////////////
        if (!is_null($data)) {
            $data->total_installed_carriers = $data->total_allowed_carriers - $data->total_remaining_carriers;
            $data->ends_at = date('m/d/Y', strtotime($data->ends_at));
        }
        return $data;
    }

    //*************************************
    // This function is used to get the details of Plan to be subscribe
    //*************************************
    public static function getPlansDetails($plan = 1, $testStore = false)
    {
        $getDefaultFreePlan = Plan::find($plan);
        self::$plansData['plan_id'] = $getDefaultFreePlan->id;
        self::$plansData['stripe_plan_id'] = $testStore ? $getDefaultFreePlan->stripe_sandbox_plan_id : $getDefaultFreePlan->stripe_plan_id;
        self::$plansData['carrier_count'] = $getDefaultFreePlan->carrier_count;
        self::$plansData['cost'] = $getDefaultFreePlan->price;
        self::$plansData['name'] = $getDefaultFreePlan->name;
        self::$plansData['type'] = $getDefaultFreePlan->type;
    }

    //*************************************
    // This function is used to create new subscription in case of previous subscription is cancelled
    //*************************************
    public function createnewSubscriptionPlan($customerId, $planId)
    {
        try {
            $subscription = \Stripe\Subscription::create(array(
                'customer' => $customerId,
                'plan' => $planId
            ));
            $responce = [
                'status' => true,
                'message' => 'Plan is subscribed successfully.',
                'data' => $subscription,
            ];

        } catch (\Exception $e) {
            $responce = [
                'status' => false,
                'data' => [],
                'message' => $e->getMessage()
            ];
        }
        return $responce;
    }

    //*************************************
    // This function is used to retrieve and update the plan for a subscription
    //*************************************
    public function updateSubscriptionPlan($subId, $planId)
    {
        try {
            $subscription = \Stripe\Subscription::retrieve($subId);
            $subscription->plan = $planId;
            $subscription->proration_behavior = 'always_invoice';
            $subResponce = $subscription->save();

            $responce = [
                'status' => true,
                'message' => 'Plan is updated successfully.',
                'data' => $subResponce,
            ];
        } catch (\Exception $e) {
            $responce = [
                'status' => false,
                'data' => [],
                'message' => $e->getMessage()
            ];
        }
        return $responce;
    }

    //*************************************
    // This function is used to create or update the subscription in DB when the plan is upgraded or downgraded from stripe
    //*************************************
    public function updateSubscriptionInDB($subscriptionReponse, $oldSubscription, $testStore = false, $status)
    {
        Log::info('Is Test Store on adding plan to DB ' . $testStore . json_encode($subscriptionReponse));
        if (isset($oldSubscription->status) && $oldSubscription->status == 2) {
            $subscription = [
                'store_id' => $oldSubscription->store_id,
                'paymentMethod_id' => $oldSubscription->paymentMethod_id,
                'name' => $oldSubscription->name ?? '',
                'email' => self::$email ?? null,
                'stripe_id' => $oldSubscription->stripe_id ?? '',
                'is_test_subscription' => $testStore,
                'subscription_id' => $subscriptionReponse->id ?? '',
                'quantity' => $subscriptionReponse->quantity ?? '',
                'plan_id' => self::$plansData['plan_id'] ?? self::$trial,
                'payment_method' => $oldSubscription->payment_method ?? null,
                'status' => $status, //Active Subscription
                'trial_ends_at' => null,
                'charge_object' => json_encode($subscriptionReponse),
                'ends_at' => gmdate("Y-m-d\TH:i:s\Z", $subscriptionReponse->current_period_end),
                'amount_charged' => self::$plansData['cost'] ?? 0
            ];
            $newSubscription = Subscription::create($subscription);
            $this->updateCarrierCountsinDB($newSubscription->id, $oldSubscription->store_id);
            return $newSubscription->id;
        }
        $carrierCounts = CarrierCount::where('plan_id', $oldSubscription->plan_id)->where('subscription_id', $oldSubscription->id)->first();
        $oldSubscription->plan_id = self::$plansData['plan_id'];
        $oldSubscription->status = $status; //Active Status
        $oldSubscription->ends_at = gmdate("Y-m-d\TH:i:s\Z", $subscriptionReponse->current_period_end);
        $oldSubscription->charge_object = json_encode($subscriptionReponse);
        $oldSubscription->amount_charged = self::$plansData['cost'];
        /*Added for TEst subscription functionality*/
        $oldSubscription->is_test_subscription = $testStore;
        $oldSubscription->update();

        //Get: Previous Plan Allowed Carrier Limit
        $previousPlan = Plan::find($carrierCounts->plan_id);
        $installedCarrier = $previousPlan->carrier_count - $carrierCounts->carrier_counts; //10-7
        //$installedCarrier is the current installed carriers
        $carrierCounts->plan_id = self::$plansData['plan_id'];
        $carrierCounts->subscription_id = $oldSubscription->id;
        $carrierCounts->carrier_counts = self::$plansData['carrier_count'] - $installedCarrier; //Sustain the current install value
        $carrierCounts->update();
        return $oldSubscription->id;
    }

    //*************************************
    // This function is used to create or update the carrier counts for new subscription and change subscription
    //*************************************
    public function updateCarrierCountsinDB($subscriptionId, $storeId)
    {
        if (CarrierCount::where('store_id', $storeId)->exists()) {
            $carrierCount = CarrierCount::where('store_id', $storeId)->first();
            //Get: Previous Plan Allowed Carrier Limit
            $previousPlan = Plan::find($carrierCount->plan_id);
            $installedCarrier = $previousPlan->carrier_count - $carrierCount->carrier_counts;
            //$installedCarrier is the current installed carriers
            $carrierCount->plan_id = self::$plansData['plan_id'];
            $carrierCount->subscription_id = $subscriptionId;
            $carrierCount->carrier_counts = self::$plansData['carrier_count'] - $installedCarrier; //Sustain the current install value
            $carrierCount->save();
        } else {
            CarrierCount::create([
                'store_id' => $storeId,
                'plan_id' => self::$plansData['plan_id'],
                'subscription_id' => $subscriptionId,
                'carrier_counts' => self::$plansData['carrier_count'],
            ]);
        }
    }
}
