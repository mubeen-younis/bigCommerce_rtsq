<?php

namespace App\Http\Middleware;

use App\Models\Store;
use Closure;
use Illuminate\Http\Request;

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
        if (isset($request->token) && !empty($request->token)) {
            if (Store::where('token', $request->token)->exists()) {
                $storeID = Store::where('token', $request->token)->first();
                $request['store_id'] = $storeID->id;
                return $next($request);
            }
            return response()->json(['error' => true,
                'data' => [],
                'message' => 'Token Mismatch'
            ], 401);
        }
        return response()->json(['error' => true,
            'data' => [],
            'message' => 'Token Not Found'
        ], 404);


    }
}
