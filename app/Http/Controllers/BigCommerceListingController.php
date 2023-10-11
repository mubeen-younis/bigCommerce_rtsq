<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Subscription\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Helpers\Helpers;

class BigCommerceListingController extends Controller
{
    /**
     * List BigCOmmerce Stores
     * @param Request $request
     * @return JsonResponse
     */
    public function listCustomers(Request $request)
    {
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
}
