<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Plans;
use App\Models\Subscription\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Helpers\Helpers;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Subscription\SubscriptionController;
use Stripe\Charge;
use Stripe\Stripe;

class BigCommerceListingController extends Controller
{
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
        $uuid = $request->uuid ?? null;
        if (blank($uuid)) {
            return Helpers::sendJsonResponse(false, 'Invalid Request');
        }
        dd(33, $request->all());


        return Helpers::sendJsonResponse(true, 'BigCommerce subscription details updated successfully');
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
                $plan = Plans::find($planId);
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

        
        $subscriptionDetail = $Subscription->subscriptionDetailFromDB($dbSub->store_id);
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
}
