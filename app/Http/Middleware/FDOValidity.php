<?php

namespace App\Http\Middleware;

use App\Helpers\Helpers;
use App\Models\Store;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;

class FDOValidity
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\JsonResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->hasHeader('company-id') && !blank($request->header('company-id')) &&
            $request->hasHeader('store-hash') && !blank($request->header('store-hash'))) {
            $storeHash = $request->header('store-hash');
            $store = Store::where('hash', $storeHash)->first();
            if (isset($store->id)) {
                $request['store_id'] = $store->id;
                $request['store_name'] = $store->name;
                $request['store_hash'] = $store->hash;
                return $next($request);
            }
            return Helpers::sendJsonResponseFdo(true, 'No store found against this hash');
        }
        return Helpers::sendJsonResponseFdo(true, 'Please make sure company is in header');
    }
}
