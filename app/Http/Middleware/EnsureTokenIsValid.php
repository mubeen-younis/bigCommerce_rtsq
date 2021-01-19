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
        if (!empty($request->header('authorization'))) {
            $token = explode(' ', $request->header('authorization'));
            if (empty($token[1])) {
                return response()->json(['error' => true,
                    'data' => [],
                    'message' => 'Missing Token Value'
                ], 401);
            }
            if (Store::where('token', $token[1])->exists()) {
                $storeID = Store::where('token', $token[1])->first();
                $request['store_id'] = $storeID->id;
                $request['store_name'] = $storeID->hash;
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
