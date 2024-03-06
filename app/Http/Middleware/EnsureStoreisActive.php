<?php

namespace App\Http\Middleware;

use App\Helpers\Helpers;
use App\Models\Store;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;

class EnsureStoreisActive
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
        $postData = file_get_contents("php://input");
        $postData = json_decode($postData, true);   
        $storeHash = explode('/', $postData['producer']);
        $storeHash = $storeHash[1];
        $storeStatus = optional(Store::where('hash', $storeHash)->first())->app_status ?? false;
        if ($storeStatus) {
            return $next($request);
        }
        return response()->json(false,404);
    }
}
