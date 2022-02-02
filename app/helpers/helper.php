<?php

namespace App\Helpers;


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Stripe;

class Helper
{
    public static function jsonValidator($data = NULL)
    {
        json_decode($data);
        return (json_last_error() === JSON_ERROR_NONE);
    }

    public static function checkIsTestStore($storeHash)
    {
        return DB::table('test_stores')->where('store_hash', $storeHash)->exists();
    }

    public static function setStripeAPiKey($testStore)
    {
        if ($testStore) {
            Stripe::setApiKey(config('app.stripe_sandbox_secret'));
        } else {
            Stripe::setApiKey(config('app.stripe_secret'));
        }
    }

    public static function getUuid()
    {
        return Str::orderedUuid()->toString();
    }

    
    public static function sendJsonResponse($error, $message, $data = [])
    {
        $response = [
            'error' => $error,
            'message' => $message,
        ];
        if (!blank($data)) {
            $response['data'] = $data;
        }
        return response()->json($response
            , 200);
    }

}
