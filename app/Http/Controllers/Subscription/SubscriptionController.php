<?php

namespace App\Http\Controllers\Subscription;


use App\Http\Controllers\Controller;
use App\Mail\PaymentFailedByWebHookEmail;
use App\Models\Subscription\CarrierCount;
use App\Models\Subscription\PaymentMethod;
use App\Models\Subscription\Plan;
use App\Models\Subscription\Subscription;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Exception;
use Stripe\Stripe;

class SubscriptionController extends Controller
{
    public static $fromRegister = false;
    public static $plansArray = [];
    public static $isTrial = false;
    public static $chargeAmount = 0;
    public static $trial = 1;
    public static $plansData = [];
    public static $testUsers = [];
    public static $_parcelAndLtlCarries = ['WWE'];

    public function __construct()
    {
        Stripe::setApiKey(config('app.stripe_secret'));
        //self::getTrialPlans();
    }

    public function validateRequest($request)
    {
        $messages = [
            'card_number.required' => 'Card number is required.',
            'exp_date.required' => 'Expiry date is required.',
            'cvc.required' => 'CVC is required.',
            'zip.required' => 'Zip/Postal code is required.'
        ];
        $validator = Validator::make($request->all(), [
            'card_number' => 'required',
            'exp_date' => 'required',
            'cvc' => 'required',
            'zip' => 'required'
        ], $messages);
        return $validator;
    }

    /**************************************--New Code--****************************************** */

    //*************************************
    // This function is used to save the payment method in DB after creating a stripe customer
    //*************************************
    public function savePaymentMethodInDB($returnCustomer,$storeId){
        $fingerPrint = isset($returnCustomer->sources->data[0]->fingerprint) ? md5($returnCustomer->sources->data[0]->fingerprint) : null;
        $last4 = isset($returnCustomer->sources->data[0]->last4) ? encrypt($returnCustomer->sources->data[0]->last4) : null;
        $paymentMethod = $fingerPrint != null ? PaymentMethod::whereStoreId($storeId)->whereCardFingerPrint($fingerPrint)->first() : null;
        if (PaymentMethod::where('store_id',$storeId)->exists()){
            $paymentMethodId = PaymentMethod::where('store_id',$storeId)->update([
                'store_id' => $storeId,
                'stripe_customer_object' => isset($returnCustomer) ? encrypt(json_encode($returnCustomer)) : null,
                'stripe_id' => isset($returnCustomer->id) ? encrypt($returnCustomer->id) : null,
                'payment_method' => isset($returnCustomer->default_source) ? encrypt($returnCustomer->default_source) : null,
                'card_finger_print' => $fingerPrint,
                'last4' => $last4,
                'is_default' => 1
            ]);
            return $paymentMethodId;
        }else{
            $paymentMethod = PaymentMethod::create([
                'store_id' => $storeId,
                'stripe_customer_object' => isset($returnCustomer) ? encrypt(json_encode($returnCustomer)) : null,
                'stripe_id' => isset($returnCustomer->id) ? encrypt($returnCustomer->id) : null,
                'payment_method' => isset($returnCustomer->default_source) ? encrypt($returnCustomer->default_source) : null,
                'card_finger_print' => $fingerPrint,
                'last4' => $last4,
                'is_default' => 1
            ]);
        }
        return $paymentMethod->id;
    }

