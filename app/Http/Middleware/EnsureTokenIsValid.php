<?php

namespace App\Http\Middleware;

use App\Helpers\Helpers;
use App\Models\Store;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;

class EnsureTokenIsValid
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
        if (!empty($request->header('authorization'))) {
            $token = explode(' ', $request->header('authorization'));
            if (empty($token[1])) {
                return response()->json(['error' => true,
                    'data' => [],
                    'message' => 'Missing Token Value'
                ], 401);
            }
            $store = Store::whereToken($token[1])->first();
            if (isset($store->id)) {
                $request['store_id'] = $store->id;
                $request['store_name'] = $store->name;
                $request['store_hash'] = $store->hash;
                $isTestStore = Helpers::checkIsTestStore($store->hash);
                $request['is_test_store'] = $isTestStore;
                // Setting Stripe Api Key For store
                Helpers::setStripeAPiKey($isTestStore);
                Log::info('Stripe APi Key : ' . Stripe::getApiKey().'Store Hash '.$request['store_hash']);
                return $next($request);
            }
            return response()->json(['error' => true,
                'data' => [
                    'token' => $token[1],
                    'store' => $store
                ],
                'message' => 'Token Mismatch'
            ], 401);
        }
        return response()->json(['error' => true,
            'data' => [],
            'message' => 'Token Not Found'
        ], 404);


    }
}
