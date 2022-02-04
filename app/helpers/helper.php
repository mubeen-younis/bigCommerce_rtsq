<?php

namespace App\Helpers;


use Illuminate\Support\Facades\DB;
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

    public static function floatValue($number = 0)
    {
        if ($number == 0) {
            return $number;
        }
        $number = rtrim($number, '0');                // 50,00 --> 50,
        $number = rtrim($number, '.'); // 50,   --> 50
        return $number;

    }

}