    //*************************************
    // This function is used to save the new subscription in DB or update the existing subscription when plan is upgraded or downgraded
    //*************************************
    public function saveSubscriptionInDB($customerResponse,$subscriptionReponse,$paymentMethodId,$storeId, $oldSubscription){

        $subscriptionReponse = isset($subscriptionReponse->data[0]) ? $subscriptionReponse->data[0] :$subscriptionReponse;
        $newSubscription = null;
        $subscription = [
            'store_id' => $storeId,
            'paymentMethod_id' => $paymentMethodId,
            'name' => $customerResponse->name ?? '',
            'stripe_id' => $customerResponse->id ?? '',
            'subscription_id' => $subscriptionReponse->id ?? '',
            'quantity' => $subscriptionReponse->quantity ?? '',
            'plan_id' => self::$plansData['plan_id'] ?? self::$trial,
            'payment_method' => $customerResponse->default_source ?? null,
            'status' => 1, //Active Subscription
            'trial_ends_at' => null,
            'charge_object' => json_encode($subscriptionReponse),
            'ends_at' => gmdate("Y-m-d\TH:i:s\Z", $subscriptionReponse->current_period_end),
            'amount_charged' => self::$plansData['cost'] ?? 0
        ];
        if ($oldSubscription == null){
            $newSubscription = Subscription::create($subscription);
        } else{
            $sub = Subscription::where('store_id',$storeId)->latest()->first();
            Subscription::where('id',$sub->id)->update($subscription);
            return $sub->id;
        }
        return $newSubscription->id;
    }
    //*************************************
    // This function is used to create or update the subscription in DB when the plan is upgraded or downgraded from stripe
    //*************************************
    public function updateSubscriptionInDB($subscriptionReponse,$oldSubscription){
        if (isset($oldSubscription->status) && $oldSubscription->status == 2){
            $subscription = [
                'store_id' => $oldSubscription->store_id,
                'paymentMethod_id' => $oldSubscription->paymentMethod_id,
                'name' => $oldSubscription->name ?? 'Trial User',
                'stripe_id' => $oldSubscription->stripe_id ?? '',
                'subscription_id' => $subscriptionReponse->id ?? '',
                'quantity' => $subscriptionReponse->quantity ?? '',
                'plan_id' => self::$plansData['plan_id'] ?? self::$trial,
                'payment_method' => $oldSubscription->payment_method ?? null,
                'status' => 1, //Active Subscription
                'trial_ends_at' => null,
                'charge_object' => json_encode($subscriptionReponse),
                'ends_at' => gmdate("Y-m-d\TH:i:s\Z", $subscriptionReponse->current_period_end),
                'amount_charged' => self::$plansData['cost'] ?? 0
            ];
            $newSubscription = Subscription::create($subscription);
            $this->updateCarrierCountsinDB($newSubscription->id, $oldSubscription->store_id);
            return $newSubscription->id;
        }
        $carrierCounts = CarrierCount::where('plan_id',$oldSubscription->plan_id)->where('subscription_id',$oldSubscription->id)->first();
        $oldSubscription->plan_id = self::$plansData['plan_id'];
        $oldSubscription->status = 1; //Active Status
        $oldSubscription->ends_at = gmdate("Y-m-d\TH:i:s\Z", $subscriptionReponse->current_period_end);
        $oldSubscription->charge_object = json_encode($subscriptionReponse);
        $oldSubscription->amount_charged = self::$plansData['cost'];
        $oldSubscription->update();

        $carrierCounts->plan_id = self::$plansData['plan_id'];
        $carrierCounts->subscription_id = $oldSubscription->id;
        $carrierCounts->carrier_counts = self::$plansData['carrier_count'];
        $carrierCounts->update();
        return $oldSubscription->id;
    }
    //*************************************
    // This function is used to create or update the carrier counts for new subscription and change subscription
    //*************************************
    public function updateCarrierCountsinDB($subscriptionId, $storeId){
        if (CarrierCount::where('store_id',$storeId)->exists()){
            CarrierCount::where('store_id',$storeId)->update([
                'plan_id' => self::$plansData['plan_id'],
                'subscription_id' => $subscriptionId,
                'carrier_counts' => self::$plansData['carrier_count'],
            ]);
        }else{
            CarrierCount::create([
                'store_id' => $storeId,
                'plan_id' => self::$plansData['plan_id'],
                'subscription_id' => $subscriptionId,
                'carrier_counts' => self::$plansData['carrier_count'],
            ]);
        }
    }

