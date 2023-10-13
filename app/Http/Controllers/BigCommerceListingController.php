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
                $res = $Subscription->cencelStripeSubscription($dbSub->subscription_id);
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
                $res = $Subscription->reActivateSubscriptionPlan($subId, $stripePlanId);
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
}
