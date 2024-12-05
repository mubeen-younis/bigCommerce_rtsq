<?php

namespace App\Http\Middleware;

use App\Helpers\Helpers;
use App\Models\Store;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use App\Models\ApiAccessTokens;

class EnsureProductApiTokenIsValid
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {  
        if ($request->hasHeader('X-BigCommerce-Url') && !blank($request->header('X-BigCommerce-Url'))) {
            if (!empty($request->header('authorization'))) {
                $token = explode(' ', $request->header('authorization'));

                if (empty($token[1])) {
                    return response()->json(['status' => 401,'message' => 'Unauthenticated.'], 401);
                }

                $ApiTokenDetails = ApiAccessTokens::whereAccessToken($token[1])->first();
                if (isset($ApiTokenDetails->store_id) && isset($ApiTokenDetails->access_token)) {
                    $store = Store::where('id', $ApiTokenDetails->store_id)->first();

                    if (isset($store->id) && $request->header('X-BigCommerce-Url') == $store->url) {
                        $request['store_id'] = $store->id;
                        $request['store_name'] = $store->name;
                        $request['store_hash'] = $store->hash;
                        $isTestStore = Helpers::checkIsTestStore($store->hash);
                        $request['is_test_store'] = $isTestStore;

                        return $next($request);
                    }
                    return response()->json(['status' => 404,'message' => 'No Store Found.'], 404);
                }
                return response()->json(['status' => 401,'message' => 'Unauthenticated.'], 401);
            }
            return response()->json(['status' => 404,'message' => 'Token Not Found'], 404);
        } 
        return response()->json(['status' => 404,'message' => 'Please make sure X-BigCommerce-Url is in header'], 404);

    }
}