    //*************************************
    // This function is used to create new subscription in case of previous subscription is cancelled
    //*************************************
    public function createnewSubscriptionPlan($customerId, $planId) {
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
                'error'  => true,
                'data'  => [],
                'message'  => $e->getMessage()
            ];
        }
        return $responce;
    }

    //*************************************
    // This function is used to retrieve and update the plan for a subscription
    //*************************************
    public function updateSubscriptionPlan($subId, $planId) {
        try {
            $subscription = \Stripe\Subscription::retrieve($subId);
            $subscription->plan = $planId;
            $subscription->proration_behavior = 'always_invoice';
            $subResponce = $subscription->save();

            $responce = [
                'error' => false,
                'message' => 'Subscription updated successfully.',
                'data' => $subResponce,
            ];
        } catch (\Exception $e) {
            $responce = [
                'error'  => true,
                'data'  => [],
                'message'  => $e->getMessage()
            ];
        }
        return $responce;
    }
    //*************************************
    // This function is used from Stripe WebHook and update the subsription details in DB
    //*************************************
    public function updateSubscriptionFromStripe(Request $request){

        $json = file_get_contents('php://input', true);
        error_log('Subscription From Stripe Updated : '. $json);
        $json = json_decode($json);

        $subscription = isset($json->data->object) ? $json->data->object : json_encode([]);

        $data = [
            'stripe_id' => $subscription->customer,
            'subscription_id' => $subscription->id,
            'subscription' => $subscription
        ];
        $updateSubResponse = $data['subscription'];
        //If there is already a subscription exists for the store_id then retrieve it
        $oldSubscription = Subscription::where('subscription_id',$data['subscription_id'])->latest()->first();

        if (!is_null($oldSubscription)){
            $this->updateSubscriptionInDB($updateSubResponse,$oldSubscription);
            return json_encode($updateSubResponse);
        }else{
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Subscription not found to be update.',
            ]);
        }


    }
    //*************************************
    // This function is used to subscribe to Trial, Paid Plan, Updgrade or DownGrade plan
    //*************************************
    public function subscribeToPlan(Request $request){

        if ($request['plan'] != self::$trial){
            $data = [
                // 'card_number' => '4242424242424242',
                'card_number' => preg_replace("/\s+/", "", $request['card_number']),
                'exp_month' => $request['exp_month'],
                'exp_year' => $request['exp_year'],
                'cvc' => $request['cvc'],
                'card_name' => $request['card_name'],
                'email' => $request['email'],
                'address' => $request['address'],
                'city' => $request['city'],
                'state' => $request['state'],
                'zip' => $request['zip'],
                'country'=> $request['country'],
                'defaultpayment'=> $request['defaultpayment']
            ];
        }
        $data['store_id'] = $request['store_id'];
        $data['plan'] = $request['plan'];
        $data['defaultpayment'] = (isset($request['defaultpayment']) && $request['defaultpayment'] == true) ? true : false;
        self::getPlansDetails($data['plan']);   //Getting Plan detail from DB
        $planId = self::$plansData['stripe_plan_id'];

        // Intializing Billing info for the stripe customer
        $data = [
            'store_id' => isset($data['store_id']) ? $data['store_id'] : '',
            'cNumber' => isset($data['card_number']) ? $data['card_number'] : '',
            'cExpiryMonth' => isset($data['exp_month']) ? $data['exp_month'] : '',
            'cExpiryYear' => isset($data['exp_year']) ? $data['exp_year'] : '',
            'cCvc' => isset($data['cvc']) ? $data['cvc'] : '',
            'cName' => isset($data['card_name']) ? $data['card_name'] : '',
            'email' => isset($data['email']) ? $data['email'] : '',
            'cAddress_line1' => isset($data['address']) ? $data['address'] : '',
            'cAddress_city' => isset($data['city']) ? $data['city'] : '',
            'cAddress_state' => isset($data['state']) ? $data['state'] : '',
            'cAddress_zip' => isset($data['zip']) ? $data['zip'] : '',
            'cAddress_country'=> isset($data['country']) ? $data['country'] : '',
            'defaultpayment'=> isset($data['defaultpayment']) ? $data['defaultpayment'] : '',
            'stripePlanId'=> $planId
        ];
        //If the payment method already exists then retrieve it
        $paymentMethod = PaymentMethod::where('store_id',$data['store_id'])->first();
        $paymentMethodId = isset($paymentMethod->id) ? $paymentMethod->id : null;
        //If there is already a subscription exists for the store_id then retrieve it
        $oldSubscription = Subscription::where('store_id',$data['store_id'])->latest()->first();

        //Start: Upgrade or DownGrade Plans
        if (!is_null($paymentMethod) && !is_null($oldSubscription) && $planId != null){
            $oldPaymentMethod = PaymentMethod::where('store_id',$data['store_id'])->first();
            $last4 = decrypt($oldPaymentMethod->last4);
            //Update: the customer card if the defaultpayment is false OR the last4 digits of the current card does not match with the new given card
            if ((isset($data['defaultpayment']) && $data['defaultpayment'] == false) && substr($data['cNumber'], -4) != $last4){
                $customerId = $oldSubscription->stripe_id;

                $updateCustomerCardRes = $this->updateCustomerCard($customerId, $data);
                if ($updateCustomerCardRes['error'] == true){
                    return response()->json($updateCustomerCardRes);
                }
                $this->savePaymentMethodInDB($updateCustomerCardRes['data'],$data['store_id']);
            }

            if ($oldSubscription->status == 2){ //If the previous subscription is expired
                $updateSubResponse = $this->createnewSubscriptionPlan($oldSubscription->stripe_id, $planId);
            }else{ //If the previous subscription is active
                $updateSubResponse = $this->updateSubscriptionPlan($oldSubscription->subscription_id, $planId);
            }

            if ($updateSubResponse['error'] == true){
                return response()->json($updateSubResponse);
            }
            $this->updateSubscriptionInDB($updateSubResponse['data'],$oldSubscription);
            //Getting Current Plan Detail
            $updateSubResponse['data'] = $this->subscriptionDetailFromDB($data['store_id']);
            return response()->json($updateSubResponse,200);
        }
        //END: Upgrade or DownGrade Plans
        //If planId (null) means, it is trial.
        if ($planId != null){
            $customerResponse = $this->createCustomerOnStripe($data);
        }
        // if stripe customer is not created successfully then return the error
        if (isset($customerResponse['error']) && $customerResponse['error'] == true){
            return response()->json($customerResponse);
        }
        //If the stripe customer is created and subscription is done
        $customerId = isset($customerResponse['data']->id) ? $customerResponse['data']->id : null;
        $subscriptions = isset($customerResponse['data']->subscriptions) ? $customerResponse['data']->subscriptions : null;
        //If the stripe customer is created and plan is subscribed successfully then it means the PAID plan is subscribed.
        //else, otherwise we consider it to be a trial
        if (!is_null($customerId) && !is_null($subscriptions)){
            $paymentMethodId = $this->savePaymentMethodInDB($customerResponse['data'],$data['store_id']);
        }else{
            //Else part will be executed in case of trial and we need to update the subscription table for a trial
            $subscription = new Subscription();
            $subscription->store_id = $data['store_id'];
            $subscription->plan_id = self::$plansData['plan_id'];
            $subscription->name = isset($data['card_name']) ? $data['card_name'] : '';
            $subscription->status = 1; //Active Status
            $subscription->trial_ends_at = Carbon::now()->addDays(30);
            $subscription->ends_at = Carbon::now()->addDays(30);
            $subscription->save();
        }
        //If the plan if subcribed successfully, then it must be a PAID Stripe plan
        //Else, it is trial and $subscriptionId will be null.
        if (!is_null($subscriptions)){
            $subscriptionId = $this->saveSubscriptionInDB($customerResponse['data'],$subscriptions,$paymentMethodId,$data['store_id'], $oldSubscription = null);
        }else{
            $subscriptionId = isset($subscription->id) ? $subscription->id : null;
        }

        //Updating: carrier counts that will be allowed in case of trial of PAID plan
        $this->updateCarrierCountsinDB($subscriptionId,$data['store_id']);
        $subscriptionDetail = $this->subscriptionDetailFromDB($data['store_id']);

        return response()->json([
            'error' => false,
            'data' => $subscriptionDetail,
            'message' => 'The plan subscribed successfully.'
        ], 200);
    }

    public function changePaymentMethod(Request $request){
        $storeId = $request['store_id'];
        $paymentMethod = PaymentMethod::where('store_id',$storeId)->first();
        $customerId = decrypt($paymentMethod->stripe_id);
        $data = [
            'store_id' => isset($request['store_id']) ? $request['store_id'] : '',
            'cNumber' => isset($request['card_number']) ? $request['card_number'] : '',
            'cExpiryMonth' => isset($request['exp_month']) ? $request['exp_month'] : '',
            'cExpiryYear' => isset($request['exp_year']) ? $request['exp_year'] : '',
            'cCvc' => isset($request['cvc']) ? $request['cvc'] : '',
            'cName' => isset($request['card_name']) ? $request['card_name'] : '',
            'email' => isset($request['email']) ? $request['email'] : '',
            'cAddress_line1' => isset($request['address']) ? $request['address'] : '',
            'cAddress_city' => isset($request['city']) ? $request['city'] : '',
            'cAddress_state' => isset($request['state']) ? $request['state'] : '',
            'cAddress_zip' => isset($request['zip']) ? $request['zip'] : '',
            'cAddress_country'=> isset($request['country']) ? $request['country'] : ''
        ];
        $updateCustomerCardRes = $this->updateCustomerCard($customerId, $data);
        if ($updateCustomerCardRes['error'] == true){
            return response()->json($updateCustomerCardRes);
        }
        $this->savePaymentMethodInDB($updateCustomerCardRes['data'],$data['store_id']);
        return response()->json($updateCustomerCardRes);
    }

    //*************************************
    // This function is used to update the customer card
    //*************************************
    public function updateCustomerCard($customerId, $data) {

        $cNumber            = isset($data['cNumber']) ? $data['cNumber'] : '';
        $cExpiryMonth       = isset($data['cExpiryMonth']) ? $data['cExpiryMonth'] : '';
        $cExpiryYear        = isset($data['cExpiryYear']) ? $data['cExpiryYear'] : '';
        $cCvc               = isset($data['cCvc']) ? $data['cCvc'] : '';
        $cName              = isset($data['cName']) ? stripslashes($data['cName']) : '';
        $cAddress_line1     = isset($data['cAddress_line1']) ? stripslashes($data['cAddress_line1']) : '';
        $cAddress_line2     = isset($data['cAddress_line2']) ? stripslashes($data['cAddress_line2']) : '';
        $cAddress_city       = isset($data['cAddress_city']) ? $data['cAddress_city'] : '';
        $cAddress_zip       = isset($data['cAddress_zip']) ? $data['cAddress_zip'] : '';
        $cAddress_state     = isset($data['cAddress_state']) ? $data['cAddress_state'] : '';
        $cAddress_country   = isset($data['cAddress_country']) ? $data['cAddress_country'] : '';
        $token = '';
        $cardArray = array(
            "number" => $cNumber,
            "exp_month" => $cExpiryMonth,
            "exp_year" => $cExpiryYear,
            "cvc" => $cCvc,
            "name" => $cName,
            "address_line1" => $cAddress_line1,
            "address_city" => $cAddress_city,
            "address_zip" => $cAddress_zip,
            "address_state" => $cAddress_state,
            "address_country" => $cAddress_country,
        );
        if($cAddress_line2 != ''){
            $cardArray['address_line2'] = $cAddress_line2;
        }
        try {
            $customer = \Stripe\Customer::retrieve($customerId);

            $token = \Stripe\Token::create(array(
                "card" => $cardArray
            ));

        } catch (\Exception $e) {
            $responce = ['error' => true,
                'data' => [],
                'message' => $e->getMessage(),
            ];
        }
        if($token != ''){
            try {
                $customer->source = $token->id;
                $customer->save();
                $responce = ['error' => false,
                    'data' => $customer,
                    'message' => '',
                ];
            } catch (\Exception $e) {
                $responce = ['error' => true,
                    'data' => [],
                    'message' => $e->getMessage(),
                ];
            }
        }
        return $responce;
    }

    //*************************************
    // This function is used to get the details of Plan to be subscribe
    //*************************************
    public static function getPlansDetails($plan = 1)
    {
        $getDefaultFreePlan = Plan::find($plan);
        self::$plansData['plan_id'] = $getDefaultFreePlan->id;
        self::$plansData['stripe_plan_id'] = $getDefaultFreePlan->stripe_plan_id;
        self::$plansData['carrier_count'] = $getDefaultFreePlan->carrier_count;
        self::$plansData['cost'] = $getDefaultFreePlan->price;
        self::$plansData['name'] = $getDefaultFreePlan->name;
        self::$plansData['type'] = $getDefaultFreePlan->type;
    }

    //*************************************
    // This function will create customer on stripe and add card against the customer
    //*************************************
    public function createCustomerOnStripe($data) {

        $email = isset($data['email']) ? $data['email'] : '';
        $stripeDescription = isset($data['description']) ? $data['description'] : '';
        $stripePlanId = isset($data['stripePlanId']) ? $data['stripePlanId'] : '';
        $cNumber = isset($data['cNumber']) ? $data['cNumber'] : '';
        $cExpiryMonth = isset($data['cExpiryMonth']) ? $data['cExpiryMonth'] : '';
        $cExpiryYear = isset($data['cExpiryYear']) ? $data['cExpiryYear'] : '';
        $cCvc = isset($data['cCvc']) ? $data['cCvc'] : '';
        $cName = isset($data['cName']) ? stripslashes($data['cName']) : '';
        $cAddress_line1 = isset($data['cAddress_line1']) ? stripslashes($data['cAddress_line1']) : '';
        $cAddress_line2 = isset($data['cAddress_line2']) ? stripslashes($data['cAddress_line2']) : '';
        $cAddress_city = isset($data['cAddress_city']) ? $data['cAddress_city'] : '';
        $cAddress_zip = isset($data['cAddress_zip']) ? $data['cAddress_zip'] : '';
        $cAddress_state = isset($data['cAddress_state']) ? $data['cAddress_state'] : '';
        $cAddress_country = isset($data['cAddress_country']) ? $data['cAddress_country'] : '';
        $metadata = isset($data['metadata']) ? $data['metadata'] : '';

        $cardArray = array(
            "number" => $cNumber,
            "exp_month" => (int)$cExpiryMonth,
            "exp_year" => (int)$cExpiryYear,
            "cvc" => (int)$cCvc,
            "name" => $cName,
            "address_line1" => $cAddress_line1,
            "address_city" => $cAddress_city,
            "address_zip" => $cAddress_zip,
            "address_state" => $cAddress_state,
            "address_country" => $cAddress_country,
        );
        if($cAddress_line2 != ''){
            $cardArray['address_line2'] = $cAddress_line2;
        }
        try {
            $token = \Stripe\Token::create(array(
                "card" => $cardArray
            ));

            $responce = \Stripe\Customer::create(array(
                "name" => $cName,
                "email" => $email,
                "plan" => $stripePlanId,
                "description" => $stripeDescription,
                "metadata" => $metadata,
                "source" => $token
            ));

            $responce = [
                'error' => false,
                'data' => $responce,
                'message'  => ''
            ];
        } catch (\Exception $e) {
            error_log('Create Card Error: '.$e->getMessage());
            error_log('Card Details: '.json_encode($cardArray));
            $responce = [
                'error'  => true,
                'data' => [],
                'message'  => $e->getMessage()
            ];
        }
        return $responce;
    }

    public function reActivateSubscriptionPlan(Request $request) {

        //If there is already a subscription exists for the store_id then retrieve it
        $oldSubscription = Subscription::where('store_id',$request['store_id'])->latest()->first();
        $subId = $oldSubscription->subscription_id;
        $planId = $oldSubscription->plan_id;
        try {
            $subscription = \Stripe\Subscription::retrieve($subId);
            $subscription->plan = $planId;
            $subscriptionRes = $subscription->save();
            sleep(2);
            Subscription::where('subscription_id',$subId)->update([
                'status' => 1
            ]);
            $responce = [
                'error'  => false,
                'data' => $subscriptionRes,
                'message'  => ''
            ];
        } catch (\Exception $e) {
            $responce = [
                'error'  => false,
                'data' => [],
                'message'  => $e->getMessage()
            ];
        }
        return response()->json($responce);
    }

    public function cencelStripeSubscription($subscriptionId){
        try {

            /*$subscription = \Stripe\Subscription::retrieve($subscriptionId);
            $responce = $subscription->cancel();*/

            $responce = \Stripe\Subscription::update(
                $subscriptionId, [
                    'cancel_at_period_end' => true,
                ]
            );
            //array('at_period_end' => true)
            $ends_at = gmdate("M-d-Y", $responce->cancel_at);

            $responce = [
                'error' => false,
                'message' => 'You subscription will be cancelled automatically at the end of the period on '.$ends_at.'.',
                'data' => $responce,
            ];

            /*$oldSubscription->update([
                'status' => 2
            ]);*/
            return $responce;
        } catch (Exception $e) {
            $responce = [
                'error'  => true,
                'data' => [],
                'message'  => $e->getMessage()
            ];
        }
        return $responce;
    }
    //*************************************
    // This function will cancel the active subscription
    //*************************************
    public function cancelSubscriptionPlan(Request $request) {
        $storeId = $request['store_id'];
        $dbSub = Subscription::where('store_id',$storeId)->latest()->first();
        $res = $this->cencelStripeSubscription($dbSub->subscription_id);
        if (isset($res['error']) && $res['error'] == false){
            //Because of simaltaneous execution of stripe and DB
            sleep(2);
            Subscription::where('id',$dbSub->id)->update([
                'status' => 2
            ]);
        }
        return response()->json($res);
    }

    public function subscriptionDetailFromDB($storeId){
        $data = DB::table('subscriptions as s')
            ->leftJoin('carriers_counts as cc','cc.store_id','=','s.store_id')
            ->leftJoin('plans as pl','pl.id','=','s.plan_id')
            ->leftJoin('payment_methods as pm','pm.store_id','=','s.store_id')
            ->select('s.id as subscription_id','s.store_id','s.status','s.ends_at','s.plan_id','s.created_at','cc.carrier_counts as total_installed_carriers','s.amount_charged','pl.name','pm.last4','pm.is_default as is_default_payment_method')
            ->where('s.store_id',$storeId)->latest()->first();
        try {
            if (isset($data->last4)){
                $data->last4 = decrypt($data->last4);
            }
        }catch (\Exception $exception){
            error_log('Card Decrypt'. $exception->getMessage());
        }
        return $data;
    }

    //*************************************
    // This function is used to get the subscription details for the frontend
    //*************************************
    public function getSubscriptionDetail(Request $request){

        $storeId = $request['store_id'];
        $subscriptionDetail = $this->subscriptionDetailFromDB($storeId);

        if (empty($subscriptionDetail)){
            return response()->json(['error' => false,
                'data' => ['status' => 0,'plan_id'=> 0],
                'message' => 'No active subscription is available.',
            ], 200);
        }

        $subscriptionDetail = (array)$subscriptionDetail;
        if ($subscriptionDetail['plan_id'] == self::$trial && Carbon::now() > Carbon::parse($subscriptionDetail['ends_at'])){
            //If Trial is expired then update expired (2) status to DB
            Subscription::where('id',$subscriptionDetail['subscription_id'])->update([
                'status' => 2
            ]);
            $subscriptionDetail['status'] = 2; //Trial is expired
        }
        $plan = Plan::find($subscriptionDetail['plan_id']);

        $subscriptionDetail['total_installed_carriers'] = $plan->carrier_count - $subscriptionDetail['total_installed_carriers'];
        $subscriptionDetail['total_installable_carriers'] = $plan->carrier_count;
        return response()->json(['error' => false,
            'data' => $subscriptionDetail,
            'message' => '',
        ], 200);
    }
    //*************************************
    // This function will increment the installed carrier count
    //*************************************
    public function incrementCarrierCount(Request $request){
        $number = 1;
        $storeId = $request['store_id'];
        $carrier = $request['carrier'];
        if (in_array($carrier,self::$_parcelAndLtlCarries)){
            $number = 2;
        }
        $carrierCount = CarrierCount::where('store_id',$storeId)->first();
        $plan = Plan::find($carrierCount->plan_id);
        if ($carrierCount->carrier_counts > 0){
            $carrierCount->decrement('carrier_counts',$number);
            return response()->json(['error' => false,
                'data' => ['total_carriers_installed' => $plan->carrier_count-$carrierCount->carrier_counts],
                'message' => 'Carrier is installed successfully.',
            ], 200);
        }else{
            return response()->json(['error' => true,
                'data' => ['total_carriers_installed' => $plan->carrier_count-$carrierCount->carrier_counts],
                'message' => 'You have reached upto the subscription limit.',
            ]);
        }

    }

    //*************************************
    // This function will decrement the installed carrier count
    //*************************************
    public function decrementCarrierCount(Request $request){
        $number = 1;
        $storeId = $request['store_id'];
        $carrier = $request['carrier'];
        if (in_array($carrier,self::$_parcelAndLtlCarries)){
            $number = 2;
        }
        $carrierCount = CarrierCount::where('store_id',$storeId)->first();
        $plan = Plan::find($carrierCount->plan_id);
        if ($carrierCount->carrier_counts < $plan->carrier_count){
            $carrierCount->increment('carrier_counts',$number);
            return response()->json(['error' => false,
                'data' => ['total_carriers_installed' => $plan->carrier_count-$carrierCount->carrier_counts],
                'message' => 'Carrier is uninstalled successfully.',
            ], 200);
        }else{
            return response()->json(['error' => true,
                'data' => ['total_carriers_installed' => $plan->carrier_count-$carrierCount->carrier_counts],
                'message' => 'You are allowed to install '.$plan->carrier_count.' carriers.',
            ]);
        }
    }

    //*************************************
    // This function is used to subscribe the plan
    // It is no more in use yet.
    //*************************************
    public function createSubscriptionPlan($customerId, $planId) {
        try {
            $responce = \Stripe\Subscription::create(array(
                'customer' => $customerId,
                'plan' => $planId
            ));
            $responce = [
                'status' => true,
                'response' => $responce,
            ];
        } catch (\Exception $e) {
            $responce = [
                'status'  => false,
                'message'  => $e->getMessage()
            ];
        }
        return $responce;
    }

    public function invoicePaymentFailed(){
        $input = @file_get_contents("php://input");
        $paymentDetail = json_decode($input);
        error_log('PaymentFailed: '.$input);
        $lineData = $paymentDetail->data->object->lines->data[0];
      //  $productPlan = $lineData->plan->name;
      //  $customerId = $paymentDetail->data->object->customer;

       /* $emailData = array(
            'receiverEmail'     => $userEmail,
            'receiverName'      => $fName.' '.$lNname,
            'fname'             => $fName.' '.$lNname,
            'product_name'      => $product_name,
            'product_subtitle'  => $product_subtitle,
            'domain_name'       => $domain_name,
            'pakg_type'         => $package_type,
            'action'            => 'IPF'       // Invoice Payment Failed
        );*/

        $params = array(
            'period_end'=> $paymentDetail->data->object->period_end,
            'updated_date'=> $paymentDetail->data->object->webhooks_delivered_at,
            'subscriptionId'=> $paymentDetail->data->object->subscription
        );
        $subscriptionId = $params['subscriptionId'];

        //If there is already a subscription exists for the store_id then retrieve it
        $oldSubscription = Subscription::where('subscription_id',$subscriptionId)->latest()->first();

        if (!is_null($oldSubscription)){
            //status 3, means subscription expired from the stripe due to payment failed.
            $oldSubscription->update([
                'status' => 3
            ]);
        }else{
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Subscription not found to be update.',
            ]);
        }
        $data = [];
        $email = 'zeeshantanveer199@gmail.com';
       // $this->notifyEmail($emailData);
    //    Mail::to($email)->send(new PaymentFailedByWebHookEmail($data));
        return response()->json(['error' => false,
            'data' => [],
            'message' => 'Subscription Failed.',
        ],200);
    }

    public function invoicePaymentSucceeded(){
        $input = @file_get_contents("php://input");
        $paymentDetail = json_decode($input);

        $subscipId = $paymentDetail->data->object->subscription;

        $subscriptionPlanObj = $paymentDetail->data->object->lines->data[0];
        //If there is already a subscription exists for the store_id then retrieve it
        $oldSubscription = Subscription::where('subscription_id',$subscipId)->latest()->first();
        if (!is_null($oldSubscription)){
            //status 3, means subscription expired from the stripe due to payment failed.
            $oldSubscription->update([
                'status' => 1,
                'ends_at' => gmdate("Y-m-d\TH:i:s\Z", $subscriptionPlanObj->period->end)
            ]);
        }else{
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Subscription not found to be update.',
            ]);
        }

        return response()->json(['error' => false,
            'data' => [],
            'message' => 'Subscription Succeeded.',
        ],200);
    }



}
