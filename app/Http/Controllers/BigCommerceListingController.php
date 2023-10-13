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


class BigCommerceListingController extends Controller
{

    public static $plansData = [];
    public static $email = '';
    public static $trial = 1;

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
        
        return Helpers::sendJsonResponse(true, '', $customerListing);
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
            return Helpers::sendJsonResponse(false, 'No product id or uuid');
        }

        return Helpers::sendJsonResponse(true, '', Subscription::getSubscriptionDetails($uuid));
    }

    /**
     * Updates customer information
     * @param Request $request
     * @return JsonResponse
     */
    public function updateBCSubscription(Request $request)
    {
        try {
            $uuid = isset($request->uuid) ? $request->uuid : null;
            $storeId = isset($request->store_id) ? $request->store_id : null;
            $data['plan'] = isset($request['plan']) ? $request['plan'] : null;
            $status = isset($request->status) ? $request->status : 1;
            self::$email = $data['email'] = isset($request['email']) ? $request['email'] : null;
            if (blank($uuid)) {
                return Helpers::sendJsonResponse(false, 'Invalid Request');
            }

            $hubSpotController = new HubSpotController();
            //Check: If current carriers installed are more than the choosed plan then return with message
            $currentSubscriptionDetail = $this->subscriptionDetailFromDB($storeId, $uuid);
            $store = Store::getStoreDetailsFromStoreId($storeId);
            $isTestStore = Helpers::checkIsTestStore($store['hash']);
            Helpers::setStripeAPiKey($isTestStore);
            self::getPlansDetails($data['plan'], $isTestStore);   //Getting Plan detail from DB
            $newPlanAllowedCarriers = self::$plansData['carrier_count'];

            
            if (!is_null($currentSubscriptionDetail) && $currentSubscriptionDetail->total_installed_carriers > $newPlanAllowedCarriers) {
                return response()->json([
                    'error' => true,
                    'data' => [],
                    'message' => 'You enabled more carriers than the allowed carriers limit (' . self::$plansData['carrier_count'] . ') in ' . self::$plansData['name'] . ' Plan. So, you need to disabled some carriers to downgrade your subscription plan'
                ], 200);
            }
            /*Added check for trial plan
            if the store already taken trial plan*/
            if ($data['plan'] == self::$trial) {
                $storeDetail = Store::where('id', $storeId)->first();
                if (!blank($storeDetail)) {
                    if ($storeDetail->is_trial_completed) {
                        return response()->json([
                            'error' => true,
                            'data' => [],
                            'message' => 'You have already taken trial plan! Please subscribe to a paid plan if you want to continue using our services.'
                        ], 200);
                    }
                }
            }
            //END:Check

            $stripePlanId = self::$plansData['stripe_plan_id'];

            //If the payment method already exists then retrieve it
            $paymentMethod = PaymentMethod::where('store_id', $storeId)->first();
            $paymentMethodId = isset($paymentMethod->id) ? $paymentMethod->id : null;
            //If there is already a subscription exists for the store_id then retrieve it
            $oldSubscription = Subscription::where('store_id', $storeId)->latest()->first();

            //Start: Upgrade or DownGrade Plans
            if (!is_null($paymentMethod) && !is_null($oldSubscription) && $stripePlanId != null && $oldSubscription->subscription_id != null) {
                $oldPaymentMethod = PaymentMethod::where('store_id', $storeId)->first();

                if ($oldSubscription->status == 2) { //If the previous subscription is expired
                    // TODO: We can remove previous subscription from here
                    $updateSubResponse = $this->createnewSubscriptionPlan($oldSubscription->stripe_id, $stripePlanId);

                } else { //If the previous subscription is active
                    $updateSubResponse = $this->updateSubscriptionPlan($oldSubscription->subscription_id, $stripePlanId);
                }

                if ($updateSubResponse['error'] == true) {
                    return response()->json($updateSubResponse);
                }


                $uuid = $this->updateSubscriptionInDB($updateSubResponse['data'], $oldSubscription, $isTestStore, $status);
                //Getting Current Plan Detail
                $updateSubResponse['data'] = $this->subscriptionDetailFromDB($storeId, $uuid);
                Log::info('Email of old subscription' . $oldSubscription->email);
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
            return response()->json([
                'error' => true,
                'message' => 'Something went wrong.'
            ], 200);
            dd(1);
            //END: Upgrade or DownGrade Plans
            //If stripeplan (null) means, it is trial.
            if ($stripePlanId != null) {
                $customerResponse = $this->createCustomerOnStripe($data);
            }
            // if stripe customer is not created successfully then return the error
            if (isset($customerResponse['error']) && $customerResponse['error'] == true) {
                return response()->json($customerResponse);
            }
            //If the stripe customer is created and subscription is done
            $customerId = isset($customerResponse['data']->id) ? $customerResponse['data']->id : null;
            $subscriptions = isset($customerResponse['data']->subscriptions) ? $customerResponse['data']->subscriptions : null;
            //If the stripe customer is created and plan is subscribed successfully then it means the PAID plan is subscribed.
            //else, otherwise we consider it to be a trial
            if (!is_null($customerId) && !is_null($subscriptions)) {
                $paymentMethodId = $this->savePaymentMethodInDB($customerResponse['data'], $storeId);

                $user = [
                    'email' => $data['email'],
                    'firstname' => $request['card_name'] ?? '',
                    'lastname' => '',
                    'city' => $request['city'] ?? '',
                    'state' => $request['state'] ?? '',
                    'zip' => $request['zip'] ?? '',
                    'country' => $request['country'] ?? 'US',
                    'address' => $request['address'] ?? '',
                    'phone' => $request['phone'] ?? '',
                ];
                $status = ['products_purchased' => true];
                $hubSpotController->createUpdateHubSpotUser($storeId, $user, $status);

            } else {
                //Else part will be executed in case of trial and we need to update the subscription table for a trial
                /*This block of code will check if customer already subscribe trial plan
                and is allowed to subscribe trial plan*/
                $trialDays = Carbon::now()->addDays(14);
                $trialSubscription = Subscription::where('store_id', $storeId)->where('plan_id', self::$plansData['plan_id'])->first();
                if (!blank($trialSubscription)) {
                    $dbTrialEndDate = $trialSubscription->ends_at;
                    if (!blank($dbTrialEndDate)) {
                        if (Carbon::now() >= Carbon::parse($dbTrialEndDate)) {
                            return response()->json([
                                'error' => true,
                                'data' => [],
                                'message' => 'You have already taken trial plan! Please subscribe to a paid plan if you want to continue using our services.'
                            ], 200);
                        }
                        /*Setting remaining trial days for customer*/
                        $trialDays = Functions::getDaysBwDates(Carbon::now(), $dbTrialEndDate);
                    }
                }

                $subscription = new Subscription();
                $subscription->store_id = $storeId;
                $subscription->plan_id = self::$plansData['plan_id'];
                $subscription->name = isset($data['card_name']) ? $data['card_name'] : '';
                $subscription->email = self::$email;
                $subscription->status = 1; //Active Status
                $subscription->trial_ends_at = $trialDays;
                $subscription->ends_at = $trialDays;
                $subscription->save();

                /*
                 * Create Hub spot user and activate trial
                 */
                $user = ['email' => $data['email']];
                $status = ['product_trials' => true];

                $hubSpotController->createUpdateHubSpotUser($storeId, $user, $status);
            }
            //If the plan if subcribed successfully, then it must be a PAID Stripe plan
            //Else, it is trial and $subscriptionId will be null.
            if (!is_null($subscriptions)) {
                $subscriptionId = $this->saveSubscriptionInDB($customerResponse['data'], $subscriptions, $paymentMethodId, $storeId, $oldSubscription);
            } else {
                $subscriptionId = isset($subscription->id) ? $subscription->id : null;
            }
            //Updating: carrier counts that will be allowed in case of trial of PAID plan
            $this->updateCarrierCountsinDB($subscriptionId, $storeId);
            $subscriptionDetail = $this->subscriptionDetailFromDB($storeId, $uuid);
            $emailData = array(
                'receiverEmail' => $data['email'],
                'productName' => 'Real-time Shipping Quotes',
                'planName' => self::$plansData['name'],
                'endsAt' => $subscriptionDetail->ends_at,
                'action' => 'IPF'       // Invoice Payment Failed
            );
            if ($data['plan'] == self::$trial) { // if planId is null then it's a trial and we need to send an email for trial

                Mail::to($data['email'])->send(new PaymentFailedByWebHookEmail($emailData, 3));
            } else {
                Mail::to($data['email'])->send(new PaymentFailedByWebHookEmail($emailData, 1));

            }
            /*
            * Update WS graph data
            * */
            SaleGraphController::updateGraphData();

            return response()->json([
                'error' => false,
                'data' => $subscriptionDetail,
                'message' => 'The plan subscribed successfully.'
            ], 200);
        } catch (\Exception $exception) {
            Log::info('Exception on subscribing plan ' . json_encode($exception->getTraceAsString()));
            return response()->json([
                'error' => true,
                'data' => [],
                'message' => 'Something went wrong on subscribing plan.'
            ], 200);
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
            return Helpers::sendJsonResponse(false, 'Invalid Request');
        }

        $dbSub = Subscription::where('id', $uuid)->latest()->first();

        if(!empty($dbSub)){
            $store = Store::getStoreDetailsFromStoreId($dbSub->store_id);
            $isTestStore = Helpers::checkIsTestStore($store['hash']);
            Helpers::setStripeAPiKey($isTestStore);

            if (isset($request['cancel']) && $request['cancel'] == 1) {
                $res = $this->cencelStripeSubscription($dbSub->subscription_id);
                Log::info("Cencel Stripe Subscription" . json_encode($res));
                if (isset($res['error']) && $res['error'] == false) {
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
                if (isset($res['error']) && $res['error'] == false) {
                    //Because of simaltaneous execution of stripe and DB
                    Subscription::where('id', $dbSub->id)->update([
                        'status' => 1
                    ]);
                }
            }
        }

        
        $subscriptionDetail = $this->subscriptionDetailFromDB($dbSub->store_id, $uuid);
        $res['data'] = $subscriptionDetail;


        return Helpers::sendJsonResponse($res['error'], $res['message'], $res['data']);
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
                'error' => false,
                'message' => 'Your subscription will be cancelled automatically at the end of the period on ' . $ends_at . '.',
                'data' => $responce,
            ];

            return $responce;
        } catch (\Exception $e) {
            $responce = [
                'error' => true,
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
                'error' => false,
                'data' => $subscriptionRes,
                'message' => 'The subscription reactivated successfully.'
            ];
        } catch (\Exception $e) {
            $responce = [
                'error' => false,
                'data' => [],
                'message' => $e->getMessage()
            ];
        }
        return $responce;
    }

    public function subscriptionDetailFromDB($storeId, $id)
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
        // Added this block of code for the bug of carrier count issue
        // Bug of enabling carriers according to plan
        $totalEnabledCarriersCount = InstalledCarrier::where('store_id', $storeId)->where('is_enabled', 1)->count();
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
                'error' => false,
                'message' => 'Plan is subscribed successfully.',
                'data' => $subscription,
            ];

        } catch (\Exception $e) {
            $responce = [
                'error' => true,
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
                'error' => false,
                'message' => 'Plan is updated successfully.',
                'data' => $subResponce,
            ];
        } catch (\Exception $e) {
            $responce = [
                'error' => true,
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
