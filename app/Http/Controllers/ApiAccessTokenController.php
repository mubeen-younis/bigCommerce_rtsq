<?php

namespace App\Http\Controllers;

use App\Models\ApiAccessTokens;
use Illuminate\Http\Request;
use App\Helpers\Helpers;
use Illuminate\Support\Facades\DB;

class ApiAccessTokenController extends Controller
{
    /**
     * Create Api Access Token
     *
     * @return \Illuminate\Http\Response
     */

    public function create(Request $request)
    {
        if(isset($request->store_id) && $request->store_id){
            $accessToken = Helpers::getUuid();
            $ApiAccessTokens = ApiAccessTokens::firstOrCreate(['store_id' => $request->store_id]);
            $ApiAccessTokens->access_token = $accessToken;
            $ApiAccessTokens->save();

            return Helpers::sendJsonResponse(false, "Token created successfully.", $ApiAccessTokens);
        }
        
        return Helpers::sendJsonResponse(true, "Something went wrong.");
    }

    public function show(Request $request)
    {
        if(isset($request->store_id) && $request->store_id){
            $ApiAccessTokens = optional(ApiAccessTokens::where('store_id', $request->store_id)->first())->access_token ?? null;
            return Helpers::sendJsonResponse(false, "Get access token", $ApiAccessTokens);
        }
        
        return Helpers::sendJsonResponse(true, "Something went wrong.");
    }

    
}
